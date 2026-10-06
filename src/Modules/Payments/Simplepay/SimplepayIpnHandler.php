<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Webhooks\WebhookEventRepository;

/**
 * SimplePay IPN ingress for `/?fluent-cart=fct_payment_listener_ipn&method=simplepay`
 * (GlobalPaymentHandler.php:41-67; 04_OTP_SIMPLE_CONTRACT.md §5 row 3).
 *
 * Contract behaviour (doc §3.14):
 *  - The provider POSTs a JSON body and authenticates it with the `Signature` header over the RAW body;
 *    the reply must echo the same fields plus `receiveDate`, signed the same way. A non-200 or malformed
 *    reply counts as "not received" and is retried on the documented schedule.
 *  - The IPN alone is NOT the amount proof (it carries no total/currency): after signature validation the
 *    documented server-side `query` for the transaction supplies the amount/status, and only a matching
 *    `FINISHED` transaction settles the order (contract §3 item 4, §5 row 4).
 *  - Duplicate/replayed IPNs are idempotent; every valid delivery still receives the signed confirmation.
 */
final class SimplepayIpnHandler
{
    private ?SimplepayClient $client;

    private ModuleSettings $settings;

    private SimplepayAttemptService $service;

    private PaymentAttemptRepository $repo;

    public function __construct(
        ?SimplepayClient $client = null,
        ?ModuleSettings $settings = null,
        ?SimplepayAttemptService $service = null,
        ?PaymentAttemptRepository $repo = null
    ) {
        $this->client = $client;
        $this->settings = $settings ?? new ModuleSettings();
        $this->service = $service ?? new SimplepayAttemptService();
        $this->repo = $repo ?? new PaymentAttemptRepository();
    }

    /**
     * @param string|null              $rawBody raw request body (tests inject; default php://input)
     * @param array<string,string>|null $headers request headers (tests inject; default server headers)
     * @return array<string,mixed> {status, reason?, settled?, confirm_body, confirm_signature, http_code}
     */
    public function handle(?string $rawBody = null, ?array $headers = null): array
    {
        $rawBody = $rawBody ?? (string) file_get_contents('php://input');
        $headers = $headers ?? self::headersFromServer($_SERVER ?? []);
        $signature = self::headerValue($headers, 'Signature');

        if ($rawBody === '') {
            return $this->invalid('empty_body');
        }
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return $this->invalid('malformed_json');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(SimplepayStatus::PROVIDER);
        $client = $this->client ?? new SimplepayClient($mode, $account);

        $merchant = trim((string) ($body['merchant'] ?? ''));
        $currency = $client->currencyForMerchant($merchant);
        $webhooks = new WebhookEventRepository();

        $transactionId = trim((string) ($body['transactionId'] ?? ''));
        $status = strtoupper(trim((string) ($body['status'] ?? '')));
        $stamp = (string) ($body['finishDate'] ?? $body['paymentDate'] ?? '');
        $dedup = sprintf('sp:%s:%s:%s', $transactionId !== '' ? $transactionId : 'noid', $status, substr($stamp, 0, 32));

        if ($currency === null) {
            $webhooks->ingest([
                'provider' => SimplepayStatus::PROVIDER,
                'mode' => $mode,
                'account_id' => $account,
                'dedup_key' => $dedup,
                'signature_valid' => false,
                'raw_body' => $rawBody,
                'external_ref' => $transactionId !== '' ? $transactionId : null,
                'event_type' => 'payment_ipn',
            ]);
            return $this->invalid('unknown_merchant');
        }

        $secret = $client->secretFor($currency);
        $valid = SimplepaySignature::verify($rawBody, $signature, $secret);

        $ingest = $webhooks->ingest([
            'provider' => SimplepayStatus::PROVIDER,
            'mode' => $mode,
            'account_id' => $account,
            'dedup_key' => $dedup,
            'signature_valid' => $valid,
            'raw_body' => $rawBody,
            'external_ref' => $transactionId !== '' ? $transactionId : null,
            'event_type' => 'payment_ipn',
        ]);
        $webhookId = (int) $ingest['row']->id;

        if (!$valid) {
            $webhooks->markFailed($webhookId, 'SIGNATURE_INVALID');
            return $this->invalid('signature_invalid');
        }

        $orderRef = trim((string) ($body['orderRef'] ?? ''));
        $attempt = null;
        if ($transactionId !== '') {
            $attempt = $this->repo->findByExternalPaymentId(SimplepayStatus::PROVIDER, $mode, $transactionId);
        }
        if ($attempt === null && $orderRef !== '') {
            $attempt = $this->repo->findByExternalRequestId(SimplepayStatus::PROVIDER, $mode, $orderRef);
        }
        if ($attempt === null) {
            // Valid, signed notification for a transaction we have not committed yet: confirm receipt and
            // leave the row RECEIVED for reconciliation (never guess an order from a merchant reference).
            $webhooks->touchRetry($webhookId, 'attempt_not_found');
            return $this->confirm($body, $secret, ['status' => 'deferred', 'reason' => 'attempt_not_found']);
        }

        if (strtoupper((string) $attempt->currency) !== $currency) {
            $webhooks->markFailed($webhookId, 'MERCHANT_CURRENCY_MISMATCH');
            return $this->confirm($body, $secret, ['status' => 'mismatch', 'reason' => 'merchant_currency_mismatch']);
        }

        $response = $client->query($transactionId !== '' ? [$transactionId] : [], [], $currency);
        if (!$response->isSuccess() || !SimplepayClient::isSignedResponse($response, $secret)) {
            $webhooks->touchRetry($webhookId, 'query_unavailable');
            return $this->confirm($body, $secret, ['status' => 'deferred', 'reason' => 'query_unavailable']);
        }

        $tx = SimplepayStatus::selectQueryTransaction($response->json(), $transactionId, $orderRef);
        if ($tx === null) {
            $webhooks->touchRetry($webhookId, 'query_empty');
            return $this->confirm($body, $secret, ['status' => 'deferred', 'reason' => 'query_empty']);
        }

        $outcome = $this->service->applyQueryOutcome($attempt, $tx, 'signed_ipn', $client->merchantFor($currency));
        if (in_array($outcome['action'], ['mismatch', 'late_conflict'], true)) {
            $webhooks->markFailed($webhookId, 'IPN_' . strtoupper((string) $outcome['action']));
        } else {
            $webhooks->markProcessed($webhookId);
        }

        return $this->confirm($body, $secret, [
            'status' => (string) $outcome['action'],
            'reason' => (string) $outcome['reason'],
            'settled' => (string) $outcome['settled'],
            'attempt_state' => (string) $outcome['state'],
        ]);
    }

    /**
     * Signed confirmation reply: echo the received fields + `receiveDate`, signature over that exact body
     * (doc §3.14). The handler emits this verbatim with HTTP 200.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function confirm(array $body, string $secret, array $meta): array
    {
        $reply = $body;
        $reply['receiveDate'] = SimplepaySignature::receiveDate();
        $confirmBody = SimplepaySignature::encode($reply);
        return array_merge($meta, [
            'confirm_body' => $confirmBody,
            'confirm_signature' => SimplepaySignature::sign($confirmBody, $secret),
            'http_code' => 200,
        ]);
    }

    /** Invalid deliveries get the SDK's "UNSUCCESSFUL VALIDATION" reply; the provider retries. */
    private function invalid(string $reason): array
    {
        return [
            'status' => 'invalid',
            'reason' => $reason,
            'confirm_body' => 'UNSUCCESSFUL VALIDATION',
            'confirm_signature' => 'UNSUCCESSFUL VALIDATION',
            'http_code' => 200,
        ];
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    public static function headersFromServer(array $server): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return array_map('strval', $headers);
            }
        }
        $headers = [];
        foreach ($server as $name => $value) {
            if (str_starts_with((string) $name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $name, 5)))));
                $headers[$key] = (string) $value;
            }
        }
        return $headers;
    }

    /** @param array<string,string> $headers */
    public static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return trim((string) $value);
            }
        }
        return '';
    }
}
