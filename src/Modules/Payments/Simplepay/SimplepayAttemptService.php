<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;

/**
 * Shared attempt-state transitions and FluentCart settlement for the SimplePay module
 * (05_DATA_SCHEMA §7.2; 04_OTP_SIMPLE_CONTRACT.md §3/§5).
 *
 * Rules encoded here:
 *  - Only a validated `FINISHED` query transaction confirms an attempt (`status_api`/`query`/`signed_ipn`
 *    verification source); AUTHORIZED (two-step) must never settle.
 *  - Definitive no-charge statuses (TIMEOUT/CANCELLED/NOTAUTHORIZED/REVERSED) reject the attempt.
 *  - Terminal attempts are never reopened: a late confirmation for a terminal row is reported as a
 *    conflict (observation + manual handling), never a second settlement.
 *  - FluentCart settlement goes through CODE_VERIFIED Core APIs only (OrderTransaction + StatusHelper,
 *    same pattern as the W1 Barion module); an already-paid order is never settled twice.
 */
final class SimplepayAttemptService
{
    private PaymentAttemptRepository $repo;

    public function __construct(?PaymentAttemptRepository $repo = null)
    {
        $this->repo = $repo ?? new PaymentAttemptRepository();
    }

    /**
     * Apply a validated `query` transaction entry to the attempt.
     *
     * @param object              $attempt          attempt row
     * @param array<string,mixed> $tx               one element of the query `transactions[]`
     * @param string              $source           verification source ('query'|'status_api'|'signed_ipn')
     * @param string|null         $expectedMerchant merchant account the attempt was created with
     * @return array{action:string,state:string,reason:string,settled:string,charged_minor:?int}
     */
    public function applyQueryOutcome(object $attempt, array $tx, string $source, ?string $expectedMerchant = null): array
    {
        $attemptId = (int) $attempt->id;
        $currency = strtoupper((string) $attempt->currency);
        $status = strtoupper(trim((string) ($tx['status'] ?? '')));

        $check = SimplepayStatus::validateQueryTransaction($tx, [
            'merchant' => (string) ($expectedMerchant ?? ''),
            'order_ref' => (string) ($attempt->external_request_id ?? ''),
            'transaction_id' => (string) ($attempt->external_payment_id ?? ''),
            'amount_minor' => (int) $attempt->amount_minor,
            'currency' => $currency,
        ]);
        if (!$check['ok']) {
            $this->observe($attemptId, $status !== '' ? $status : $check['reason'], $source);
            return [
                'action' => 'mismatch',
                'state' => (string) $attempt->state,
                'reason' => $check['reason'],
                'settled' => '',
                'charged_minor' => null,
            ];
        }

        $charged = SimplepayStatus::providerAmountToMinor($tx['total'], $currency);
        $action = SimplepayStatus::actionFor($status);

        if ($action === SimplepayStatus::ACTION_CONFIRM) {
            $confirmed = $this->confirmAttempt(
                $attempt,
                (string) ($tx['transactionId'] ?? ($attempt->external_payment_id ?? '')),
                $charged ?? (int) $attempt->amount_minor,
                $status,
                $source
            );
            if (!$confirmed) {
                // Late success on a terminal attempt: never reopen, never settle twice.
                $this->observe($attemptId, $status, $source);
                return [
                    'action' => 'late_conflict',
                    'state' => (string) $attempt->state,
                    'reason' => 'terminal_attempt',
                    'settled' => '',
                    'charged_minor' => $charged,
                ];
            }

            $settled = $this->settleOrder(
                (int) $attempt->order_id,
                (string) ($tx['transactionId'] ?? ($attempt->external_payment_id ?? '')),
                (string) $attempt->mode,
                $status
            );
            $fresh = $this->repo->getById($attemptId);

            return [
                'action' => 'confirmed',
                'state' => $fresh !== null ? (string) $fresh->state : ExternalOperation::EXTERNAL_CONFIRMED,
                'reason' => 'ok',
                'settled' => $settled,
                'charged_minor' => $charged,
            ];
        }

        if ($action === SimplepayStatus::ACTION_REJECT) {
            $this->rejectAttempt($attempt, $status, $source);
            $fresh = $this->repo->getById($attemptId);
            return [
                'action' => 'rejected',
                'state' => $fresh !== null ? (string) $fresh->state : ExternalOperation::PROVEN_REJECTED,
                'reason' => $status,
                'settled' => '',
                'charged_minor' => $charged,
            ];
        }

        // Pending (INIT/INPAYMENT/INFRAUD/AUTHORIZED two-step) or unknown: observation only.
        $this->observe($attemptId, $status !== '' ? $status : 'UNKNOWN', $source);
        return [
            'action' => $action === SimplepayStatus::ACTION_PENDING ? 'pending' : 'observed',
            'state' => (string) $attempt->state,
            'reason' => $status !== '' ? $status : 'unknown_status',
            'settled' => '',
            'charged_minor' => $charged,
        ];
    }

    /** Mark the attempt confirmed; false when the attempt is terminal (never reopened). */
    public function confirmAttempt(
        object $attempt,
        string $transactionId,
        int $chargedMinor,
        string $rawStatus,
        string $source
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
            return true; // idempotent duplicate
        }
        $extra = [
            'verification_source' => $source,
            'charged_amount_minor' => $chargedMinor,
            'raw_state' => substr($rawStatus, 0, 48),
        ];
        if ($transactionId !== '') {
            $extra['external_payment_id'] = $transactionId;
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            $this->repo->markStarted($attemptId, []);
            $state = ExternalOperation::IN_PROGRESS;
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            // Mirror machine hop (never UNCERTAIN -> confirmed directly).
            $this->repo->markAwaitingReconciliation($attemptId);
        }
        return $this->repo->markVerified($attemptId, $extra);
    }

    /** Mark the attempt rejected; terminal rows keep their state (idempotent). */
    public function rejectAttempt(object $attempt, string $rawStatus, string $source): bool
    {
        $attemptId = (int) $attempt->id;
        $state = (string) $attempt->state;
        if (in_array($state, [
            ExternalOperation::EXTERNAL_CONFIRMED,
            ExternalOperation::PROVEN_REJECTED,
            ExternalOperation::SUPERSEDED,
            ExternalOperation::MANUALLY_RESOLVED,
        ], true)) {
            return $state === ExternalOperation::PROVEN_REJECTED;
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            $this->repo->markStarted($attemptId, []);
            $state = ExternalOperation::IN_PROGRESS;
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            $this->repo->markAwaitingReconciliation($attemptId);
        }
        return $this->repo->markRejected($attemptId, [
            'verification_source' => $source,
            'raw_state' => substr($rawStatus, 0, 48),
        ]);
    }

    /** Non-proving observation: raw_state only, state unchanged. */
    public function observe(int $attemptId, string $rawStatus, string $source): void
    {
        $this->repo->touchObservation($attemptId, substr($rawStatus, 0, 48), substr($source, 0, 24));
    }

    /**
     * Settle the FluentCart order using CODE_VERIFIED Core APIs only (no Core patching).
     * Returns a diagnostic outcome code. An already-paid order is never settled twice.
     */
    public function settleOrder(int $orderId, string $transactionId, string $mode, string $rawStatus): string
    {
        if (!class_exists('\FluentCart\App\Models\Order')
            || !class_exists('\FluentCart\App\Models\OrderTransaction')
            || !class_exists('\FluentCart\App\Helpers\StatusHelper')) {
            return 'fc_unavailable';
        }

        try {
            $order = \FluentCart\App\Models\Order::query()->find($orderId);
            if (!$order) {
                return 'order_not_found';
            }

            $transaction = \FluentCart\App\Models\OrderTransaction::query()
                ->where('order_id', $orderId)
                ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE)
                ->orderBy('id', 'desc')
                ->first();
            if (!$transaction) {
                return 'transaction_not_found';
            }

            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $meta['simplepay_transaction_id'] = $transactionId;
            $meta['simplepay_status'] = $rawStatus;

            $transaction->vendor_charge_id = $transactionId;
            $transaction->payment_method = SimplepayStatus::PROVIDER;
            $transaction->payment_mode = $mode;
            $transaction->meta = $meta;
            $transaction->status = \FluentCart\App\Helpers\Status::TRANSACTION_SUCCEEDED;
            $transaction->save();

            $order->updateMeta('simplepay_transaction_id', $transactionId);

            if ((string) $order->payment_status === 'paid') {
                return 'already_paid';
            }

            (new \FluentCart\App\Helpers\StatusHelper($order))->syncOrderStatuses($transaction);

            return 'settled';
        } catch (\Throwable $e) {
            return 'settle_error';
        }
    }
}
