<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

use FluentCartHungary\Core\Billing\CorrectionFinalizer;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Payments\RefundFinalizer;
use FluentCartHungary\Core\Shipping\ShipmentCancellationService;

/**
 * Resolves UNCERTAIN operations via the provider's read API (05_DATA_SCHEMA §7.1; invariant §1.4).
 * It never re-dispatches the create call: the provider reconciler must *prove* the outcome by lookup.
 * Until a reconciler exists or succeeds, the row stays AWAITING_RECONCILIATION (manual queue), and the
 * job retries with backoff then fails into the dead-letter state — never a false resolution.
 */
final class ReconcileUncertainJob implements JobInterface
{
    public const HOOK = 'fch_reconcile_uncertain';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $id = (int) ($args['operation_id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('operation_id is required.');
        }

        $ledger = new LedgerRepository();
        $row = $ledger->getById($id);
        if ($row === null) {
            return;
        }

        $state = (string) $row->state;
        if (ExternalOperation::isTerminal($state)) {
            return;
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            $ledger->transition($id, ExternalOperation::AWAITING_RECONCILIATION, ['lease_owner' => 'reconciler'], ExternalOperation::UNCERTAIN);
            $row = $ledger->getById($id) ?? $row;
        }
        if ((string) $row->state !== ExternalOperation::AWAITING_RECONCILIATION) {
            return;
        }

        $provider = (string) $row->provider;
        $reconciler = ReconcilerRegistry::get($provider);
        if ($reconciler === null) {
            // No read-back path yet: keep the row for manual resolution; retry the job with backoff.
            throw new \RuntimeException('NO_RECONCILER:' . $provider);
        }

        $result = $reconciler($row);
        if (!$result instanceof ProviderCallResult) {
            throw new \RuntimeException('BAD_RECONCILER:' . $provider);
        }

        switch ($result->status) {
            case ProviderCallResult::SUCCESS:
                $ledger->transition($id, ExternalOperation::EXTERNAL_CONFIRMED, array_merge($result->fields, [
                    'reconciled_at' => gmdate('Y-m-d H:i:s'),
                ]), ExternalOperation::AWAITING_RECONCILIATION);
                if ((string) $row->kind === 'refund') {
                    // A proven refund outcome must reach FluentCart: promote the parked transaction or
                    // roll the local record back — never leave the FC mirror ahead of the ledger.
                    RefundFinalizer::applyFromLedgerRow($id);
                }
                if (in_array((string) $row->kind, CorrectionFinalizer::KINDS, true)) {
                    // A proven correction outcome must reach the document chain (original -> SUPERSEDED).
                    CorrectionFinalizer::applyFromLedgerRow($id, $result->fields);
                }
                if ((string) $row->kind === ShipmentCancellationService::KIND) {
                    // A proven cancellation must reach the shipment row (SUPERSEDED + cancelled_at).
                    ShipmentCancellationService::applyFromLedgerRow($id, $result->fields);
                }
                break;
            case ProviderCallResult::REJECTED:
                $ledger->transition($id, ExternalOperation::PROVEN_REJECTED, array_merge($result->fields, [
                    'reconciled_at' => gmdate('Y-m-d H:i:s'),
                    'local_error_code' => (string) $result->code,
                    'local_error_message' => (string) $result->message,
                ]), ExternalOperation::AWAITING_RECONCILIATION);
                if ((string) $row->kind === 'refund') {
                    RefundFinalizer::applyFromLedgerRow($id);
                }
                if (in_array((string) $row->kind, CorrectionFinalizer::KINDS, true)) {
                    CorrectionFinalizer::applyFromLedgerRow($id, $result->fields);
                }
                break;
            default:
                // Still unresolved: retry with backoff; the row remains AWAITING for the operator.
                throw new \RuntimeException('RECONCILE_STILL_UNCERTAIN:' . (string) $result->code);
        }
    }
}
