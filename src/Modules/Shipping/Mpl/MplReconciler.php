<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * Resolves an `UNCERTAIN` MPL label create by *reading back* `GET /v2/mplapi/shipments` (MPL-007) — never
 * by re-POSTing `/shipments` (04_MPL_CONTRACT.md §7 verdict: auto create-retry not safe). The lookup uses the
 * creation window (created_at ±1 day) and matches `customData1 === ledger idempotency_key` (customData1
 * survives list close, §7 reference L766-771) or the echoed `webshopId`.
 *
 *   found                         => SUCCESS (tracking ids) + mark the shipment row confirmed
 *   successful query, no match    => REJECTED('MPL_NOT_FOUND')  (the response proves absence)
 *   query error / timeout / 5xx   => UNCERTAIN (stays in the manual reconciliation queue)
 *
 * Registered as a callable `fn(object $ledgerRow): ProviderCallResult` in ReconcilerRegistry.
 */
final class MplReconciler
{
    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        if ((string) ($ledgerRow->kind ?? '') === 'shipment_cancel') {
            return (new MplShipmentService())->reconcileCancel($ledgerRow);
        }

        $idempotencyKey = trim((string) ($ledgerRow->idempotency_key ?? ''));
        $businessReference = trim((string) ($ledgerRow->business_reference ?? ''));
        if ($idempotencyKey === '' && $businessReference === '') {
            return ProviderCallResult::uncertain('NO_QUERY_REFERENCE');
        }

        $client = new MplClient(MplModule::mode());
        if (!$client->hasCredentials()) {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }

        [$fromDate, $toDate] = self::window((string) ($ledgerRow->created_at ?? ''));
        $request = [
            'operation' => 'query_shipments',
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'customData1' => $idempotencyKey,
        ];

        $response = $client->queryShipments(['fromDate' => $fromDate, 'toDate' => $toDate]);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request, ['error' => (string) $response->error]);
        }
        if (!$response->isSuccess()) {
            return ProviderCallResult::uncertain('QUERY_HTTP_' . $response->status, $request);
        }

        $data = $response->json();
        if ($data === []) {
            return ProviderCallResult::uncertain('QUERY_EMPTY_RESPONSE', $request, ['status' => $response->status]);
        }

        $results = self::asList($data);
        $match = self::match($results, $idempotencyKey, $businessReference);
        if ($match === null) {
            // A successful query that does not contain our reference proves the shipment was not created.
            return ProviderCallResult::rejected('MPL_NOT_FOUND', 'No shipment matched customData1/webshopId in the creation window.', $request);
        }

        $tracking = trim((string) ($match['trackingNumber'] ?? ''));
        $packageTracking = self::stringList($match['packageTrackingNumbers'] ?? []);
        if ($tracking === '' && $packageTracking !== []) {
            $tracking = $packageTracking[0];
        }
        if ($tracking === '') {
            return ProviderCallResult::uncertain('QUERY_MATCH_WITHOUT_TRACKING', $request);
        }

        $webshopId = trim((string) ($match['webshopId'] ?? $businessReference));
        $customData1 = trim((string) ($match['customData1'] ?? $idempotencyKey));

        $fields = [
            'external_id' => $tracking,
            'external_ref2' => $webshopId,
            'external_ref3' => $customData1,
            'barcode' => $packageTracking !== [] ? $packageTracking[0] : $tracking,
            'tracking_number' => $tracking,
            'carrier_reference' => $webshopId,
            'parcels' => $packageTracking,
        ];

        $this->confirmShipment($ledgerRow, $fields, (string) ($match['label'] ?? ''));

        return ProviderCallResult::success($fields, $request, ['webshopId' => $webshopId, 'trackingNumber' => $tracking]);
    }

    /**
     * Mark the mirror row confirmed without ever regressing a terminal state (05_DATA_SCHEMA §7.4). The
     * reconciler only runs on non-terminal rows; a mirror in UNCERTAIN is first moved to
     * AWAITING_RECONCILIATION because the mirror state machine forbids UNCERTAIN -> EXTERNAL_CONFIRMED.
     *
     * @param array<string,mixed> $fields
     */
    private function confirmShipment(object $ledgerRow, array $fields, string $labelBase64): void
    {
        if ((string) ($ledgerRow->owner_type ?? '') !== 'shipment') {
            return;
        }
        $shipmentId = (int) ($ledgerRow->owner_id ?? 0);
        if ($shipmentId <= 0) {
            return;
        }

        $repo = new ShipmentRepository();
        $row = $repo->getById($shipmentId);
        if ($row === null) {
            return;
        }

        $state = (string) $row->state;
        if (ExternalOperation::isTerminal($state)) {
            return;
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            MplShipmentService::ensureShipmentInProgress($repo, $shipmentId);
            $state = ExternalOperation::IN_PROGRESS;
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            $repo->markAwaitingReconciliation($shipmentId);
            $state = ExternalOperation::AWAITING_RECONCILIATION;
        }
        if (!in_array($state, [ExternalOperation::IN_PROGRESS, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            return;
        }

        $extra = [
            'external_shipment_id' => (string) ($fields['tracking_number'] ?? ''),
            'external_ref2' => (string) ($fields['external_ref2'] ?? ''),
            'external_ref3' => (string) ($fields['external_ref3'] ?? ''),
            'barcode' => (string) ($fields['barcode'] ?? ''),
            'tracking_number' => (string) ($fields['tracking_number'] ?? ''),
            'carrier_reference' => (string) ($fields['carrier_reference'] ?? ''),
            'parcels' => (string) (function_exists('wp_json_encode') ? wp_json_encode((array) ($fields['parcels'] ?? [])) : json_encode((array) ($fields['parcels'] ?? []))),
            'package_count' => max(1, count((array) ($fields['parcels'] ?? []))),
        ];

        if ($labelBase64 !== '') {
            $binary = base64_decode(trim($labelBase64), true);
            if (is_string($binary) && $binary !== '') {
                try {
                    $stored = (new MplLabelStore())->store('mpl-label-' . (string) ($fields['tracking_number'] ?? ''), $binary, 'pdf');
                    $extra['label_ref'] = $stored['ref'];
                    $extra['label_sha256'] = $stored['sha256'];
                } catch (\Throwable $e) {
                    // best-effort
                }
            }
        }

        $repo->markConfirmed($shipmentId, $extra);
        if (!empty($extra['label_ref'])) {
            $repo->setLabelState($shipmentId, 'PREPARED');
        }
    }

    /** @return array{0:string,1:string} [fromDate, toDate] Y-m-d around the ledger creation instant (±1 day). */
    public static function window(string $createdAt): array
    {
        $timestamp = $createdAt !== '' ? strtotime($createdAt . ' UTC') : false;
        if ($timestamp === false) {
            $timestamp = time();
        }
        return [
            gmdate('Y-m-d', $timestamp - 86400),
            gmdate('Y-m-d', $timestamp + 86400),
        ];
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    private static function asList(array $data): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['shipments', 'results', 'items'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return [$data];
    }

    /**
     * @param list<array<string,mixed>> $results
     * @return array<string,mixed>|null
     */
    private static function match(array $results, string $idempotencyKey, string $businessReference): ?array
    {
        $wanted = array_values(array_filter([$idempotencyKey, $businessReference], static fn(string $v): bool => $v !== ''));
        foreach ($results as $result) {
            foreach (['customData1', 'webshopId', 'orderId'] as $key) {
                $value = trim((string) ($result[$key] ?? ''));
                if ($value !== '' && in_array($value, $wanted, true)) {
                    return $result;
                }
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_scalar($entry) && (string) $entry !== '') {
                $out[] = (string) $entry;
            }
        }
        return $out;
    }
}
