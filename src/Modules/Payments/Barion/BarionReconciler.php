<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Barion reconciler for UNCERTAIN payment starts (05_DATA_SCHEMA §7.2; invariant §1.4).
 *
 * It proves the outcome with the read-only GetPaymentState endpoint (04_BARION §5 row 2) and NEVER
 * re-dispatches /v2/Payment/Start. Barion has no lookup-by-reference endpoint (04_BARION §6, §10 item 2),
 * so when the lost-response start never produced a PaymentId the reconciler cannot prove anything and
 * returns UNCERTAIN, leaving the ledger row AWAITING_RECONCILIATION for a human.
 *
 * Registered via ReconcilerRegistry::register('barion', [$reconciler, 'reconcile']).
 * Signature: callable(object $ledgerRow): ProviderCallResult.
 */
final class BarionReconciler
{
    private ?BarionClient $client;

    private ModuleSettings $settings;

    public function __construct(?BarionClient $client = null, ?ModuleSettings $settings = null)
    {
        $this->settings = $settings ?? new ModuleSettings();
        $this->client = $client;
    }

    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        // Refund rows are proven through the payment state + our Comment marker (W4_EXECUTION_PLAN §2.1).
        if ((string) ($ledgerRow->kind ?? '') === 'refund') {
            return (new BarionRefundService(null, null))->reconcile($ledgerRow);
        }

        $mode = (string) ($ledgerRow->mode ?? $this->settings->mode());
        $mode = $mode === 'live' ? 'live' : 'test';

        // external_id is the Barion PaymentId persisted on the successful start; without it there is no
        // documented way to find the payment (04_BARION §6/§10 item 2) -> cannot prove -> UNCERTAIN.
        $paymentId = (string) ($ledgerRow->external_id ?? '');
        if ($paymentId === '') {
            return ProviderCallResult::uncertain('BARION_NO_EXTERNAL_ID');
        }

        $repo = new PaymentAttemptRepository();
        $attempt = $repo->findByExternalPaymentId(BarionClient::PROVIDER, $mode, $paymentId);
        if ($attempt === null) {
            return ProviderCallResult::uncertain('BARION_ATTEMPT_NOT_FOUND');
        }

        $client = $this->client ?? new BarionClient($mode);
        $response = $client->getPaymentState($paymentId);
        if (!$response->isSuccess()) {
            return ProviderCallResult::uncertain('BARION_RECONCILE_QUERY_FAILED');
        }

        $state = $response->json();
        $rawStatus = substr((string) ($state['Status'] ?? ''), 0, 48);

        $check = BarionStatus::validateCallback($state, [
            'external_payment_id' => $paymentId,
            'external_request_id' => (string) ($attempt->external_request_id ?? ''),
            'amount_minor' => (int) $attempt->amount_minor,
            'currency' => (string) $attempt->currency,
        ]);

        if ($check['ok']) {
            $currency = strtoupper((string) ($state['Currency'] ?? $attempt->currency));
            $chargedMinor = BarionStatus::amountMinorFromProvider($state['Total'] ?? null, $currency) ?? (int) $attempt->amount_minor;
            if (!$this->confirmAttempt($repo, $attempt, $paymentId, $chargedMinor, $rawStatus, (string) ($state['TraceId'] ?? ''))) {
                // Terminal conflict (late success after a proved failure): never force a reverse
                // transition; leave the row for manual handling (05_DATA_SCHEMA §8.4 rule 3).
                return ProviderCallResult::uncertain('BARION_TERMINAL_CONFLICT');
            }

            return ProviderCallResult::success([
                'external_id' => $paymentId,
                'external_ref2' => $rawStatus,
                'business_reference' => (string) ($attempt->external_request_id ?? ''),
            ], ['PaymentId' => $paymentId], ['Status' => $rawStatus]);
        }

        if (BarionStatus::isFinalFailure((string) ($state['Status'] ?? ''))) {
            $this->rejectAttempt($repo, $attempt, $rawStatus);
            return ProviderCallResult::rejected(
                'BARION_' . strtoupper((string) ($state['Status'] ?? 'FAILED')),
                'Barion payment ' . $paymentId . ' is ' . (string) ($state['Status'] ?? 'FAILED')
            );
        }

        // Still pending (or an unresolved mismatch): keep the row for reconciliation, no state change.
        return ProviderCallResult::uncertain('BARION_STILL_' . strtoupper((string) ($state['Status'] ?? 'UNKNOWN')));
    }

    private function confirmAttempt(
        PaymentAttemptRepository $repo,
        object $attempt,
        string $paymentId,
        int $chargedMinor,
        string $rawStatus,
        string $traceId
    ): bool {
        $attemptId = (int) $attempt->id;
        $state = (string) $attempt->state;
        if (in_array($state, [
            ExternalOperation::PROVEN_REJECTED,
            ExternalOperation::SUPERSEDED,
            ExternalOperation::MANUALLY_RESOLVED,
        ], true)) {
            return false;
        }
        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return true;
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            $repo->markStarted($attemptId, []);
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            // The mirror state machine forbids UNCERTAIN -> EXTERNAL_CONFIRMED directly: the read-back
            // attempt is the documented reconcile hop (mirrors MplReconciler/SzamlazzReconciler).
            $repo->markAwaitingReconciliation($attemptId);
        }
        return $repo->markVerified($attemptId, [
            'verification_source' => 'status_api',
            'external_payment_id' => $paymentId,
            'charged_amount_minor' => $chargedMinor,
            'raw_state' => $rawStatus,
            'external_ref2' => $traceId,
        ]);
    }

    private function rejectAttempt(PaymentAttemptRepository $repo, object $attempt, string $rawStatus): bool
    {
        $attemptId = (int) $attempt->id;
        $state = (string) $attempt->state;
        if (in_array($state, [
            ExternalOperation::EXTERNAL_CONFIRMED,
            ExternalOperation::PROVEN_REJECTED,
            ExternalOperation::SUPERSEDED,
            ExternalOperation::MANUALLY_RESOLVED,
        ], true)) {
            return false;
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            $repo->markStarted($attemptId, []);
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            // Same documented hop as confirmAttempt: no UNCERTAIN -> terminal transition on mirrors.
            $repo->markAwaitingReconciliation($attemptId);
        }
        return $repo->markRejected($attemptId, [
            'verification_source' => 'status_api',
            'raw_state' => $rawStatus,
        ]);
    }
}
