<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;

/**
 * W4 (PKT-007): Packeta return/claim creation routed through the ledger.
 *
 * `createPacketClaimWithPassword` has NO idempotency key and NO documented lookup-by-number (04 §4 row 7,
 * §7), so:
 *  - exactly one claim intent per shipment/packet (`fch:{order}:packeta:claim_create:{shipment_no}`), the
 *    binding (mode/account) and packet id taken from the ORIGINAL confirmed shipment row;
 *  - `max_attempts=1`: a timeout is UNCERTAIN and is NEVER re-POSTed (no read-back exists — the operator
 *    resolves it manually with the provider UI/claim password, which is stored sealed when received);
 *  - the 9-digit claim password artifact is stored in the sealed private carrier store and never reaches
 *    the ledger payloads, an admin notice string, or any log;
 *  - a local error (missing credential, storage failure) stays NOT_STARTED so the SAME identity can be
 *    resumed after the operator fixes the cause.
 */
final class PacketaClaimService
{
    public const KIND = 'claim_create';
    public const MAX_ATTEMPTS = 1;

    private ShipmentRepository $shipments;

    private OperationRunner $runner;

    private LedgerRepository $ledger;

    public function __construct(
        ?ShipmentRepository $shipments = null,
        ?OperationRunner $runner = null,
        ?LedgerRepository $ledger = null
    ) {
        $this->shipments = $shipments ?? new ShipmentRepository();
        $this->runner = $runner ?? new OperationRunner();
        $this->ledger = $ledger ?? new LedgerRepository();
    }

    /**
     * @param array<string,mixed> $opts reserved for future overrides (send_email etc.)
     * @return array<string,mixed>
     */
    public function request(int $shipmentId, array $opts = []): array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null) {
            return $this->summary('error', '', $shipmentId, 'shipment_not_found');
        }
        if ((string) $row->carrier !== PacketaModule::CARRIER) {
            return $this->summary('blocked', (string) $row->state, $shipmentId, 'claim_only_for_packeta');
        }
        if ((string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary('blocked', (string) $row->state, $shipmentId, 'shipment_not_confirmed');
        }
        $barcode = PacketaClient::canonicalPacketId((string) ($row->external_shipment_id ?? $row->barcode ?? ''));
        if ($barcode === '') {
            return $this->summary('blocked', (string) $row->state, $shipmentId, 'no_packet_id');
        }

        $orderId = (int) $row->order_id;
        $key = sprintf('fch:%d:%s:claim_create:%d', $orderId, PacketaModule::CARRIER, (int) $row->shipment_no);
        $lock = $this->acquireLock('fch:claim:' . $orderId);
        try {
            $existing = $this->ledger->getByKey($key);
            if ($existing !== null && (string) $existing->state !== ExternalOperation::NOT_STARTED) {
                return $this->replay($existing, $shipmentId);
            }

            $order = PacketaModule::loadOrder($orderId);
            if ($order === null) {
                return $this->summary('error', '', $shipmentId, 'order_not_found');
            }
            $claims = new PacketaClaims();
            $snapshot = $claims->buildSnapshotFor($order, $barcode);
            $errors = PacketaClaims::validate($snapshot);
            if ($errors !== []) {
                // Local validation before dispatch: no ledger row, the operator fixes the customer data.
                return $this->summary('blocked', '', $shipmentId, 'claim_validation_failed', ['errors' => $errors]);
            }

            $attrs = $this->ledgerAttrs($row, $key, (int) ($snapshot['value_minor'] ?? 0), (string) ($snapshot['currency'] ?? ''));
            if ($existing !== null) {
                // Resume the SAME not-yet-dispatched identity: clear the local backoff/stale lease.
                $this->ledger->clearRetryAt((int) $existing->id);
            }
            $run = $this->runner->run($attrs, function (object $ledgerRow) use ($snapshot): ProviderCallResult {
                return $this->dispatch($ledgerRow, $snapshot);
            });
            $opId = (int) $run['row']->id;
            $state = (string) $run['state'];
            $fields = is_array($run['result']?->fields) ? $run['result']->fields : [];

            if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
                $this->persistOrderRefs($orderId, (string) ($fields['external_ref2'] ?? $barcode), (string) ($fields['password_ref'] ?? ''));
                return $this->summary('confirmed', $state, $shipmentId, null, [
                    'operation_id' => $opId,
                    'claim_barcode' => (string) ($fields['external_ref2'] ?? $barcode),
                    'claim_id' => (string) ($fields['external_id'] ?? ''),
                ]);
            }
            if ($state === ExternalOperation::PROVEN_REJECTED) {
                return $this->summary('rejected', $state, $shipmentId, (string) ($run['result']?->code ?? 'REJECTED'), ['operation_id' => $opId]);
            }
            if (in_array($state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                // No documented read-back for claims: the row stays fenced for a manual resolution.
                $this->runner->enqueueReconcile($opId);
                return $this->summary('uncertain', $state, $shipmentId, (string) ($run['result']?->code ?? 'CLAIM_TIMEOUT_NO_READBACK'), ['operation_id' => $opId]);
            }
            return $this->summary('local_error', $state, $shipmentId, (string) ($run['result']?->code ?? 'LOCAL_ERROR'), ['operation_id' => $opId]);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** Operator retry: continues the SAME claim identity (only a not-yet-dispatched row can re-dispatch). */
    public function resume(int $shipmentId): array
    {
        return $this->request($shipmentId);
    }

    /** Resolve the latest confirmed Packeta shipment of an order and request its claim. */
    public function claimForOrder(int $orderId): array
    {
        foreach (array_reverse($this->shipments->listForOrder($orderId)) as $row) {
            if ((string) $row->carrier !== PacketaModule::CARRIER) {
                continue;
            }
            if ((string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED) {
                return $this->request((int) $row->id);
            }
        }
        return $this->summary('blocked', '', 0, 'no_confirmed_packet');
    }

    /**
     * Read-back is impossible by the documented contract: the claim may exist; resolving it needs a human.
     * This never rejects and never re-dispatches.
     *
     * @param object $ledgerRow claim_create row
     */
    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        return ProviderCallResult::uncertain('CLAIM_NO_READBACK_MANUAL_REQUIRED');
    }

    // ------------------------------------------------------------------ internals

    /** @param object $ledgerRow claim_create row */
    private function dispatch(object $ledgerRow, array $snapshot): ProviderCallResult
    {
        $password = trim((string) (new CredentialStore())->get(
            PacketaModule::PROVIDER,
            (string) $ledgerRow->mode,
            (string) $ledgerRow->account_id,
            'api_password'
        ));
        if ($password === '') {
            return ProviderCallResult::localError('MISSING_CREDENTIALS', 'A visszaküldéshez az eredeti Packeta fiók (mód/fiók) apiPassword kulcsa nem érhető el.');
        }

        $request = [
            'operation' => 'createPacketClaimWithPassword',
            'number' => (string) ($snapshot['number'] ?? ''),
            'value' => (string) ($snapshot['value_minor'] ?? ''),
            'currency' => (string) ($snapshot['currency'] ?? ''),
        ];
        $result = (new PacketaClaims())->create(PacketaClaims::buildAttributes($snapshot), $snapshot, new PacketaClient($password));

        if (!empty($result['ok'])) {
            $fields = [
                'external_id' => (string) ($result['id'] ?? ''),
                'external_ref2' => (string) ($result['barcode'] ?? ''),
                'barcode' => (string) ($result['barcode'] ?? ''),
                'document_number' => (string) ($result['barcode_text'] ?? $result['barcode'] ?? ''),
                'provider_doc_type' => 'claim',
                'password_ref' => (string) ($result['password_ref'] ?? ''),
            ];
            return ProviderCallResult::success($fields, $request, [
                'claimId' => (string) ($result['id'] ?? ''),
                'barcode' => (string) ($result['barcode'] ?? ''),
            ]);
        }

        $error = (string) ($result['error'] ?? 'claim_failed');
        if ($error === 'timeout') {
            // No idempotency key and no lookup: never re-POST. Stays UNCERTAIN for a human.
            return ProviderCallResult::uncertain('CLAIM_TIMEOUT_NO_READBACK', $request);
        }
        if (str_starts_with($error, 'http_')) {
            $code = (int) substr($error, 5);
            if ($code >= 500) {
                return ProviderCallResult::uncertain('PACKETA_HTTP_' . $code, $request);
            }
            return ProviderCallResult::rejected('PACKETA_CLAIM_REJECTED', $error, $request);
        }
        if (in_array($error, ['missing_credentials', 'password_storage_failed'], true)) {
            // Local: no external side effect happened (storage failure aborts before returning the secret),
            // the same identity can be resumed after the operator fixes the cause.
            return ProviderCallResult::localError(strtoupper($error), 'A visszaküldés nem indult el (' . $error . ').');
        }
        return ProviderCallResult::rejected('PACKETA_CLAIM_FAULT', $error, $request);
    }

    /** @return array<string,mixed> */
    private function ledgerAttrs(object $row, string $key, int $valueMinor, string $currency): array
    {
        return [
            'idempotency_key' => $key,
            'module' => 'shipping',
            'provider' => PacketaModule::PROVIDER,
            'kind' => self::KIND,
            'mode' => (string) $row->mode,
            'account_id' => (string) $row->account_id,
            'order_id' => (int) $row->order_id,
            'owner_type' => 'shipment',
            'owner_id' => (int) $row->id,
            'currency' => $currency,
            'amount_minor' => $valueMinor,
            'business_reference' => PacketaClient::canonicalPacketId((string) ($row->external_shipment_id ?? $row->barcode ?? '')),
            'request_fingerprint' => sprintf('claim_create|shipment=%d|barcode=%s', (int) $row->id, (string) ($row->barcode ?? '')),
            'max_attempts' => self::MAX_ATTEMPTS,
        ];
    }

    /** @return array<string,mixed> */
    private function replay(object $existing, int $shipmentId): array
    {
        return match ((string) $existing->state) {
            ExternalOperation::EXTERNAL_CONFIRMED => $this->summary('already_confirmed', (string) $existing->state, $shipmentId, null, [
                'operation_id' => (int) $existing->id,
                'claim_barcode' => (string) ($existing->external_ref2 ?? ''),
            ]),
            ExternalOperation::PROVEN_REJECTED => $this->summary('rejected', (string) $existing->state, $shipmentId, 'already_rejected', ['operation_id' => (int) $existing->id]),
            ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION => $this->summary('uncertain', (string) $existing->state, $shipmentId, 'manual_resolution_required', ['operation_id' => (int) $existing->id]),
            ExternalOperation::NOT_STARTED => $this->summary('in_flight', (string) $existing->state, $shipmentId, 'not_started_resume_available', ['operation_id' => (int) $existing->id]),
            default => $this->summary('not_retryable', (string) $existing->state, $shipmentId, 'operation_' . strtolower((string) $existing->state), ['operation_id' => (int) $existing->id]),
        };
    }

    private function persistOrderRefs(int $orderId, string $claimBarcode, string $passwordRef): void
    {
        $order = PacketaModule::loadOrder($orderId);
        if ($order === null || !method_exists($order, 'updateMeta')) {
            return;
        }
        $order->updateMeta('_fch_packeta_claim_ref', $claimBarcode);
        $order->updateMeta('_fch_packeta_claim_password_ref', $passwordRef);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function summary(string $status, string $state, int $shipmentId, ?string $reason = null, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'state' => $state,
            'shipment_id' => $shipmentId,
            'reason' => $reason,
        ], $extra);
    }

    private function acquireLock(string $name): ?string
    {
        global $wpdb;
        if (!method_exists($wpdb, 'get_var')) {
            return null;
        }
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        return (string) $got === '1' ? $name : null;
    }

    private function releaseLock(?string $name): void
    {
        if ($name === null) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}
