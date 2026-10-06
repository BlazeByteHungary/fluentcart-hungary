<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Webhooks\WebhookEventRepository;

/**
 * Barion callback (IPN) ingress for /?fluent-cart=fct_payment_listener_ipn&method=barion
 * (GlobalPaymentHandler.php:41-67; PaymentHelper.php:22-27).
 *
 * Barion callbacks are UNSIGNED and carry only `paymentId`; the documented security model is to answer
 * HTTP 200 and then authenticate the state with a POS-key GetPaymentState call (04_BARION §3 item 7;
 * §5 row 11). Therefore:
 *   ingest (dedup paymentId, signature_valid NULL) -> server-side GetPaymentState -> compare
 *   amount/currency/reference -> markVerified('status_api') -> settle the FluentCart order.
 * A mismatch records an observation with no state change (invariant §1.2); anything unprovable stays
 * RECEIVED for reconciliation (invariant §1.3/§1.4).
 */
final class BarionIpnHandler
{
    public const PROVIDER = 'barion';

    private ?BarionClient $client;

    private ModuleSettings $settings;

    public function __construct(?BarionClient $client = null, ?ModuleSettings $settings = null)
    {
        $this->settings = $settings ?? new ModuleSettings();
        $this->client = $client;
    }

    /** @return array<string,mixed> */
    public function handle(): array
    {
        $request = $this->requestVars();
        $paymentId = $this->sanitize((string) ($request['paymentId'] ?? $request['PaymentId'] ?? ''));
        if ($paymentId === '') {
            return ['status' => 'ignored', 'reason' => 'missing_payment_id'];
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(self::PROVIDER);

        $webhooks = new WebhookEventRepository();
        $ingest = $webhooks->ingest([
            'provider' => self::PROVIDER,
            'mode' => $mode,
            'account_id' => $account,
            'dedup_key' => $paymentId,
            // Unsigned callback by design: status-API verification is the auth (04_BARION §3 item 7).
            'signature_valid' => null,
            'raw_body' => $this->rawBody(),
            'external_ref' => $paymentId,
            'event_type' => 'payment_callback',
        ]);
        $webhookId = (int) $ingest['row']->id;

        $repo = new PaymentAttemptRepository();
        $attempt = $repo->findByExternalPaymentId(self::PROVIDER, $mode, $paymentId);
        if ($attempt === null) {
            // Callback before our commit / unknown order: leave RECEIVED for reconciliation (§7.5).
            return ['status' => 'deferred', 'reason' => 'attempt_not_found', 'duplicate' => $ingest['duplicate']];
        }

        $client = $this->client ?? new BarionClient($mode);
        $response = $client->getPaymentState($paymentId);
        if (!$response->isSuccess()) {
            // Not provable now: record the observation and keep the ingress row for retry/reconciliation.
            $this->recordObservation($repo, $attempt, 'status_api_unavailable');
            $webhooks->touchRetry($webhookId, 'status_api_unavailable');
            return ['status' => 'retry', 'reason' => 'status_api_unavailable'];
        }

        $state = $response->json();
        $rawStatus = substr((string) ($state['Status'] ?? ''), 0, 48);

        $check = BarionStatus::validateCallback($state, [
            'external_payment_id' => $paymentId,
            'external_request_id' => (string) ($attempt->external_request_id ?? ''),
            'amount_minor' => (int) $attempt->amount_minor,
            'currency' => (string) $attempt->currency,
        ]);

        if (!$check['ok']) {
            // A definitive provider failure (Canceled/Expired/Failed/PartiallySucceeded) is a proved rejection.
            if (BarionStatus::isFinalFailure((string) ($state['Status'] ?? ''))) {
                $this->rejectAttempt($repo, $attempt, $rawStatus);
                $webhooks->markProcessed($webhookId);
                return ['status' => 'rejected', 'reason' => $rawStatus !== '' ? $rawStatus : $check['reason']];
            }

            if ($check['reason'] === 'status_not_succeeded') {
                // Pending lifecycle state: observation only, no state change.
                $this->recordObservation($repo, $attempt, $rawStatus);
                $webhooks->markProcessed($webhookId);
                return ['status' => 'pending', 'reason' => $rawStatus !== '' ? $rawStatus : 'pending'];
            }

            // Amount/currency/reference mismatch on a Succeeded state: never settle; flag for manual
            // review (05_DATA_SCHEMA §7.5 FAILED = dead-letter/manual; invariant §1.2).
            $this->recordObservation($repo, $attempt, $rawStatus);
            $webhooks->markFailed($webhookId, 'CALLBACK_MISMATCH:' . $check['reason']);
            return ['status' => 'mismatch', 'reason' => $check['reason']];
        }

        $currency = strtoupper((string) ($state['Currency'] ?? $attempt->currency));
        $chargedMinor = BarionStatus::amountMinorFromProvider($state['Total'] ?? null, $currency);
        if ($chargedMinor === null) {
            $this->recordObservation($repo, $attempt, $rawStatus);
            $webhooks->touchRetry($webhookId, 'amount_unparsable');
            return ['status' => 'retry', 'reason' => 'amount_unparsable'];
        }

        $wasAlreadyConfirmed = (string) $attempt->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $confirmed = $this->confirmAttempt($repo, $attempt, $paymentId, $chargedMinor, $rawStatus, (string) ($state['TraceId'] ?? ''));
        if (!$confirmed) {
            // Late success on a terminal attempt: a proved failure is never reopened and the order is
            // never auto-settled from a reverse transition — store it and flag for manual handling
            // (05_DATA_SCHEMA §8.4 rule 3).
            $this->recordObservation($repo, $attempt, $rawStatus);
            $webhooks->markFailed($webhookId, 'LATE_CONFLICT:' . $rawStatus);
            return ['status' => 'late_conflict', 'reason' => $rawStatus !== '' ? $rawStatus : 'terminal'];
        }

        $webhooks->markProcessed($webhookId);
        $settled = $wasAlreadyConfirmed ? 'already_confirmed' : $this->settleOrder((int) $attempt->order_id, $paymentId, $mode, $rawStatus);

        return [
            'status' => 'confirmed',
            'payment_id' => $paymentId,
            'charged_minor' => $chargedMinor,
            'settled' => $settled,
        ];
    }

    // ---------------------------------------------------------------- verification / settlement

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
            return false; // terminal: a proved failure is never reopened
        }
        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return true; // idempotent duplicate
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            $repo->markStarted($attemptId, []);
        }

        return $repo->markVerified($attemptId, [
            'verification_source' => 'status_api', // 05_DATA_SCHEMA §7.2: only server verification proves payment
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
        return $repo->markRejected($attemptId, [
            'verification_source' => 'status_api',
            'raw_state' => $rawStatus,
        ]);
    }

    private function recordObservation(PaymentAttemptRepository $repo, object $attempt, string $rawStatus): void
    {
        // Non-proving observation: recorded in raw_state only, state unchanged (05_DATA_SCHEMA §7.2).
        $repo->touchObservation((int) $attempt->id, substr($rawStatus, 0, 48), 'redirect_none');
    }

    /**
     * Settle the FluentCart order using CODE_VERIFIED Core APIs only (no Core patching):
     *   - latest charge OrderTransaction lookup: PaymentInstance.php:34-38
     *   - transaction status/vendor_charge_id/meta fields: OrderTransaction.php:36-54
     *   - order meta mirror: Order.php:502-522
     *   - canonical settlement: StatusHelper::syncOrderStatuses() (StatusHelper.php:164-355), which owns the
     *     atomic paid claim (StatusHelper.php:227-249) and dispatches OrderPaid (StatusHelper.php:313-315).
     *
     * @return string settlement outcome code (diagnostic)
     */
    private function settleOrder(int $orderId, string $paymentId, string $mode, string $rawStatus): string
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
            $meta['barion_payment_id'] = $paymentId;
            $meta['barion_status'] = $rawStatus;

            $transaction->vendor_charge_id = $paymentId;
            $transaction->payment_method = self::PROVIDER;
            $transaction->payment_mode = $mode;
            $transaction->meta = $meta;
            $transaction->status = \FluentCart\App\Helpers\Status::TRANSACTION_SUCCEEDED;
            $transaction->save();

            $order->updateMeta('barion_payment_id', $paymentId);

            (new \FluentCart\App\Helpers\StatusHelper($order))->syncOrderStatuses($transaction);

            return 'settled';
        } catch (\Throwable $e) {
            return 'settle_error';
        }
    }

    // ---------------------------------------------------------------- request helpers

    /** @return array<string,mixed> */
    private function requestVars(): array
    {
        if (function_exists('wp_unslash') && isset($_REQUEST) && is_array($_REQUEST)) {
            /** @var array<string,mixed> $unslashed */
            $unslashed = wp_unslash($_REQUEST);
            return $unslashed;
        }
        $vars = [];
        foreach (['paymentId', 'PaymentId'] as $key) {
            if (isset($_REQUEST[$key])) {
                $vars[$key] = $_REQUEST[$key];
            } elseif (isset($_GET[$key])) {
                $vars[$key] = $_GET[$key];
            }
        }
        return $vars;
    }

    private function rawBody(): string
    {
        $body = '';
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $body = (string) file_get_contents('php://input');
        }
        if ($body === '') {
            $body = http_build_query((array) $this->requestVars());
        }
        return $body;
    }

    private function sanitize(string $value): string
    {
        $value = trim($value);
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : $value;
    }
}
