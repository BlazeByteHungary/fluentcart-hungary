<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * GLS PULL tracking (GLS-021 `GetParcelStatuses`, read-only by ParcelNumber; 04_GLS_CONTRACT.md §3.5/§4)
 * plus the pure Appendix G status-code → FluentCart shipping-status mapping (04_GLS §6.1, pp.44-46).
 *
 * Exact status table (§6.1). The provider status has NO prescribed FC mapping (provider status → external
 * meaning only). We map to `unshipped|shipped|delivered|unshippable` and apply MONOTONIC transitions only
 * (invariant §1.3): a duplicate/late poll can never regress a proved status.
 *
 * `syncOrder()` applies the mapped status through the verified FluentCart shipping-status API
 * `\FluentCart\Api\Resource\OrderResource::updateStatuses(...)` (api/Resource/OrderResource.php:2187;
 * shipping branch :2217-2305), which fires `fluent_cart/shipping_status_changed[_to_*]`.
 */
final class GlsTracking
{
    /** §6.1: codes that mean the parcel reached the consignee. */
    private const DELIVERED_CODES = [5, 54, 55, 58, 59, 92, 93, 97];

    /**
     * §6.1: terminal failure/return branches — returned to sender, refused, disposed, damaged, not
     * locatable, excluded from GTC, parcel damaged at a locker.
     */
    private const UNSHIPPABLE_CODES = [17, 23, 28, 29, 30, 31, 34, 35, 39, 40, 42, 43, 44, 403];

    /** §6.1: acceptance/transport/delivery-attempt codes that prove the parcel is in the GLS network. */
    private const SHIPPED_CODES = [
        1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 19, 20, 21, 22, 24, 25, 26, 27,
        32, 33, 36, 37, 38, 41, 46, 47, 53, 56, 57, 80, 83, 84, 85, 86, 87, 88, 89, 90, 91, 99,
        401, 402, 404, 420,
        // 60..76 customs-clearance states are handled numerically below.
    ];

    private ?GlsClient $client;

    private ShipmentRepository $shipments;

    public function __construct(?GlsClient $client = null, ?ShipmentRepository $shipments = null)
    {
        $this->client = $client;
        $this->shipments = $shipments ?? new ShipmentRepository();
    }

    /**
     * Fetch tracking for the order's confirmed GLS shipments and apply the mapped shipping status.
     *
     * @return array{status:string,order_id:int,checked:int,applied:list<string>,reason?:string}
     */
    public function syncOrder(int $orderId): array
    {
        $parcelNumbers = [];
        foreach ($this->shipments->listForOrder($orderId) as $row) {
            if ((string) ($row->carrier ?? '') !== GlsModule::CARRIER) {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $tracking = trim((string) ($row->tracking_number ?? ''));
            if ($tracking !== '') {
                $parcelNumbers[$tracking] = true;
            }
        }

        if ($parcelNumbers === []) {
            return ['status' => 'skipped', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'no_tracking'];
        }

        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['status' => 'error', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'missing_credentials'];
        }

        $applied = [];
        $checked = 0;
        foreach (array_keys($parcelNumbers) as $parcelNumber) {
            $response = $client->getParcelStatuses($parcelNumber, false, (string) GlsModule::setting('language', 'HU'));
            if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
                continue;
            }
            $checked++;
            $statusList = self::statusListFromResponse($response->json());
            $status = self::mapStatusList($statusList);
            if ($status === '') {
                continue;
            }
            if ($this->applyShippingStatus($orderId, $status)) {
                $applied[] = $status;
            }
        }

        return ['status' => 'ok', 'order_id' => $orderId, 'checked' => $checked, 'applied' => array_values(array_unique($applied))];
    }

    /**
     * Extract `ParcelStatusList[]` from a GLS-021 response (also tolerates `ParcelList[].ParcelStatusList`
     * from GLS-022, §3.5/§4).
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    public static function statusListFromResponse(array $data): array
    {
        if (isset($data['ParcelStatusList']) && is_array($data['ParcelStatusList'])) {
            return self::asList($data['ParcelStatusList']);
        }
        foreach (['ParcelList', 'ParcelStatuses'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            foreach (self::asList($data[$key]) as $entry) {
                if (isset($entry['ParcelStatusList']) && is_array($entry['ParcelStatusList'])) {
                    return self::asList($entry['ParcelStatusList']);
                }
            }
        }
        return [];
    }

    /**
     * Pure status-list → FC shipping-status mapping. Strongest proved event wins (delivered > unshippable >
     * shipped); an empty/unrecognised list returns '' (never guesses).
     *
     * @param list<array<string,mixed>> $statusList
     */
    public static function mapStatusList(array $statusList): string
    {
        $best = '';
        $rank = ['shipped' => 1, 'unshippable' => 2, 'delivered' => 3];
        foreach ($statusList as $entry) {
            $status = self::mapStatusCode(
                (int) ($entry['StatusCode'] ?? $entry['statusCode'] ?? 0),
                (string) ($entry['StatusDescription'] ?? $entry['statusDescription'] ?? '')
            );
            if ($status !== '' && ($rank[$status] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $status;
            }
        }
        return $best;
    }

    /**
     * Pure single-code mapping from the §6.1 provider status table. Unknown codes return '' — the mapper
     * never invents a state. A human description is used only as a fallback when the code is unknown.
     */
    public static function mapStatusCode(int $code, string $description = ''): string
    {
        if (in_array($code, self::DELIVERED_CODES, true)) {
            return 'delivered';
        }
        if (in_array($code, self::UNSHIPPABLE_CODES, true)) {
            return 'unshippable';
        }
        if (in_array($code, self::SHIPPED_CODES, true)) {
            return 'shipped';
        }
        // §6.1: 60..76 are customs-clearance states (missing invoice / delayed / released / inspection / …).
        if ($code >= 60 && $code <= 76) {
            return 'shipped';
        }
        // §6.1: 51/52 are "data entered, not yet handed over" — not proven in transit, never guess.
        if ($code === 51 || $code === 52) {
            return '';
        }

        $text = strtolower($description);
        if ($text !== '') {
            if (str_contains($text, 'delivered') || str_contains($text, 'kézbesített') || str_contains($text, 'kezbesitett')) {
                return 'delivered';
            }
            if (str_contains($text, 'returned to sender') || str_contains($text, 'visszaküld') || str_contains($text, 'visszakuld')) {
                return 'unshippable';
            }
            if (str_contains($text, 'handed to gls') || str_contains($text, 'felvétel') || str_contains($text, 'felvetel') || str_contains($text, 'transit') || str_contains($text, 'depot')) {
                return 'shipped';
            }
        }
        return '';
    }

    /**
     * Apply a mapped status through FluentCart's verified shipping-status API. Monotonic: never regresses
     * a proved status (invariant §1.3). FC symbols are referenced only inside this method (constraints §3).
     */
    private function applyShippingStatus(int $orderId, string $target): bool
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

    /** Rank unshipped < shipped < delivered; unshippable is a terminal branch never overwritten by delivered. */
    public static function isForwardTransition(string $current, string $target): bool
    {
        if ($current === $target) {
            return false;
        }
        if ($current === 'delivered') {
            return false;
        }
        if ($target === 'unshippable') {
            return true; // terminal exception state
        }
        if ($current === 'unshippable') {
            return false;
        }
        $rank = ['unshipped' => 0, 'shipped' => 1, 'delivered' => 2];
        return ($rank[$target] ?? 0) > ($rank[$current] ?? 0);
    }

    /** @param array<mixed> $value @return list<array<string,mixed>> */
    private static function asList(array $value): array
    {
        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    private function client(): ?GlsClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $this->client = new GlsClient(GlsModule::mode());
        } catch (\Throwable $e) {
            return null;
        }
        return $this->client;
    }
}
