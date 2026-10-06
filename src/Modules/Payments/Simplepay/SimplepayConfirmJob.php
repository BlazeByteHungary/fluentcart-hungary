<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Jobs\JobInterface;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;

/**
 * Async SimplePay confirmation job (hook `fch_simplepay_confirm`).
 *
 * Enqueued by the browser-return handler and by recovery paths when an attempt needs the documented
 * server-side reconciliation (`POST /v2/query`) but it cannot be run inline (query failure, unknown
 * attempt, IPN without attempt). Throwing on transport failure lets JobRunner retry with backoff; once
 * the query succeeds the outcome is applied idempotently.
 */
final class SimplepayConfirmJob implements JobInterface
{
    public const HOOK = 'fch_simplepay_confirm';

    public function hook(): string
    {
        return self::HOOK;
    }

    /** @param array<string,mixed> $args */
    public function handle(array $args): void
    {
        $attemptId = (int) ($args['attempt_id'] ?? ($args['id'] ?? 0));
        if ($attemptId <= 0) {
            throw new \InvalidArgumentException('attempt_id is required for ' . self::HOOK . '.');
        }

        $repo = new PaymentAttemptRepository();
        $attempt = $repo->getById($attemptId);
        if ($attempt === null || (string) $attempt->provider !== SimplepayStatus::PROVIDER) {
            return; // nothing to confirm
        }

        $mode = (string) $attempt->mode;
        $account = (string) $attempt->account_id;
        $currency = strtoupper((string) $attempt->currency);
        $client = new SimplepayClient($mode, $account);
        if (!$client->hasCredentialsFor($currency)) {
            throw new \RuntimeException('simplepay_confirm_credentials_missing');
        }

        $transactionId = trim((string) ($attempt->external_payment_id ?? ''));
        $orderRef = trim((string) ($attempt->external_request_id ?? ''));
        $response = $client->query(
            $transactionId !== '' ? [$transactionId] : [],
            $orderRef !== '' ? [$orderRef] : [],
            $currency
        );
        if (!$response->isSuccess() || !SimplepayClient::isSignedResponse($response, $client->secretFor($currency))) {
            throw new \RuntimeException('simplepay_confirm_query_failed');
        }

        $tx = SimplepayStatus::selectQueryTransaction($response->json(), $transactionId, $orderRef);
        $service = new SimplepayAttemptService($repo);
        if ($tx === null) {
            $service->rejectAttempt($attempt, 'NO_TRANSACTION', 'query');
            return;
        }

        $service->applyQueryOutcome($attempt, $tx, 'query', $client->merchantFor($currency));
    }
}
