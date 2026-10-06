<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;

/**
 * Resolves an `UNCERTAIN` GLS label create by *reading back* the provider — never by re-POSTing
 * `PrintLabels` (04_GLS_CONTRACT.md §7 verdict: "Auto create-retry is risky … Safe recovery = list …
 * and match on ClientReference").
 *
 * Read-back paths:
 *  1. If the mirror already holds a `ParcelNumber` (e.g. a previous partial/ambiguous outcome), GLS-021
 *     `GetParcelStatuses` by ParcelNumber proves the parcel exists (errors 9/10 = not ours → REJECTED).
 *  2. Otherwise GLS-020 `GetParcelList` over the creation window, matching `ClientReference`
 *     (`PrintDataInfo.ClientReference`, §7) or the ledger idempotency key → proves/denies creation.
 *
 *   found                         => SUCCESS + mark the shipment row confirmed
 *   successful query, no match    => REJECTED('GLS_NOT_FOUND')  (the response proves absence)
 *   query error / timeout / 5xx   => UNCERTAIN (stays in the manual reconciliation queue)
 *
 * Registered as a callable `fn(object $ledgerRow): ProviderCallResult` in ReconcilerRegistry under 'gls'.
 */
final class GlsReconciler
{
    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        $idempotencyKey = trim((string) ($ledgerRow->idempotency_key ?? ''));
        $businessReference = trim((string) ($ledgerRow->business_reference ?? ''));
        if ($idempotencyKey === '' && $businessReference === '') {
            return ProviderCallResult::uncertain('NO_QUERY_REFERENCE');
        }

        $client = new GlsClient(GlsModule::mode());
        if (!$client->hasCredentials()) {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }

        $shipment = $this->shipmentRow($ledgerRow);
        $parcelNumber = $shipment !== null
            ? trim((string) (($shipment->tracking_number ?? '') !== '' ? $shipment->tracking_number : ($shipment->external_shipment_id ?? '')))
            : '';
        $clientReference = $businessReference !== ''
            ? $businessReference
            : trim((string) ($shipment->carrier_reference ?? ''));

        // Path 1 — direct ParcelNumber status read-back (GLS-021).
        if ($parcelNumber !== '' && ctype_digit($parcelNumber)) {
            $statusResult = $this->lookupByParcelNumber($client, $parcelNumber, $clientReference);
            if ($statusResult['definitive']) {
                if ($statusResult['result']->isSuccess()) {
                    $this->confirmShipment($ledgerRow, $statusResult['result']->fields, '');
                }
                return $statusResult['result'];
            }
        }

        // Path 2 — list by creation window and match ClientReference / idempotency key (GLS-020).
        [$fromDate, $toDate] = self::window((string) ($ledgerRow->created_at ?? ''));
        $request = [
            'operation' => 'GetParcelList',
            'PrintDateFrom' => $fromDate,
            'PrintDateTo' => $toDate,
            'ClientReference' => $clientReference,
        ];

        $response = $client->getParcelList([
            'PrintDateFrom' => $fromDate,
            'PrintDateTo' => $toDate,
            'PickupDateFrom' => $fromDate,
            'PickupDateTo' => $toDate,
        ]);
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

        $parcels = GlsMapper::collectParcelInfos($data);
        $match = self::matchPrintData($parcels, [$clientReference, $idempotencyKey]);
        if ($match === null) {
            // A successful query that does not contain our reference proves the shipment was not created.
            return ProviderCallResult::rejected('GLS_NOT_FOUND', 'No parcel matched ClientReference in the creation window.', $request);
        }

        $parcel = trim((string) ($match['parcel_number'] ?? ''));
        if ($parcel === '') {
            return ProviderCallResult::uncertain('QUERY_MATCH_WITHOUT_PARCEL_NUMBER', $request);
        }

        $fields = [
            'external_id' => $parcel,
            'external_ref2' => $clientReference,
            'external_ref3' => $idempotencyKey,
            'barcode' => (string) ($match['barcode'] ?? $parcel),
            'tracking_number' => $parcel,
            'carrier_reference' => $clientReference,
            'parcels' => [$parcel],
        ];

        $this->confirmShipment($ledgerRow, $fields, (string) (GlsMapper::firstLabelBase64($data) ?? ''));

        return ProviderCallResult::success($fields, $request, ['parcelNumber' => $parcel, 'clientReference' => $clientReference]);
    }

    /**
     * GLS-021 read-back by ParcelNumber. Errors 9 (not exists) / 10 (not assigned) prove the parcel is not
     * ours; a status list proves it exists.
     *
     * @return array{definitive:bool,result:ProviderCallResult}
     */
    private function lookupByParcelNumber(GlsClient $client, string $parcelNumber, string $clientReference): array
    {
        $request = ['operation' => 'GetParcelStatuses', 'ParcelNumber' => $parcelNumber];
        $response = $client->getParcelStatuses($parcelNumber);
        if ($response->error !== null || $response->status === 0) {
            return ['definitive' => true, 'result' => ProviderCallResult::uncertain('STATUS_QUERY_TIMEOUT', $request, ['error' => (string) $response->error])];
        }
        if (!$response->isSuccess()) {
            return ['definitive' => true, 'result' => ProviderCallResult::uncertain('STATUS_QUERY_HTTP_' . $response->status, $request)];
        }

        $data = $response->json();
        $statusList = GlsTracking::statusListFromResponse($data);
        $errors = GlsMapper::collectErrors($data);
        foreach ($errors as $error) {
            if (in_array((string) $error['code'], ['9', '10'], true)) {
                return ['definitive' => true, 'result' => ProviderCallResult::rejected('GLS_NOT_FOUND', (string) $error['message'], $request)];
            }
        }
        if ($statusList === []) {
            // Inconclusive status read-back: fall through to the list path instead of guessing.
            return ['definitive' => false, 'result' => ProviderCallResult::uncertain('STATUS_QUERY_INCONCLUSIVE', $request)];
        }

        $ref = $clientReference !== '' ? $clientReference : trim((string) ($data['ClientReference'] ?? ''));
        $fields = [
            'external_id' => $parcelNumber,
            'external_ref2' => $ref,
            'barcode' => $parcelNumber,
            'tracking_number' => $parcelNumber,
            'carrier_reference' => $ref,
            'parcels' => [$parcelNumber],
        ];
        // A ledger row is not in scope here (definitive result returned) — mark via owner in the caller.
        return ['definitive' => true, 'result' => ProviderCallResult::success($fields, $request, ['parcelNumber' => $parcelNumber])];
    }

    /**
     * Mark the mirror row confirmed without ever regressing a terminal state (05_DATA_SCHEMA §7.4). The
     * mirror in UNCERTAIN is first moved to AWAITING_RECONCILIATION (the mirror machine forbids
     * UNCERTAIN -> EXTERNAL_CONFIRMED directly).
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
            GlsShipmentService::ensureShipmentInProgress($repo, $shipmentId);
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
            $binary = GlsMapper::extractLabelBinary($labelBase64);
            if ($binary !== null) {
                try {
                    $stored = (new CarrierLabelStore(GlsShipmentService::CARRIER))->store('gls-label-' . (string) ($fields['tracking_number'] ?? ''), $binary, 'pdf');
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

    private function shipmentRow(object $ledgerRow): ?object
    {
        if ((string) ($ledgerRow->owner_type ?? '') !== 'shipment') {
            return null;
        }
        $shipmentId = (int) ($ledgerRow->owner_id ?? 0);
        if ($shipmentId <= 0) {
            return null;
        }
        return (new ShipmentRepository())->getById($shipmentId);
    }

    /**
     * @param list<array<string,mixed>> $parcels
     * @param list<string>             $references
     * @return array<string,mixed>|null
     */
    private static function matchPrintData(array $parcels, array $references): ?array
    {
        $wanted = array_values(array_filter(array_map('strval', $references), static fn(string $v): bool => $v !== ''));
        if ($wanted !== []) {
            foreach ($parcels as $parcel) {
                $value = trim((string) ($parcel['client_reference'] ?? ''));
                if ($value !== '' && in_array($value, $wanted, true)) {
                    return $parcel;
                }
            }
        }
        return null;
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
}
