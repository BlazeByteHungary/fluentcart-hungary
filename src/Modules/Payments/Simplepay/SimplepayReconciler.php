<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * SimplePay reconciler for UNCERTAIN payment starts / late callbacks (05_DATA_SCHEMA §7.2; invariant §1.4;
 * 04_OTP_SIMPLE_CONTRACT.md §6).
 *
 * Proof comes exclusively from the documented read-only `POST /v2/query` by `transactionId`/`orderRef`;
 * this class NEVER re-dispatches `/v2/start`. The API has no idempotency key (contract §10 item 2), so a
 * lost-response start is resolved by reference, not by blind retry. A query that returns zero matching
 * transactions is authoritative (no transaction exists for the reference) and rejects the attempt;
 * a query failure leaves the row for a later run.
 *
 * Registered via ReconcilerRegistry::register('simplepay', [$reconciler, 'reconcile']);
 * signature: callable(object $ledgerRow): ProviderCallResult.
 */
final class SimplepayReconciler
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

    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        // Refund rows use the refund read-back (query refunds=true) and never resolve negatively to a
        // rejection (W4_EXECUTION_PLAN §2.2/§5) — unlike a start reference, a missing refund listing is
        // not proof that the refund was refused.
        if ((string) ($ledgerRow->kind ?? '') === 'refund') {
            return (new SimplepayRefundService(null, null))->reconcile($ledgerRow);
        }

        $mode = (string) ($ledgerRow->mode ?? $this->settings->mode());
        $mode = $mode === 'live' ? 'live' : 'test';
        $account = (string) ($ledgerRow->account_id ?? $this->settings->accountId(SimplepayStatus::PROVIDER));
        $transactionId = trim((string) ($ledgerRow->external_id ?? ''));
        $orderRef = trim((string) ($ledgerRow->business_reference ?? ''));

        $attempt = null;
        if ($transactionId !== '') {
            $attempt = $this->repo->findByExternalPaymentId(SimplepayStatus::PROVIDER, $mode, $transactionId);
        }
        if ($attempt === null && $orderRef !== '') {
            $attempt = $this->repo->findByExternalRequestId(SimplepayStatus::PROVIDER, $mode, $orderRef);
        }
        if ($attempt === null) {
            return ProviderCallResult::uncertain('SIMPLEPAY_ATTEMPT_NOT_FOUND');
        }

        $orderRef = $orderRef !== '' ? $orderRef : (string) ($attempt->external_request_id ?? '');
        $transactionId = $transactionId !== '' ? $transactionId : (string) ($attempt->external_payment_id ?? '');
        $currency = strtoupper((string) $attempt->currency);

        $client = $this->client ?? new SimplepayClient($mode, $account);
        if (!$client->hasCredentialsFor($currency)) {
            return ProviderCallResult::uncertain('SIMPLEPAY_CREDENTIALS_MISSING');
        }

        $response = $client->query(
            $transactionId !== '' ? [$transactionId] : [],
            $orderRef !== '' ? [$orderRef] : [],
            $currency
        );
        if (!$response->isSuccess()) {
            return ProviderCallResult::uncertain('SIMPLEPAY_RECONCILE_QUERY_FAILED');
        }
        if (!SimplepayClient::isSignedResponse($response, $client->secretFor($currency))) {
            return ProviderCallResult::uncertain('SIMPLEPAY_QUERY_SIGNATURE_INVALID');
        }

        $body = $response->json();
        $tx = SimplepayStatus::selectQueryTransaction($body, $transactionId, $orderRef);
        if ($tx === null) {
            // Authoritative zero-result: no transaction exists for this reference (contract §6/§10.2).
            $this->service->rejectAttempt($attempt, 'NO_TRANSACTION', 'query');
            return ProviderCallResult::rejected(
                'SIMPLEPAY_NO_TRANSACTION',
                'No SimplePay transaction found for the attempt reference.'
            );
        }

        $outcome = $this->service->applyQueryOutcome($attempt, $tx, 'query', $client->merchantFor($currency));
        $rawStatus = strtoupper((string) ($tx['status'] ?? ''));

        switch ($outcome['action']) {
            case 'confirmed':
                return ProviderCallResult::success([
                    'external_id' => (string) ($tx['transactionId'] ?? $transactionId),
                    'external_ref2' => $rawStatus,
                    'business_reference' => (string) ($attempt->external_request_id ?? $orderRef),
                ], ['query' => $transactionId !== '' ? $transactionId : $orderRef], ['status' => $rawStatus]);
            case 'rejected':
                return ProviderCallResult::rejected(
                    'SIMPLEPAY_' . ($rawStatus !== '' ? $rawStatus : 'REJECTED'),
                    'SimplePay transaction is ' . ($rawStatus !== '' ? $rawStatus : 'rejected') . '.'
                );
            case 'mismatch':
                return ProviderCallResult::uncertain('SIMPLEPAY_QUERY_MISMATCH_' . strtoupper((string) $outcome['reason']));
            case 'late_conflict':
                return ProviderCallResult::uncertain('SIMPLEPAY_TERMINAL_CONFLICT');
            default:
                return ProviderCallResult::uncertain('SIMPLEPAY_STILL_' . ($rawStatus !== '' ? $rawStatus : 'UNKNOWN'));
        }
    }
}
