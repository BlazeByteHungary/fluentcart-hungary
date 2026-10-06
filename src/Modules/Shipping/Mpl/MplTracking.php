<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * MPL PULL-1 tracking (MPL-013, `POST /v2/nyomkovetes/registered`, 04_MPL_CONTRACT.md §4) plus the pure
 * C43/C10/C9 → FluentCart shipping-status mapping (04_MPL §6.1).
 *
 * Event model (04_MPL §6.1): three coupled fields — C43 category code, C10 `PackageStatus`, C9
 * `last_event_type`. Categories: 1 Felvétel (acceptance) · 2 Feldolgozás (processing) · 3 Szállítás
 * (transport) · 4 Kézbesítés (delivery) · 5 Kézbesített (delivered). C9 examples include "pre-advised",
 * "delivered", "returned to sender". Mapping rule:
 *   category 5 / C9 delivered              => 'delivered'
 *   C9 returned to sender                   => 'unshippable'
 *   accepted (category >= 1)                => 'shipped'
 *   anything else / unknown / pre-advised   => '' (never guess)
 *
 * `syncOrder()` applies the mapped status through the verified FluentCart shipping-status API
 * `\FluentCart\Api\Resource\OrderResource::updateStatuses(...)` (api/Resource/OrderResource.php:2187;
 * shipping branch :2217-2305), which fires the typed hooks `fluent_cart/shipping_status_changed_to_<status>`
 * and `fluent_cart/shipping_status_changed` (app/Events/Order/OrderStatusUpdated.php:85-88).
 */
final class MplTracking
{
    private ?MplClient $client;

    private ShipmentRepository $shipments;

    public function __construct(?MplClient $client = null, ?ShipmentRepository $shipments = null)
    {
        $this->client = $client;
        $this->shipments = $shipments ?? new ShipmentRepository();
    }

    /**
     * Fetch tracking for the order's confirmed MPL shipments and apply the mapped status (PULL-1, one id
     * per call; don't resubmit the same id within 4 h — 04_MPL §4/§7).
     *
     * @return array{status:string,order_id:int,checked:int,applied:list<string>,reason?:string}
     */
    public function syncOrder(int $orderId): array
    {
        $trackingNumbers = [];
        foreach ($this->shipments->listForOrder($orderId) as $row) {
            if ((string) ($row->carrier ?? '') !== 'mpl') {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $tracking = trim((string) ($row->tracking_number ?? ''));
            if ($tracking !== '') {
                $trackingNumbers[$tracking] = true;
            }
        }

        if ($trackingNumbers === []) {
            return ['status' => 'skipped', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'no_tracking'];
        }

        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['status' => 'error', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'missing_credentials'];
        }

        $applied = [];
        $checked = 0;
        foreach (array_keys($trackingNumbers) as $tracking) {
            $response = $client->pullTrackingRegistered([$tracking]);
            if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
                continue;
            }
            $checked++;
            $data = $response->json();
            $trackAndTrace = $data['trackAndTrace'] ?? $data;
            if (!is_array($trackAndTrace)) {
                continue;
            }
            $status = $this->mapEventsToShippingStatus($trackAndTrace);
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
     * Pure C43/C9 → fct_orders.shipping_status mapping (04_MPL §6.1). Returns '' when the event set does
     * not prove a transition (never guesses). Input may be a list of trace entries, each optionally
     * carrying an `events` list; field names are matched liberally because the exact JSON keys are not
     * transcribed (04_MPL §6.1 / MPL-UNK partial) — see the UNKNOWNS report.
     *
     * @param array<mixed> $trackAndTrace
     */
    public function mapEventsToShippingStatus(array $trackAndTrace): string
    {
        $codes = [];
        $texts = [];
        foreach ($trackAndTrace as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $events = $entry['events'] ?? null;
            if (is_array($events) && $events !== []) {
                foreach ($events as $event) {
                    if (is_array($event)) {
                        $this->collectEvent($event, $codes, $texts);
                    }
                }
            }
            $this->collectEvent($entry, $codes, $texts);
        }

        if (in_array(5, $codes, true) || self::textContains($texts, ['delivered', 'kézbesített', 'kezbesitett'])) {
            return 'delivered';
        }
        if (self::textContains($texts, ['returned to sender', 'return to sender', 'visszaküld', 'vissza a feladó', 'vissza a feladonak'])) {
            return 'unshippable';
        }
        foreach ($codes as $code) {
            if ($code >= 1) {
                return 'shipped';
            }
        }
        if (self::textContains($texts, ['accepted', 'felvétel', 'felvetel', 'felfogad', 'processing', 'feldolgoz', 'transport', 'szállít', 'szallit'])) {
            return 'shipped';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $event
     * @param list<int>           $codes
     * @param list<string>        $texts
     */
    private function collectEvent(array $event, array &$codes, array &$texts): void
    {
        $category = $event['c43'] ?? $event['C43'] ?? $event['category'] ?? $event['categoryCode'] ?? $event['category_code'] ?? null;
        if ($category !== null && is_numeric($category)) {
            $codes[] = (int) $category;
        }

        foreach (['c9', 'C9', 'last_event_type', 'lastEventType', 'eventType', 'event_type', 'status', 'c10', 'C10', 'packageStatus', 'package_status'] as $key) {
            if (isset($event[$key]) && is_scalar($event[$key])) {
                $texts[] = strtolower(trim((string) $event[$key]));
            }
        }

        // C43 category 5 is "delivered" by definition even without a text.
        if ($category !== null && (int) $category === 5) {
            $codes[] = 5;
        }
    }

    /** @param list<string> $texts @param list<string> $needles */
    private static function textContains(array $texts, array $needles): bool
    {
        foreach ($texts as $text) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($text, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Apply a mapped status through FluentCart's verified shipping-status API. Monotonic: never regresses a
     * proved status (invariant §1.3). The FC symbol is only referenced inside this method (constraints §3,
     * spike B1).
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

    private function client(): ?MplClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $this->client = new MplClient(MplModule::mode());
        } catch (\Throwable $e) {
            return null;
        }
        return $this->client;
    }
}
