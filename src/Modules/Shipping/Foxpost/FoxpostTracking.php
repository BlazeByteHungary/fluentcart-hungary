<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * Foxpost PULL tracking (04_FOXPOST_CONTRACT.md §4 op 13/14/15, §6) and the pure status →
 * FluentCart `shipping_status` mapping.
 *
 * Push contract: the OpenAPI exposes **poll only**; a "tracking callback" exists **by request** and is not
 * specified (§6.3, ACCOUNT_DEPENDENT / MISSING_DOC). Therefore this module implements **PULL only** and
 * registers no webhook/callback endpoint (FX-U3).
 *
 * Mapping (guide §5 meanings; spec enum §6) with terminal precedence delivered > unshippable > shipped:
 *   RECEIVE, HDRECEIVE                         => delivered
 *   RETURN, RETURNED, OVERTIMEOUT, OVERTIMED,
 *   HDRETURN, BACKTOSENDER, INWAREHOUSE,
 *   DESTROYED, RETURNDELIVERED, EXTRETURN,
 *   RETURNCOURIER                              => unshippable
 *   any other known status except CREATE       => shipped
 *   CREATE / unknown                           => '' (never guess)
 *
 * `syncOrder()` applies the mapped status through the verified FluentCart shipping-status API
 * `\FluentCart\Api\Resource\OrderResource::updateStatuses(...)` (same contract as MplTracking), which fires
 * the typed `fluent_cart/shipping_status_changed[_to_*]` hooks. Transitions are monotonic (never regress).
 */
final class FoxpostTracking
{
    public const CARRIER = FoxpostModule::CARRIER;

    /** Spec enum (§6) restricted to delivered states. */
    private const DELIVERED = ['RECEIVE', 'HDRECEIVE'];

    /** Definitive return/undeliverable states (guide §5). */
    private const UNSHIPPABLE = [
        'RETURN', 'RETURNED', 'OVERTIMEOUT', 'OVERTIMED', 'HDRETURN', 'BACKTOSENDER',
        'INWAREHOUSE', 'DESTROYED', 'RETURNDELIVERED', 'EXTRETURN', 'RETURNCOURIER',
    ];

    /**
     * Every documented status (spec enum + guide superset) that proves transport progress. `CREATE` (only
     * created) and `C2BIN` (a C2B sender dropped a parcel, not our outbound delivery) are deliberately
     * absent so they map to '' instead of a guessed status.
     */
    private const IN_TRANSIT = [
        'OPERIN', 'OPEROUT', 'SORTIN', 'SORTOUT', 'REDIRECT', 'MPSIN', 'C2CIN', 'HDSENT', 'HDDEPO',
        'HDINTRANSIT', 'WBXREDIRECT', 'HDHUBIN', 'HDHUBOUT', 'HDCOURIER', 'HDUNDELIVERABLE',
        'PREPAREDFORPD', 'COLLECTSENT', 'COLLECTED', 'BACKLOGINFULL', 'BACKLOGINFAIL', 'MISSORT',
        'EMPTYSLOT', 'RESENT', 'PREREDIRECT', 'SLOTCHANGE', 'EXTPICKUP', 'EXTOPERIN',
    ];

    private ?FoxpostClient $client;

    private ShipmentRepository $shipments;

    public function __construct(?FoxpostClient $client = null, ?ShipmentRepository $shipments = null)
    {
        $this->client = $client;
        $this->shipments = $shipments ?? new ShipmentRepository();
    }

    /**
     * PULL `GET /api/tracking/{barcode}` (clFox id or uniqueBarcode, §4 op 13) for every confirmed Foxpost
     * shipment of the order and apply the mapped status.
     *
     * @return array{status:string,order_id:int,checked:int,applied:list<string>,reason?:string}
     */
    public function syncOrder(int $orderId): array
    {
        $refs = [];
        foreach ($this->shipments->listForOrder($orderId) as $row) {
            if ((string) ($row->carrier ?? '') !== self::CARRIER) {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $ref = trim((string) ($row->tracking_number ?? ''));
            if ($ref === '') {
                $ref = trim((string) ($row->barcode ?? ''));
            }
            if ($ref === '') {
                $ref = trim((string) ($row->external_ref2 ?? ''));
            }
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }

        if ($refs === []) {
            return ['status' => 'skipped', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'no_tracking'];
        }

        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['status' => 'error', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'missing_credentials'];
        }

        $applied = [];
        $checked = 0;
        foreach (array_keys($refs) as $ref) {
            $response = $client->getTracking($ref);
            if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
                continue;
            }
            $checked++;
            $status = $this->mapTrackingToShippingStatus($response->json());
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
     * Pure mapping over a `Tracking` / `Statuses` / `TrackDTO[]` payload. Returns the strongest proved FC
     * status, '' when nothing is proved.
     *
     * @param array<mixed> $data
     */
    public function mapTrackingToShippingStatus(array $data): string
    {
        $statuses = [];
        $this->collectStatuses($data, $statuses);
        return self::mapStatuses($statuses);
    }

    /**
     * Pure status-list → FC shipping status (terminal precedence: delivered, then unshippable, then shipped).
     *
     * @param list<string> $statuses
     */
    public static function mapStatuses(array $statuses): string
    {
        $normalized = [];
        foreach ($statuses as $status) {
            $status = strtoupper(trim((string) $status));
            if ($status !== '') {
                $normalized[$status] = true;
            }
        }
        foreach (self::DELIVERED as $status) {
            if (isset($normalized[$status])) {
                return 'delivered';
            }
        }
        foreach (self::UNSHIPPABLE as $status) {
            if (isset($normalized[$status])) {
                return 'unshippable';
            }
        }
        foreach (array_keys($normalized) as $status) {
            if (in_array($status, self::IN_TRANSIT, true)) {
                return 'shipped';
            }
        }
        return '';
    }

    /**
     * Flatten any of the documented tracking payloads into a status list:
     *  - `Tracking{traces:[Trace{status}]}` (GET /tracking/{barcode});
     *  - `Statuses{barcode,statuses:[TrackDTO{status}]}` (GET /tracks, batch);
     *  - a bare list of either.
     *
     * @param array<mixed> $data
     * @param list<string> $out
     */
    private function collectStatuses(array $data, array &$out): void
    {
        if (isset($data['traces']) && is_array($data['traces'])) {
            foreach ($data['traces'] as $trace) {
                if (is_array($trace) && isset($trace['status'])) {
                    $out[] = (string) $trace['status'];
                }
            }
        }
        if (isset($data['statuses']) && is_array($data['statuses'])) {
            foreach ($data['statuses'] as $entry) {
                if (is_array($entry) && isset($entry['status'])) {
                    $out[] = (string) $entry['status'];
                }
            }
        }
        if (array_is_list($data)) {
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['status'])) {
                    $out[] = (string) $entry['status'];
                }
                if (isset($entry['traces']) && is_array($entry['traces'])) {
                    foreach ($entry['traces'] as $trace) {
                        if (is_array($trace) && isset($trace['status'])) {
                            $out[] = (string) $trace['status'];
                        }
                    }
                }
                if (isset($entry['statuses']) && is_array($entry['statuses'])) {
                    foreach ($entry['statuses'] as $inner) {
                        if (is_array($inner) && isset($inner['status'])) {
                            $out[] = (string) $inner['status'];
                        }
                    }
                }
            }
        }
    }

    /**
     * Apply a mapped status through FluentCart's verified shipping-status API. The FC symbol is only
     * referenced inside this method (constraints §3).
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

    private function client(): ?FoxpostClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $this->client = new FoxpostClient(FoxpostModule::mode());
        } catch (\Throwable $e) {
            return null;
        }
        return $this->client;
    }
}
