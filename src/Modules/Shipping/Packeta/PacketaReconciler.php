<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;

/**
 * Resolves an `UNCERTAIN` Packeta packet create by *reading back* `packetInfo(packetId)` (PKT-010) —
 * never by re-POSTing `createPacket` (04_PACKETA_CONTRACT.md §7 verdict: auto create-retry is NOT safe;
 * no idempotency key, `number` uniqueness not enforced, no documented lookup-by-number).
 *
 * Outcome:
 *   packet id known + packetInfo ok            => SUCCESS (id/barcode/number) + confirm the shipment row
 *   packet id known + packet not found fault   => REJECTED('PACKETA_PACKET_NOT_FOUND')
 *   query error / timeout / 5xx / no packet id => UNCERTAIN (operator reconciliation queue)
 *
 * Lookup is by packet id; the returned `number` is used to CONFIRM the packet matches our order reference
 * (`number` is returned by packetInfo but is not a documented query key — 04 §5.1/§7). The only claimed
 * auto-recovery is the documented one; without a packet id the row stays AWAITING_RECONCILIATION.
 *
 * Registered as a callable `fn(object $ledgerRow): ProviderCallResult` in ReconcilerRegistry under
 * provider 'packeta'.
 */
final class PacketaReconciler
{
    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        $kind = (string) ($ledgerRow->kind ?? '');
        if ($kind === 'shipment_cancel') {
            return (new PacketaPacketService())->reconcileCancel($ledgerRow);
        }
        if ($kind === PacketaClaimService::KIND) {
            // Claims have no documented lookup: never rejects, never re-dispatches (PKT-007).
            return (new PacketaClaimService())->reconcile($ledgerRow);
        }

        $packetId = $this->resolvePacketId($ledgerRow);
        if ($packetId === '') {
            // 04 §7: there is no documented "find packet by order number"; without a packet id the outcome
            // cannot be proved automatically and must stay for a human (contract §7 verdict NOT_SAFE).
            return ProviderCallResult::uncertain('PACKETA_NO_LOOKUP_BY_NUMBER');
        }

        $expectedNumber = trim((string) ($ledgerRow->business_reference ?? ''));

        $client = new PacketaClient();
        if (!$client->hasCredentials()) {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }

        $request = ['operation' => 'packetInfo', 'packetId' => $packetId];
        $response = $client->packetInfo($packetId);

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('PACKETA_HTTP_' . $response->status, $request);
        }
        if (!$response->isSuccess()) {
            // 400-class fault: for a definitive PacketIdFault the packet is not in our account.
            $decoded = PacketaClient::decode($response->body);
            $fault = (string) ($decoded['fault'] ?? '');
            if (stripos($fault, 'PacketId') !== false || stripos($fault, 'not found') !== false) {
                return ProviderCallResult::rejected('PACKETA_PACKET_NOT_FOUND', $fault, $request);
            }
            return ProviderCallResult::uncertain('QUERY_HTTP_' . $response->status, $request, ['fault' => $fault]);
        }

        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return ProviderCallResult::uncertain('PACKETA_FAULT', $request, ['fault' => (string) ($decoded['fault'] ?? '')]);
        }

        $result = $decoded['result'];
        $number = PacketaClient::firstString($result, 'number');
        $branchId = PacketaClient::firstString($result, 'branchId');
        $consignPassword = PacketaClient::firstString($result, 'consignPassword');

        $fields = [
            'external_id' => $packetId,
            'external_ref2' => $number !== '' ? $number : $expectedNumber,
            'barcode' => $packetId,
            'tracking_number' => $packetId,
            'carrier_reference' => $branchId,
            'packeta_branch_id' => $branchId,
            'packeta_number' => $number,
            'consign_password' => $consignPassword,
            'number_verified' => $number !== '' && $expectedNumber !== '' ? ($number === $expectedNumber) : null,
        ];

        $labelBase64 = $this->fetchLabelBase64($client, $packetId);
        if ($labelBase64 !== '') {
            $binary = base64_decode(trim($labelBase64), true);
            if (is_string($binary) && $binary !== '') {
                try {
                    $stored = (new CarrierLabelStore(PacketaModule::CARRIER))->store('packeta-label-' . $packetId, $binary, 'pdf');
                    $fields['label_ref'] = $stored['ref'];
                    $fields['label_sha256'] = $stored['sha256'];
                } catch (\Throwable $e) {
                    // Label storage is best-effort; a proved packet must not be downgraded by it.
                }
            }
        }

        $this->confirmShipment($ledgerRow, $fields);

        return ProviderCallResult::success($fields, $request, ['packetId' => $packetId, 'number' => $number, 'branchId' => $branchId]);
    }

    /**
     * Resolve the packet id from the ledger row or its owner shipment mirror. Returns '' when neither
     * carries an id (the lost-response-before-id case → manual reconciliation).
     */
    private function resolvePacketId(object $ledgerRow): string
    {
        $candidates = [
            (string) ($ledgerRow->external_id ?? ''),
            (string) ($ledgerRow->external_ref2 ?? ''),
        ];

        if ((string) ($ledgerRow->owner_type ?? '') === 'shipment') {
            $shipmentId = (int) ($ledgerRow->owner_id ?? 0);
            if ($shipmentId > 0) {
                $row = (new ShipmentRepository())->getById($shipmentId);
                if ($row !== null) {
                    $candidates[] = (string) ($row->external_shipment_id ?? '');
                    $candidates[] = (string) ($row->barcode ?? '');
                }
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = PacketaClient::canonicalPacketId($candidate);
            // A packet id starts with a digit or the Z barcode prefix; skip obvious non-ids.
            if ($candidate !== '' && preg_match('/^(Z)?\d{6,}$/i', $candidate) === 1) {
                return $candidate;
            }
        }
        return '';
    }

    /** Mark the mirror row confirmed without regressing a terminal state (mirror machine §7.4). */
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
            PacketaPacketService::ensureShipmentInProgress($repo, $shipmentId);
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
            'external_shipment_id' => (string) ($fields['external_id'] ?? ''),
            'external_ref2' => (string) ($fields['external_ref2'] ?? ''),
            'barcode' => (string) ($fields['barcode'] ?? ''),
            'tracking_number' => (string) ($fields['tracking_number'] ?? ''),
            'carrier_reference' => (string) ($fields['carrier_reference'] ?? ''),
            'label_ref' => $fields['label_ref'] ?? null,
            'label_sha256' => $fields['label_sha256'] ?? null,
        ];

        $repo->markConfirmed($shipmentId, $extra);
        if (!empty($extra['label_ref'])) {
            $repo->setLabelState($shipmentId, 'PREPARED');
        }
    }

    private function fetchLabelBase64(PacketaClient $client, string $packetId): string
    {
        $format = (string) PacketaModule::setting('label_format', 'A6 on A6');
        $response = $client->packetLabelPdf($packetId, $format, 0);
        if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
            return '';
        }
        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return '';
        }
        return PacketaClient::firstString($decoded['result'], 'packetLabelPdfResult', 'label', 'value');
    }
}
