<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * Packeta tracking (PKT-011 `packetStatus`, PKT-012 `packetTracking`) plus the pure status-code →
 * FluentCart `shipping_status` mapping (04_PACKETA_CONTRACT.md §6 "Status / event model").
 *
 * Documented status codes (04 §6):
 *   1 received data · 2 arrived · 3 prepared for departure · 4 departed · 5 ready for pickup ·
 *   6 handed to carrier · 7 delivered · 9 posted back · 10 returned · 11 cancelled · 12 collected ·
 *   14 customs · 15 reverse packet arrived · 16 delivery attempt · 17/18 rejected by recipient ·
 *   19 return from HD no branch nearby · 20 storage time expired · 21 packet cancelled but consigned ·
 *   22 return overlimit · 23 zbox delivery attempt · 24 zbox last delivery attempt ·
 *   25 carrier first delivery attempt · 26 packet under investigation · 27 investigation resolved ·
 *   28 favourite point redirect · 29 no favourite point available redirect · 30 no favourite point set
 *   redirect · 31 courier tracking code added · 32 on demand redirect · 999 unknown.
 *
 * Mapping rule (monotonic, never guesses):
 *   7 / 12                          => 'delivered'
 *   9/10/11/15/17/18/19/20/21/22    => 'unshippable' (terminal return/cancel branch)
 *   any other known code 1..32      => 'shipped' (accepted → in transit → ready at PP/Z-BOX)
 *   0 / 999 / unknown               => '' (never guess)
 *
 * Delivery-point events (status 5 "ready for pickup" for a Packeta PP or Z-BOX, and 23/24 Z-BOX
 * attempts) are included in the shipped branch; status 25 covers the external carrier last mile
 * (e.g. HU FoxPost BOX id=32970, which is a Packeta-side carrier status, 04 §6).
 *
 * `syncOrder()` applies the mapped status through FluentCart's verified shipping-status API
 * `\FluentCart\Api\Resource\OrderResource::updateStatuses(...)` (shipping branch), which fires the
 * typed `fluent_cart/shipping_status_changed[_to_*]` hooks. The FC symbol is referenced only inside the
 * method (constraints §3 / spike B1).
 */
final class PacketaTracking
{
    private ?PacketaClient $client;

    private ShipmentRepository $shipments;

    public function __construct(?PacketaClient $client = null, ?ShipmentRepository $shipments = null)
    {
        $this->client = $client;
        $this->shipments = $shipments ?? new ShipmentRepository();
    }

    /**
     * @return array{status:string,order_id:int,checked:int,applied:list<string>,reason?:string}
     */
    public function syncOrder(int $orderId): array
    {
        $packetIds = [];
        foreach ($this->shipments->listForOrder($orderId) as $row) {
            if ((string) ($row->carrier ?? '') !== PacketaModule::CARRIER) {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $packetId = trim((string) ($row->external_shipment_id ?? ''));
            if ($packetId === '') {
                $packetId = PacketaClient::canonicalPacketId((string) ($row->barcode ?? ''));
            }
            if ($packetId !== '') {
                $packetIds[$packetId] = true;
            }
        }

        if ($packetIds === []) {
            return ['status' => 'skipped', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'no_packet'];
        }

        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['status' => 'error', 'order_id' => $orderId, 'checked' => 0, 'applied' => [], 'reason' => 'missing_credentials'];
        }

        $applied = [];
        $checked = 0;
        foreach (array_keys($packetIds) as $packetId) {
            $status = $this->fetchShippingStatus($client, (string) $packetId);
            if ($status === '') {
                continue;
            }
            $checked++;
            if ($this->applyShippingStatus($orderId, $status)) {
                $applied[] = $status;
            }
        }

        return ['status' => 'ok', 'order_id' => $orderId, 'checked' => $checked, 'applied' => array_values(array_unique($applied))];
    }

    /**
     * Latest-known status for one packet: `packetStatus` first; when its code is unmapped, fall back to
     * the `packetTracking` history so an earlier delivered/returned record is not lost.
     */
    public function fetchShippingStatus(PacketaClient $client, string $packetId): string
    {
        $code = $this->latestStatusCode($client, $packetId);
        $mapped = self::mapStatusCode($code);
        if ($mapped !== '') {
            return $mapped;
        }

        $records = $this->trackingRecords($client, $packetId);
        return self::mapRecords($records);
    }

    /** PKT-011: parse `result.statusCode` as an int (0 when unknown). */
    private function latestStatusCode(PacketaClient $client, string $packetId): int
    {
        $response = $client->packetStatus($packetId);
        if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
            return 0;
        }
        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return 0;
        }
        $code = PacketaClient::firstString($decoded['result'], 'statusCode');
        return is_numeric($code) ? (int) $code : 0;
    }

    /**
     * PKT-012: decode the `record` list; each record carries `statusCode`/`codeText`.
     *
     * @return list<array{statusCode:int,codeText:string,statusText:string}>
     */
    private function trackingRecords(PacketaClient $client, string $packetId): array
    {
        $response = $client->packetTracking($packetId);
        if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
            return [];
        }
        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return [];
        }
        return self::normalizeRecords($decoded['result']);
    }

    /**
     * @param array<string,mixed> $result
     * @return list<array{statusCode:int,codeText:string,statusText:string}>
     */
    public static function normalizeRecords(array $result): array
    {
        $raw = $result['record'] ?? $result;
        $out = [];
        foreach (PacketaClient::asList($raw) as $record) {
            if (!is_array($record)) {
                continue;
            }
            $codeRaw = PacketaClient::firstString($record, 'statusCode');
            $out[] = [
                'statusCode' => is_numeric($codeRaw) ? (int) $codeRaw : 0,
                'codeText' => PacketaClient::firstString($record, 'codeText'),
                'statusText' => PacketaClient::firstString($record, 'statusText'),
            ];
        }
        return $out;
    }

    /** Highest-severity mapping across a record set: delivered > unshippable > shipped > '' (pure). */
    public static function mapRecords(array $records): string
    {
        $best = '';
        $rank = ['' => 0, 'shipped' => 1, 'unshippable' => 2, 'delivered' => 3];
        foreach ($records as $record) {
            $code = is_array($record) ? (int) ($record['statusCode'] ?? 0) : (int) $record;
            $mapped = self::mapStatusCode($code);
            if (($rank[$mapped] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $mapped;
            }
        }
        return $best;
    }

    /** Pure statusCode → FluentCart shipping_status map (04 §6). */
    public static function mapStatusCode(int $code): string
    {
        if (in_array($code, [7, 12], true)) {
            return 'delivered';
        }
        if (in_array($code, [9, 10, 11, 15, 17, 18, 19, 20, 21, 22], true)) {
            return 'unshippable';
        }
        if ($code >= 1 && $code <= 32) {
            return 'shipped';
        }
        return '';
    }

    /**
     * Apply a mapped status through FluentCart's verified shipping-status API. Monotonic: never regresses
     * a proved status (invariant §1.3).
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

    /** Rank unshipped < shipped < delivered; unshippable is a terminal branch never overwritten. */
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

    private function client(): ?PacketaClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $this->client = new PacketaClient();
        } catch (\Throwable $e) {
            return null;
        }
        return $this->client;
    }
}
