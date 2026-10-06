<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Shipping;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * Shipment cancellation lifecycle (W4 logistics scope).
 *
 * One deduped, state-aware entry for withdrawing a consignment at the carrier:
 *  - only carriers with a DOCUMENTED external cancellation route have a dispatcher (Packeta cancelPacket,
 *    MPL delete shipment, Foxpost delete parcel, DPD shipment cancellation); a label-file delete is NOT a
 *    cancellation and carriers without a documented route get a precise `cancellation_not_supported` block;
 *  - the identity is bound to THIS shipment (`fch:{order}:{carrier}:shipment_cancel:{shipment_no}`) with
 *    the binding (mode/account) taken from the original shipment row;
 *  - state-aware: a never-dispatched (NOT_STARTED) intent is cancelled LOCALLY with zero provider calls and
 *    its queued create intent is superseded; an in-flight/uncertain create defers the cancellation to
 *    `CancelShipmentJob` so the lifecycle continues from the PROVEN create outcome; a CONFIRMED shipment
 *    dispatches the provider cancellation exactly once;
 *  - UNCERTAIN is fenced: no re-dispatch, only the provider read-back (or an operator manual resolution);
 *  - success marks the shipment SUPERSEDED + cancelled_at and KEEPS the label file; a proven rejection
 *    leaves the confirmed shipment untouched.
 *
 * An FC order cancellation alone never calls this service: withdrawing a consignment is a separate,
 * merchant-initiated (or explicitly configured) decision, never derived from the order status.
 */
final class ShipmentCancellationService
{
    public const KIND = 'shipment_cancel';

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
     * Request the withdrawal of a shipment. Idempotent by shipment identity: a repeated or parallel
     * request replays the existing operation and never calls the provider twice.
     *
     * @param array<string,mixed> $opts reserved (requested_by/deferred flags)
     * @return array<string,mixed>
     */
    public function request(int $shipmentId, array $opts = []): array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null) {
            return $this->summary('error', '', '', $shipmentId, 'shipment_not_found');
        }
        $carrier = (string) $row->carrier;
        if (!CancelDispatcherRegistry::has($carrier)) {
            // Documented-capability fence: GLS has no external cancellation route (DeleteLabels is a
            // label-file operation), and no other carrier uses a simulated withdrawal.
            return $this->summary('blocked', $this->carrierLabel($row), $carrier, $shipmentId, 'cancellation_not_supported');
        }

        $state = (string) $row->state;
        if ($state === ExternalOperation::SUPERSEDED) {
            return $this->summary('already_cancelled', $state, $carrier, $shipmentId, null, ['cancelled_at' => (string) ($row->cancelled_at ?? '')]);
        }
        if (in_array($state, [ExternalOperation::PROVEN_REJECTED, ExternalOperation::MANUALLY_RESOLVED], true)) {
            return $this->summary('not_cancellable', $state, $carrier, $shipmentId, 'shipment_' . strtolower($state));
        }

        $orderId = (int) $row->order_id;
        $key = $this->identity($row);
        $lock = $this->acquireLock('fch:cancel:' . $orderId);
        try {
            $existing = $this->ledger->getByKey($key);
            if ($existing !== null) {
                return $this->replay($existing, $row);
            }

            if ($state === ExternalOperation::NOT_STARTED) {
                $createOpId = (int) ($row->operation_id ?? 0);
                if ($createOpId > 0) {
                    $createOp = $this->ledger->getById($createOpId);
                    $createState = $createOp === null ? '' : (string) $createOp->state;
                    if ($createState === ExternalOperation::IN_PROGRESS) {
                        return $this->defer($row);
                    }
                    if (in_array($createState, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                        $this->runner->enqueueReconcile($createOpId);
                        return $this->defer($row);
                    }
                    if ($createState === ExternalOperation::NOT_STARTED) {
                        // The provider was never called: cancel the queued create intent in the ledger and
                        // close the shipment row locally (no external identity is created).
                        $this->ledger->transition($createOpId, ExternalOperation::SUPERSEDED, [
                            'resolved_note' => 'local cancel before dispatch',
                        ], ExternalOperation::NOT_STARTED);
                    }
                }
                $fresh = $this->shipments->getById($shipmentId) ?? $row;
                if ((string) $fresh->state === ExternalOperation::NOT_STARTED) {
                    $this->shipments->markIntentCancelled($shipmentId);
                    return $this->summary('cancelled_local', ExternalOperation::SUPERSEDED, $carrier, $shipmentId, 'create_never_dispatched');
                }
                $row = $fresh;
                $state = (string) $row->state;
            }

            if ($state !== ExternalOperation::EXTERNAL_CONFIRMED) {
                // An in-flight/uncertain create: continue from its proven outcome via the deferred job.
                $createOpId = (int) ($row->operation_id ?? 0);
                if ($createOpId > 0) {
                    $createOp = $this->ledger->getById($createOpId);
                    if ($createOp !== null && in_array((string) $createOp->state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                        $this->runner->enqueueReconcile($createOpId);
                    }
                }
                return $this->defer($row);
            }

            $attrs = $this->ledgerAttrs($row, $key);
            $dispatcher = CancelDispatcherRegistry::get($carrier);
            if ($dispatcher === null) {
                return $this->summary('blocked', $state, $carrier, $shipmentId, 'cancellation_not_supported');
            }
            $run = $this->runner->run($attrs, static function (object $ledgerRow) use ($dispatcher) {
                return $dispatcher($ledgerRow);
            });
            $opId = (int) $run['row']->id;
            $fields = is_array($run['result']?->fields) ? $run['result']->fields : [];
            self::applyFromLedgerRow($opId, $fields);

            $state = (string) $run['state'];
            $originalId = $shipmentId;
            return match ($state) {
                ExternalOperation::EXTERNAL_CONFIRMED => $this->summary('cancelled_externally', $state, $carrier, $originalId, null, ['cancel_reference' => (string) ($fields['external_id'] ?? $run['row']->external_id ?? '')]),
                ExternalOperation::PROVEN_REJECTED => $this->summary('rejected', $state, $carrier, $originalId, (string) ($run['result']?->code ?? 'REJECTED')),
                ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION => $this->summary('uncertain', $state, $carrier, $originalId, (string) ($run['result']?->code ?? 'TIMEOUT_AFTER_DISPATCH')),
                default => $this->summary('local_error', $state, $carrier, $originalId, (string) ($run['result']?->code ?? 'LOCAL_ERROR')),
            };
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** Operator retry: replays the same identity and continues from the current proven state. */
    public function resume(int $shipmentId): array
    {
        return $this->request($shipmentId);
    }

    /** Operator read-back for an UNCERTAIN cancellation (never re-dispatches). */
    public function reconcileNow(int $opId): string
    {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => $opId]);
        } catch (\Throwable $e) {
            // Still-uncertain outcomes stay AWAITING for a human.
        }
        $row = $this->ledger->getById($opId);
        return $row === null ? '' : (string) $row->state;
    }

    /**
     * Mirror the proven cancellation onto the shipment row. Idempotent: a row already SUPERSEDED is a
     * no-op, and a REJECTED outcome leaves the confirmed shipment untouched.
     *
     * @param array<string,mixed> $fields provider result fields (external cancel id)
     */
    public static function applyFromLedgerRow(int $ledgerId, array $fields = []): void
    {
        $ledger = new LedgerRepository();
        $row = $ledger->getById($ledgerId);
        if ($row === null || (string) $row->kind !== self::KIND || (string) ($row->owner_type ?? '') !== 'shipment') {
            return;
        }
        if ((string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return;
        }
        $shipmentId = (int) ($row->owner_id ?? 0);
        if ($shipmentId <= 0) {
            return;
        }
        $reference = trim((string) ($fields['external_id'] ?? ($row->external_id ?? '')));
        (new ShipmentRepository())->markCancelled($shipmentId, $reference === '' ? [] : ['external_ref3' => $reference]);
    }

    // ------------------------------------------------------------------ internals

    private function defer(object $row): array
    {
        $orderId = (int) $row->order_id;
        $carrier = (string) $row->carrier;
        $jobKey = sprintf('fch:%d:%s:cancel_job:%d', $orderId, $carrier, (int) $row->shipment_no);
        JobRunner::instance()->enqueue(new JobSpec(
            $jobKey,
            CancelShipmentJob::HOOK,
            ['shipment_id' => (int) $row->id],
            5,
            3,
            'core'
        ));
        return $this->summary('deferred', (string) $row->state, $carrier, (int) $row->id, 'create_not_yet_proven');
    }

    /** @return array<string,mixed> */
    private function replay(object $existing, object $row): array
    {
        $carrier = (string) $row->carrier;
        return match ((string) $existing->state) {
            ExternalOperation::EXTERNAL_CONFIRMED => $this->summary('cancelled_externally', (string) $existing->state, $carrier, (int) $row->id, null, ['cancel_reference' => (string) ($existing->external_id ?? '')]),
            ExternalOperation::PROVEN_REJECTED => $this->summary('rejected', (string) $existing->state, $carrier, (int) $row->id, 'already_rejected'),
            ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION => $this->summary('uncertain', (string) $existing->state, $carrier, (int) $row->id, 'reconcile_required'),
            ExternalOperation::NOT_STARTED => $this->summary('in_flight', (string) $existing->state, $carrier, (int) $row->id, 'not_started_resume_available'),
            default => $this->summary('not_retryable', (string) $existing->state, $carrier, (int) $row->id, 'operation_' . strtolower((string) $existing->state)),
        };
    }

    /** @return array<string,mixed> */
    private function ledgerAttrs(object $row, string $key): array
    {
        $shipmentId = (int) $row->id;
        return [
            'idempotency_key' => $key,
            'module' => 'shipping',
            'provider' => (string) $row->carrier,
            'kind' => self::KIND,
            'mode' => (string) $row->mode,
            'account_id' => (string) $row->account_id,
            'order_id' => (int) $row->order_id,
            'owner_type' => 'shipment',
            'owner_id' => $shipmentId,
            'currency' => (string) ($row->currency ?? ''),
            'amount_minor' => (int) ($row->cod_amount_minor ?? 0),
            'business_reference' => (string) ($row->external_shipment_id ?? $row->barcode ?? ''),
            'request_fingerprint' => sprintf('shipment_cancel|shipment=%d|carrier=%s', $shipmentId, (string) $row->carrier),
            'max_attempts' => 3,
        ];
    }

    private function identity(object $row): string
    {
        return sprintf(
            'fch:%d:%s:shipment_cancel:%d',
            (int) $row->order_id,
            (string) $row->carrier,
            (int) $row->shipment_no
        );
    }

    private function carrierLabel(object $row): string
    {
        return (string) $row->state;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function summary(string $status, string $state, string $carrier, int $shipmentId, ?string $reason = null, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'state' => $state,
            'carrier' => $carrier,
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
