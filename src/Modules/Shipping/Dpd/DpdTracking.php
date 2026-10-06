<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * DPD event/scan status handling (04_DPD_CONTRACT.md §6.2, §6.3) with a monotonic FluentCart
 * `shipping_status` mapping.
 *
 * IMPORTANT DOCUMENTED LIMIT — there is **no tracking API endpoint** in the local DPD files (§3.5:
 * "No tracking API endpoint is documented"; DPD-UNK-06/DPD-U6, matrix DPD-903). The documented event
 * channel is the **STATUSDATA** semicolon CSV feed over SFTP. Also `shipment.status` is an integer with
 * **no documented enum** (§6.1; DPD-UNK-07/DPD-U7, DPD-905), so it is never mapped here.
 *
 * Therefore:
 *  - `syncOrder()` performs **no HTTP call** — it reports `tracking_endpoint_undocumented` (the unknown
 *    is surfaced in DpdModule::probe()). No invented tracking URL is ever built.
 *  - the documented STATUSDATA records are parsed/mapped by `parseStatusdataCsv()` / `mapRecord()`
 *    (pure, unit-testable) and applied by `applyRecords()` through FluentCart's verified shipping-status
 *    API, monotonically (never regresses a proved status; invariant §1.3).
 *
 * STATUSDATA fields (20, §6.2): PARCELNO, SCAN_CODE, DEPOT_CODE, DEPOTNAME, EVENT_DATE_TIME, ROUTE,
 * TOUR, PCODE, SERVICE, CONSIGNEE_COUNTRY_CODE, CONSIGNEE_ZIP, ADD_SERVICE_1..3, WEIGHT,
 * CUSTOMER_REFERENCE, POD_IMAGE_REF, RECEIVER_NAME, INFO_TEXT, LOCATION.
 *
 * Scan types (§6.2) → FC status:
 *   13 Delivered                                     => delivered
 *   23 Delivered by driver to DPD ParcelShop         => shipped (handed to the point, not the consignee)
 *   01..20 (consolidation/inbound/out/depot/…)       => shipped
 * Exception codes (ADD_SERVICE_1..3): 37* (3rd attempt → return) and 49* (sender-requested return)
 *                                                     => unshippable
 *   unknown scan / no event                          => '' (never guess)
 */
final class DpdTracking
{
    /** §6.2 scan types (code => meaning) — documentation only, used by probe/reporting. */
    public const SCAN_MEANINGS = [
        '01' => 'Consolidation',
        '02' => 'Inbound',
        '03' => 'Out for delivery',
        '04' => "Driver's return",
        '05' => 'Pick-up',
        '06' => 'System return',
        '07' => 'Outbound',
        '08' => 'Warehouse',
        '09' => 'Inbound exception',
        '10' => 'HUB scan',
        '12' => 'Customs clearance process',
        '13' => 'Delivered',
        '14' => 'Not delivered',
        '15' => "Driver's pick-up",
        '17' => 'Export/Import cleared',
        '18' => 'Additional information',
        '20' => 'Loading',
        '23' => 'Delivered by driver to DPD ParcelShop',
    ];

    /** 04_DPD §6.2 fields in file order. */
    public const FIELDS = [
        'PARCELNO', 'SCAN_CODE', 'DEPOT_CODE', 'DEPOTNAME', 'EVENT_DATE_TIME', 'ROUTE', 'TOUR', 'PCODE',
        'SERVICE', 'CONSIGNEE_COUNTRY_CODE', 'CONSIGNEE_ZIP', 'ADD_SERVICE_1', 'ADD_SERVICE_2', 'ADD_SERVICE_3',
        'WEIGHT', 'CUSTOMER_REFERENCE', 'POD_IMAGE_REF', 'RECEIVER_NAME', 'INFO_TEXT', 'LOCATION',
    ];

    public const DELIVERED_SCANS = ['13'];
    public const RETURN_EXCEPTION_PREFIXES = ['37', '49'];

    private ?DpdClient $client;

    private ShipmentRepository $shipments;

    public function __construct(?DpdClient $client = null, ?ShipmentRepository $shipments = null)
    {
        $this->client = $client;
        $this->shipments = $shipments ?? new ShipmentRepository();
    }

    /**
     * No tracking API is documented (DPD-U6). This performs no network call and returns a stable
     * unsupported result; callers ingest STATUSDATA via {@see applyRecords()}.
     *
     * @return array{status:string,order_id:int,checked:int,applied:list<string>,reason:string}
     */
    public function syncOrder(int $orderId): array
    {
        return [
            'status' => 'unsupported',
            'order_id' => $orderId,
            'checked' => 0,
            'applied' => [],
            'reason' => 'tracking_endpoint_undocumented',
        ];
    }

    /**
     * Map and apply a batch of STATUSDATA records to one order, monotonically.
     *
     * @param list<array<string,mixed>> $records
     * @return array{status:string,order_id:int,checked:int,applied:list<string>,mapped:string,reason:?string}
     */
    public function applyRecords(int $orderId, array $records): array
    {
        $mapped = $this->mapRecords($records);
        $applied = [];
        if ($mapped !== '' && $this->applyShippingStatus($orderId, $mapped)) {
            $applied[] = $mapped;
        }
        return [
            'status' => $mapped === '' ? 'no_transition' : 'ok',
            'order_id' => $orderId,
            'checked' => count($records),
            'applied' => $applied,
            'mapped' => $mapped,
            'reason' => $mapped === '' ? 'no_proven_event' : null,
        ];
    }

    /**
     * Aggregate the whole record set to one FC shipping status: delivered > unshippable > shipped > ''.
     *
     * @param list<array<string,mixed>> $records
     */
    public function mapRecords(array $records): string
    {
        $hasShipped = false;
        $hasUnshippable = false;
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $status = $this->mapRecord($record);
            if ($status === 'delivered') {
                return 'delivered';
            }
            if ($status === 'unshippable') {
                $hasUnshippable = true;
            } elseif ($status === 'shipped') {
                $hasShipped = true;
            }
        }
        if ($hasUnshippable) {
            return 'unshippable';
        }
        return $hasShipped ? 'shipped' : '';
    }

    /**
     * Pure single-record mapping (04_DPD §6.2). Returns '', 'shipped', 'delivered' or 'unshippable'.
     *
     * @param array<string,mixed> $record
     */
    public function mapRecord(array $record): string
    {
        $scan = $this->dig($record, ['SCAN_CODE', 'scan_code', 'SCANCODE', 'scanType', 'scan_type']);
        if (in_array($scan, self::DELIVERED_SCANS, true)) {
            return 'delivered';
        }

        foreach (['ADD_SERVICE_1', 'ADD_SERVICE_2', 'ADD_SERVICE_3', 'add_service_1', 'add_service_2', 'add_service_3'] as $key) {
            $exception = $this->dig($record, [$key]);
            if ($exception === '') {
                continue;
            }
            foreach (self::RETURN_EXCEPTION_PREFIXES as $prefix) {
                if (str_starts_with($exception, $prefix)) {
                    return 'unshippable';
                }
            }
        }

        if ($scan !== '' && isset(self::SCAN_MEANINGS[$scan])) {
            return 'shipped';
        }

        return '';
    }

    /**
     * Parse a STATUSDATA semicolon CSV body (§6.2: header + records, optional `.sem` semaphore handled by
     * the importer). Unknown/short lines are skipped; a header line is detected by matching PARCELNO.
     *
     * @return list<array<string,string>>
     */
    public static function parseStatusdataCsv(string $csv): array
    {
        $records = [];
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(';', $line);
            if (strtoupper(trim($parts[0] ?? '')) === 'PARCELNO') {
                continue; // header
            }
            if (count($parts) < 2) {
                continue;
            }
            $record = [];
            foreach (self::FIELDS as $index => $field) {
                $record[$field] = trim((string) ($parts[$index] ?? ''));
            }
            $records[] = $record;
        }
        return $records;
    }

    /**
     * Apply a mapped status through FluentCart's verified shipping-status API, monotonically. The FC symbol
     * is only referenced inside this method (constraints §3, spike B1).
     */
    public function applyShippingStatus(int $orderId, string $target): bool
    {
        $orderClass = 'FluentCart\\App\\Models\\Order';
        $resourceClass = 'FluentCart\\Api\\Resource\\OrderResource';
        if (!class_exists($orderClass) || !class_exists($resourceClass)) {
            return false;
        }

        $order = $orderClass::find($orderId);
        if (!is_object($order)) {
            return false;
        }

        $current = (string) ($order->shipping_status ?? 'unshipped');
        if (!self::isForwardTransition($current, $target)) {
            return false;
        }

        $resourceClass::updateStatuses([
            'order' => $order,
            'action' => 'change_shipping_status',
            'statuses' => ['shipping_status' => $target],
        ]);

        return true;
    }

    /**
     * Rank unshipped < shipped < delivered; unshippable is a terminal branch never overwritten by delivered.
     * Same contract as the W1 mapper (invariant §1.3, 05_ACCEPTANCE event-order row).
     */
    public static function isForwardTransition(string $current, string $target): bool
    {
        if ($current === $target) {
            return false;
        }
        if ($current === 'delivered') {
            return false;
        }
        if ($target === 'unshippable') {
            return true;
        }
        if ($current === 'unshippable') {
            return false;
        }
        $rank = ['unshipped' => 0, 'shipped' => 1, 'delivered' => 2];
        return ($rank[$target] ?? 0) > ($rank[$current] ?? 0);
    }

    /**
     * @param array<string,mixed> $record
     * @param list<string>        $keys
     */
    private function dig(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && is_scalar($record[$key])) {
                return trim((string) $record[$key]);
            }
        }
        return '';
    }
}
