<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Webhooks\WebhookEventRepository;

/**
 * SimplePay browser-back endpoint (doc §3.12; 04_OTP_SIMPLE_CONTRACT.md §5 row 2).
 *
 * `url` in `/v2/start` points here (`?fch_simplepay_return=1`). The handler:
 *  1. validates the `r` (base64 JSON) + `s` (signature over the decoded JSON string) pair,
 *  2. records the event (SUCCESS/FAIL/CANCEL/TIMEOUT) in `fch_webhook_events` with a dedup key,
 *  3. enqueues the documented server-side reconciliation (`fch_simplepay_confirm` query job) — the browser
 *     redirect ALONE never settles an order (invariant §1.1),
 *  4. redirects the shopper back to the FluentCart order-received URL.
 */
final class SimplepayReturnHandler
{
    public const QUERY_VAR = 'fch_simplepay_return';

    private ?SimplepayClient $client;

    private ModuleSettings $settings;

    /** @var callable(int):void|null */
    private $enqueueConfirm;

    /**
     * @param callable(int):void|null $enqueueConfirm test seam; defaults to the real job enqueue
     */
    public function __construct(?SimplepayClient $client = null, ?ModuleSettings $settings = null, ?callable $enqueueConfirm = null)
    {
        $this->client = $client;
        $this->settings = $settings ?? new ModuleSettings();
        $this->enqueueConfirm = $enqueueConfirm;
    }

    /**
     * Process a browser return. Never emits/redirects (testable); `dispatch()` wraps this for HTTP.
     *
     * @param array<string,mixed>|null $query defaults to $_GET
     * @return array<string,mixed> {status, reason?, order_id?, redirect_url}
     */
    public function handle(?array $query = null): array
    {
        $query = $query ?? ($_GET ?? []);
        $r = isset($query['r']) ? (string) $query['r'] : '';
        $s = isset($query['s']) ? (string) $query['s'] : '';
        if ($r === '' || $s === '') {
            return ['status' => 'ignored', 'reason' => 'missing_r_s', 'redirect_url' => $this->homeUrl()];
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(SimplepayStatus::PROVIDER);
        $client = $this->client ?? new SimplepayClient($mode, $account);

        // Resolve the currency by the merchant in the signed payload; without a known merchant the
        // signature cannot be verified at all.
        $json = SimplepaySignature::decodeBack($r);
        if ($json === null) {
            return ['status' => 'invalid', 'reason' => 'malformed_r', 'redirect_url' => $this->homeUrl()];
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return ['status' => 'invalid', 'reason' => 'malformed_r_json', 'redirect_url' => $this->homeUrl()];
        }

        $merchant = trim((string) ($payload['m'] ?? ''));
        $currency = $client->currencyForMerchant($merchant);
        if ($currency === null) {
            return ['status' => 'invalid', 'reason' => 'unknown_merchant', 'redirect_url' => $this->homeUrl()];
        }

        $valid = SimplepaySignature::verify($json, $s, $client->secretFor($currency));
        $event = strtoupper(trim((string) ($payload['e'] ?? '')));
        $transactionId = trim((string) ($payload['t'] ?? ''));
        $orderRef = trim((string) ($payload['o'] ?? ''));

        $webhooks = new WebhookEventRepository();
        $ingest = $webhooks->ingest([
            'provider' => SimplepayStatus::PROVIDER,
            'mode' => $mode,
            'account_id' => $account,
            'dedup_key' => sprintf('spb:%s:%s:%s', $transactionId !== '' ? $transactionId : 'noid', $event, $orderRef),
            'signature_valid' => $valid,
            'raw_body' => $json,
            'external_ref' => $transactionId !== '' ? $transactionId : null,
            'event_type' => 'browser_back',
        ]);
        $webhookId = (int) $ingest['row']->id;

        if (!$valid) {
            $webhooks->markFailed($webhookId, 'SIGNATURE_INVALID');
            return ['status' => 'invalid', 'reason' => 'signature_invalid', 'redirect_url' => $this->homeUrl()];
        }

        $repo = new PaymentAttemptRepository();
        $attempt = null;
        if ($orderRef !== '') {
            $attempt = $repo->findByExternalRequestId(SimplepayStatus::PROVIDER, $mode, $orderRef);
        }
        if ($attempt === null && $transactionId !== '') {
            $attempt = $repo->findByExternalPaymentId(SimplepayStatus::PROVIDER, $mode, $transactionId);
        }
        if ($attempt === null) {
            $webhooks->touchRetry($webhookId, 'attempt_not_found');
            return ['status' => 'deferred', 'reason' => 'attempt_not_found', 'redirect_url' => $this->homeUrl()];
        }

        if (strtoupper((string) $attempt->currency) !== $currency) {
            $webhooks->markFailed($webhookId, 'MERCHANT_CURRENCY_MISMATCH');
            return ['status' => 'mismatch', 'reason' => 'merchant_currency_mismatch', 'redirect_url' => $this->orderUrl($attempt)];
        }

        // The browser event is UX information only; confirmation runs through the query job.
        $webhooks->markProcessed($webhookId);
        $this->enqueueConfirm((int) $attempt->id);

        return [
            'status' => 'scheduled',
            'reason' => $event !== '' ? $event : 'unknown_event',
            'order_id' => (int) $attempt->order_id,
            'redirect_url' => $this->orderUrl($attempt),
        ];
    }

    /** HTTP entry point: process + redirect (used by the `init` hook registration). */
    public function dispatch(): void
    {
        $result = $this->handle();
        $url = (string) ($result['redirect_url'] ?? $this->homeUrl());
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
        } elseif (function_exists('header')) {
            header('Location: ' . $url);
        }
        exit;
    }

    private function enqueueConfirm(int $attemptId): void
    {
        if (is_callable($this->enqueueConfirm)) {
            ($this->enqueueConfirm)($attemptId);
            return;
        }
        JobRunner::instance()->enqueue(new JobSpec(
            sprintf('fch:simplepay:confirm:%d', $attemptId),
            SimplepayConfirmJob::HOOK,
            ['attempt_id' => $attemptId],
            10,
            5,
            SimplepayStatus::PROVIDER
        ));
    }

    /** @param object $attempt */
    private function orderUrl(object $attempt): string
    {
        $orderId = (int) $attempt->order_id;
        if ($orderId > 0 && class_exists('\FluentCart\App\Models\OrderTransaction')) {
            try {
                $transaction = \FluentCart\App\Models\OrderTransaction::query()
                    ->where('order_id', $orderId)
                    ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE)
                    ->orderBy('id', 'desc')
                    ->first();
                if ($transaction && method_exists($transaction, 'getSuccessUrl')) {
                    $url = (string) $transaction->getSuccessUrl();
                    if ($url !== '') {
                        return $url;
                    }
                }
            } catch (\Throwable $e) {
                // fall through to home
            }
        }
        return $this->homeUrl();
    }

    private function homeUrl(): string
    {
        return function_exists('home_url') ? (string) home_url('/') : '/';
    }
}
