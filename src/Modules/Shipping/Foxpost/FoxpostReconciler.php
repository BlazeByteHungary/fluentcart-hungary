<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * Resolves an `UNCERTAIN` Foxpost label create by *reading back* tracking — never by re-POSTing the create.
 *
 * Documented lookup basis (04_FOXPOST_CONTRACT.md §4 op 13, §7): `GET /api/tracking/{barcode}` accepts a
 * clFox id **or a `uniqueBarcode`**. For APM creates this service always persists a pre-generated
 * uniqueBarcode before dispatch, so a lost/timed-out create can be proved by querying tracking with that
 * uniqueBarcode. HD creates have **no** client unique key and no lookup-by-reference (§7) → they stay in
 * manual reconciliation (`NO_QUERY_REFERENCE_HD_MANUAL`).
 *
 *   tracking found           => SUCCESS + mark the shipment row confirmed
 *   definitive not-found      => REJECTED('FOXPOST_NOT_FOUND')
 *   query error / 4xx / 5xx   => UNCERTAIN (stays in the manual reconciliation queue)
 *
 * `supportsLookup()` gates registration in FoxpostModule so the module never registers a reconciler for a
 * provider with no documented lookup (the task's "otherwise do not register" rule).
 */
final class FoxpostReconciler
{
    /** Foxpost documents a barcode/uniqueBarcode tracking lookup, so a reconciler is justified. */
    public static function supportsLookup(): bool
    {
        return true;
    }

    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        if ((string) ($ledgerRow->kind ?? '') === 'shipment_cancel') {
            return (new FoxpostParcelService())->reconcileCancel($ledgerRow);
        }

        // APM: the ledger's business_reference is the uniqueBarcode. HD: empty -> manual.
        $reference = trim((string) ($ledgerRow->business_reference ?? ''));
        if ($reference === '') {
            return ProviderCallResult::uncertain('NO_QUERY_REFERENCE_HD_MANUAL');
        }

        $client = new FoxpostClient(FoxpostModule::mode());
        if (!$client->hasCredentials()) {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }

        $request = ['operation' => 'getTrackings', 'barcode' => $reference];
        $response = $client->getTracking($reference);

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request, ['error' => (string) $response->error]);
        }
        if ($response->status === 404) {
            return ProviderCallResult::rejected('FOXPOST_NOT_FOUND', 'Tracking lookup proved the parcel does not exist.', $request);
        }
        if (!$response->isSuccess()) {
            // 400 (ambiguous) / 401 / 5xx cannot prove absence: stay uncertain.
            return ProviderCallResult::uncertain('QUERY_HTTP_' . $response->status, $request);
        }

        $data = $response->json();
        $clFox = trim((string) ($data['clFox'] ?? ''));
        $traces = is_array($data['traces'] ?? null) ? $data['traces'] : [];
        if ($clFox === '' && $traces === []) {
            return ProviderCallResult::rejected('FOXPOST_NOT_FOUND', 'Tracking response carried no parcel identity.', $request);
        }

        $fields = [
            'external_id' => $clFox !== '' ? $clFox : $reference,
            'external_ref2' => $reference,
            'barcode' => $clFox !== '' ? $clFox : $reference,
            'tracking_number' => $clFox !== '' ? $clFox : $reference,
            'uniqueBarcode' => $reference,
            'parcels' => [['clFoxId' => $clFox, 'uniqueBarcode' => $reference]],
            'package_count' => 1,
        ];

        $this->confirmShipment($ledgerRow, $fields);

        return ProviderCallResult::success($fields, $request, ['clFox' => $clFox, 'reference' => $reference]);
    }

    /**
     * Mark the mirror row confirmed without regressing a terminal state (mirror state machine §7.4).
     *
     * @param array<string,mixed> $fields
     */
    private function confirmShipment(object $ledgerRow, array $fields): void
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
            FoxpostParcelService::ensureShipmentInProgress($repo, $shipmentId);
            $state = ExternalOperation::IN_PROGRESS;
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            $repo->markAwaitingReconciliation($shipmentId);
            $state = ExternalOperation::AWAITING_RECONCILIATION;
        }
        if (!in_array($state, [ExternalOperation::IN_PROGRESS, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            return;
        }

        $parcels = (array) ($fields['parcels'] ?? []);
        $repo->markConfirmed($shipmentId, [
            'external_shipment_id' => (string) ($fields['external_id'] ?? ''),
            'external_ref2' => (string) ($fields['external_ref2'] ?? ''),
            'barcode' => (string) ($fields['barcode'] ?? ''),
            'tracking_number' => (string) ($fields['tracking_number'] ?? ''),
            'carrier_reference' => '',
            'parcels' => (string) (function_exists('wp_json_encode') ? wp_json_encode($parcels) : json_encode($parcels)),
            'package_count' => max(1, count($parcels)),
        ]);
    }
}
