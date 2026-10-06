<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Settings\CredentialStore;

/**
 * SimplePay refund dispatcher and read-back helpers (W4_EXECUTION_PLAN §2.2; OpenAPI 2.1.0 `/v2/refund`).
 *
 * SimplePay has NO idempotency key on refunds: we dispatch once, persist the accepted
 * `refundTransactionId`, and resolve a lost response exclusively through `query(refunds: true)`.
 * One accepted refund is one linked provider transaction — a negative/ambiguous read-back stays
 * UNCERTAIN with the original identity, never a rejection. `transactionCancel` is a different
 * operation (INIT only) and is never used as a refund.
 *
 * Bound to the ORIGINAL payment: mode/account and per-currency merchant/secret come from the ledger row.
 */
final class SimplepayRefundService
{
    /** Codes that denote configuration/auth problems rather than a refund refusal (condensed list). */
    public const LOCAL_ERROR_CODES = [999, 2019, 2020, 2021, 3004, 1529];

    private ?SimplepayClient $client;

    private ?CredentialStore $credentials;

    public function __construct(?SimplepayClient $client = null, ?CredentialStore $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function dispatch(object $ledgerRow): ProviderCallResult
    {
        $currency = strtoupper((string) $ledgerRow->currency);
        $amountMinor = (int) $ledgerRow->amount_minor;
        $client = $this->clientForRow($ledgerRow);
        if (!$client->hasCredentialsFor($currency)) {
            return ProviderCallResult::localError('SIMPLEPAY_CREDENTIALS_MISSING', 'Ehhez a pénznemhez nincs SimplePay merchant/secret a refund módjához és fiókjához.');
        }

        $original = $this->originalReference($ledgerRow);
        if ($original['transaction_id'] === '' && $original['order_ref'] === '') {
            return ProviderCallResult::localError('SIMPLEPAY_ORIGINAL_ID_MISSING', 'A refundhoz nem található az eredeti SimplePay tranzakció azonosítója.');
        }

        $payload = [
            'salt' => SimplepaySignature::salt(),
            'merchant' => $client->merchantFor($currency),
            'sdkVersion' => SimplepaySignature::sdkVersion(),
            'currency' => $currency,
            'refundTotal' => Money::fromMinor($amountMinor, $currency),
        ];
        if ($original['transaction_id'] !== '') {
            $payload['transactionId'] = $original['transaction_id'];
        } else {
            $payload['orderRef'] = $original['order_ref'];
        }

        $response = $client->refund($payload, $currency);
        $body = $response->json();
        $requestSummary = [
            'operation' => 'refund',
            'transaction_id' => $original['transaction_id'],
            'order_ref' => $original['order_ref'],
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ];

        if ($response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $requestSummary, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('PROVIDER_5XX', $requestSummary, ['status' => $response->status]);
        }

        $errorCodes = array_map('intval', (array) ($body['errorCodes'] ?? []));
        if ($response->status >= 400 || $errorCodes !== []) {
            $localOnly = $errorCodes !== [] && !array_diff($errorCodes, self::LOCAL_ERROR_CODES);
            if ($localOnly) {
                return ProviderCallResult::localError('SIMPLEPAY_' . implode('_', $errorCodes), 'SimplePay hitelesítési/konfigurációs hiba a visszatérítésnél.', $requestSummary);
            }
            return ProviderCallResult::rejected('SIMPLEPAY_' . implode('_', $errorCodes), 'A SimplePay elutasította a visszatérítést.', $requestSummary, ['status' => $response->status, 'error_codes' => $errorCodes]);
        }

        $refundId = (int) ($body['refundTransactionId'] ?? 0);
        if ($refundId <= 0) {
            return ProviderCallResult::uncertain('ACCEPTED_WITHOUT_REFUND_ID', $requestSummary, ['status' => $response->status]);
        }
        $remaining = SimplepayStatus::providerAmountToMinor($body['remainingTotal'] ?? null, $currency);

        return ProviderCallResult::success([
            'external_id' => (string) $refundId,
            'external_ref2' => $remaining === null ? '' : (string) $remaining,
            'business_reference' => (string) $ledgerRow->business_reference,
        ], $requestSummary, ['status' => $response->status, 'remaining_total_minor' => $remaining]);
    }

    /**
     * Read-back via `/v2/query` `refunds=true`. Matching never relies on the amount alone: a stored
     * refundTransactionId is authoritative; otherwise the candidate must be unique (amount + after
     * dispatch + not claimed by another operation), else the outcome stays uncertain.
     */
    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        $currency = strtoupper((string) $ledgerRow->currency);
        $amountMinor = (int) $ledgerRow->amount_minor;
        $client = $this->clientForRow($ledgerRow);
        if (!$client->hasCredentialsFor($currency)) {
            return ProviderCallResult::uncertain('SIMPLEPAY_CREDENTIALS_MISSING');
        }
        $original = $this->originalReference($ledgerRow);
        $transactions = [];
        $orderRefs = [];
        if ($original['transaction_id'] !== '') {
            $transactions[] = $original['transaction_id'];
        } else {
            $orderRefs[] = $original['order_ref'];
        }

        $response = $client->query($transactions, $orderRefs, $currency, true);
        if ($response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT');
        }
        $body = $response->json();
        if ($response->status >= 400 || !empty($body['errorCodes'])) {
            return ProviderCallResult::uncertain('QUERY_FAILED:' . $response->status);
        }

        $refunds = [];
        foreach ((array) ($body['transactions'] ?? []) as $txn) {
            foreach ((array) (((array) $txn)['refunds'] ?? []) as $refund) {
                $refunds[] = (array) $refund;
            }
        }
        if ($refunds === []) {
            return ProviderCallResult::uncertain('REFUND_NOT_VISIBLE_YET');
        }

        $storedId = trim((string) ($ledgerRow->external_id ?? ''));
        if ($storedId !== '') {
            foreach ($refunds as $refund) {
                if ((string) ((int) ($refund['transactionId'] ?? 0)) === $storedId) {
                    return $this->outcomeForRefund($refund, $currency, $amountMinor, true);
                }
            }
            return ProviderCallResult::uncertain('STORED_REFUND_NOT_VISIBLE_YET');
        }

        // No stored id (lost response): accept only a unique, unclaimed, after-dispatch candidate.
        $dispatchedAt = strtotime((string) ($ledgerRow->created_at ?? '')) ?: (time() - 300);
        $candidates = [];
        foreach ($refunds as $refund) {
            $refundMinor = SimplepayStatus::providerAmountToMinor($refund['refundTotal'] ?? null, $currency);
            if ($refundMinor !== $amountMinor) {
                continue;
            }
            $refundDate = strtotime((string) ($refund['refundDate'] ?? ''));
            // Wall-clock comparison across the provider and our DB defaults must tolerate timezone
            // skew (<= 14 h): a 25 h lookback still excludes refunds from earlier days, while the
            // uniqueness requirement carries the real dedup burden.
            if ($refundDate !== false && $refundDate < $dispatchedAt - 90000) {
                continue; // clearly older than our dispatch window
            }
            if ($this->claimedByOtherOperation((int) ($refund['transactionId'] ?? 0), (int) $ledgerRow->id)) {
                continue;
            }
            $candidates[] = $refund;
        }
        if (count($candidates) === 1) {
            return $this->outcomeForRefund($candidates[0], $currency, $amountMinor, false);
        }
        if (count($candidates) > 1) {
            return ProviderCallResult::uncertain('AMBIGUOUS_REFUND_MATCH');
        }
        return ProviderCallResult::uncertain('REFUND_NOT_VISIBLE_YET');
    }

    // ------------------------------------------------------------------ internals

    private function outcomeForRefund(array $refund, string $currency, int $amountMinor, bool $storedId): ProviderCallResult
    {
        $status = strtoupper((string) ($refund['status'] ?? ''));
        $refundMinor = SimplepayStatus::providerAmountToMinor($refund['refundTotal'] ?? null, $currency);
        if ($refundMinor !== $amountMinor) {
            return ProviderCallResult::uncertain('REFUND_AMOUNT_MISMATCH');
        }
        if ($status !== '' && !SimplepayStatus::isFulfillable($status)) {
            // Card refunds settle synchronously as FINISHED; anything not final yet stays uncertain.
            if (in_array($status, [SimplepayStatus::STATUS_INIT, 'AUTHORIZED', 'INPAYMENT'], true)) {
                return ProviderCallResult::uncertain('REFUND_STATUS:' . $status);
            }
            return ProviderCallResult::uncertain('REFUND_STATUS:' . $status);
        }
        return ProviderCallResult::success([
            'external_id' => (string) ((int) ($refund['transactionId'] ?? 0)),
            'external_ref2' => $storedId ? (string) ($refund['status'] ?? '') : 'readback',
            'reconciled_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function claimedByOtherOperation(int $refundTransactionId, int $ledgerId): bool
    {
        if ($refundTransactionId <= 0) {
            return true;
        }
        global $wpdb;
        $other = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fch_external_operations WHERE kind = 'refund' AND external_id = %s AND id <> %d",
            (string) $refundTransactionId,
            $ledgerId
        ));
        return $other > 0;
    }

    /** @return array{transaction_id:string,order_ref:string} */
    private function originalReference(object $ledgerRow): array
    {
        $transactionId = '';
        $orderRef = '';
        $parentTxnId = (int) ($ledgerRow->business_reference ?? 0);
        if ($parentTxnId > 0 && class_exists('\FluentCart\App\Models\OrderTransaction')) {
            try {
                $txn = \FluentCart\App\Models\OrderTransaction::query()->find($parentTxnId);
                if ($txn !== null) {
                    $vendor = trim((string) ($txn->vendor_charge_id ?? ''));
                    if ($vendor !== '' && ctype_digit($vendor)) {
                        $transactionId = $vendor;
                    }
                    $meta = is_array($txn->meta ?? null) ? $txn->meta : [];
                    $orderRef = trim((string) ($meta['simplepay_order_ref'] ?? ''));
                }
            } catch (\Throwable $e) {
                // fall through to the ledger lookup
            }
        }
        if ($transactionId === '' && $orderRef === '') {
            global $wpdb;
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT external_id, business_reference FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND provider = 'simplepay' AND kind = 'payment_start' AND state = %s ORDER BY id DESC LIMIT 1",
                (int) $ledgerRow->order_id,
                ExternalOperation::EXTERNAL_CONFIRMED
            ));
            if ($payment !== null) {
                $vendor = trim((string) $payment->external_id);
                if ($vendor !== '' && ctype_digit($vendor)) {
                    $transactionId = $vendor;
                }
                $orderRef = trim((string) ($payment->business_reference ?? ''));
            }
        }
        return ['transaction_id' => $transactionId, 'order_ref' => $orderRef];
    }

    private function clientForRow(object $ledgerRow): SimplepayClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $mode = (string) $ledgerRow->mode === 'live' ? 'live' : 'test';
        $account = (string) $ledgerRow->account_id;
        $overrides = null;
        if ($account !== '' && $this->credentials !== null) {
            $overrides = [];
            foreach (SimplepayStatus::CURRENCIES as $currency) {
                $lower = strtolower($currency);
                $merchant = $this->credentials->get('simplepay', $mode, $account, 'merchant_' . $lower);
                $secret = $this->credentials->get('simplepay', $mode, $account, 'secret_key_' . $lower);
                if (is_string($merchant) && trim($merchant) !== '' && is_string($secret) && trim($secret) !== '') {
                    $overrides[$currency] = ['merchant' => trim($merchant), 'secret' => trim($secret)];
                }
            }
        }
        return new SimplepayClient($mode, $account !== '' ? $account : null, $overrides);
    }
}
