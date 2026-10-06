<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Settings\CredentialStore;

/**
 * Barion refund dispatcher and read-back helpers (W4_EXECUTION_PLAN §2.1).
 *
 * One dispatch only: POST /v2/Payment/Refund with the original PaymentId and the refundable
 * transaction's identifiers; the refund carries a deterministic `Comment` marker derived from our
 * ledger idempotency key, which is also the read-back key after a lost response (Barion documents no
 * lookup-by-comment endpoint, but the payment state lists the transaction's Comment). A missing
 * transaction after a timeout is NEVER a rejection — the outcome stays uncertain.
 *
 * Bound to the ORIGINAL payment: mode/account and POS key are resolved from the ledger row, not from
 * the current settings selection.
 */
final class BarionRefundService
{
    public const REFUNDABLE_TYPES = ['CardPayment', 'Shop', 'BankTransferPayment'];

    private ?BarionClient $client;

    private ?CredentialStore $credentials;

    public function __construct(?BarionClient $client = null, ?CredentialStore $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public static function commentMarker(string $idempotencyKey): string
    {
        return 'fch-refund=' . $idempotencyKey;
    }

    /**
     * Dispatcher for OperationRunner: never retried after an uncertain outcome.
     */
    public function dispatch(object $ledgerRow): ProviderCallResult
    {
        $currency = strtoupper((string) $ledgerRow->currency);
        $amountMinor = (int) $ledgerRow->amount_minor;
        $marker = self::commentMarker((string) $ledgerRow->idempotency_key);
        $paymentId = $this->paymentIdForRow($ledgerRow);
        if ($paymentId === '') {
            return ProviderCallResult::localError('BARION_PAYMENT_ID_MISSING', 'A refundhoz nem található az eredeti Barion PaymentId a rendelésen.');
        }

        $client = $this->clientForRow($ledgerRow);
        if (!$client->hasCredentials()) {
            return ProviderCallResult::localError('BARION_POS_KEY_MISSING', 'A Barion POS kulcs nem érhető el ehhez a módhoz/fiókhoz.');
        }

        $state = $client->getPaymentState($paymentId)->json();
        $transaction = $this->pickRefundableTransaction($state, $amountMinor, $currency);
        if ($transaction === null) {
            return ProviderCallResult::rejected('BARION_NO_REFUNDABLE_TRANSACTION', 'A Barion állapot szerint ezen a fizetésen nincs visszatéríthető tranzakció a kért összegre.', [
                'operation' => 'paymentstate',
                'payment_id' => $paymentId,
            ], ['status' => (string) ($state['Status'] ?? '')]);
        }

        $payload = [
            'PaymentId' => $paymentId,
            'TransactionsToRefund' => [[
                'TransactionId' => (string) $transaction['TransactionId'],
                'POSTransactionId' => (string) ($transaction['POSTransactionId'] ?? ''),
                'AmountToRefund' => Money::fromMinor($amountMinor, $currency),
                'Comment' => $marker,
            ]],
        ];

        $response = $client->refundPayment($payload, [
            // SDK-only idempotency support (04_BARION §5 row 4): harmless when ignored, helpful if honored.
            'Idempotency-Key' => (string) $ledgerRow->idempotency_key,
        ]);
        $body = $response->json();
        $requestSummary = [
            'operation' => 'refund',
            'payment_id' => $paymentId,
            'transaction_id' => (string) $transaction['TransactionId'],
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'marker' => $marker,
        ];

        if ($response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $requestSummary, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('PROVIDER_5XX', $requestSummary, ['status' => $response->status]);
        }
        if ($response->status >= 400) {
            $errors = (array) ($body['Errors'] ?? []);
            $first = (array) ($errors[0] ?? []);
            $code = (string) ($first['ErrorCode'] ?? ('HTTP_' . $response->status));
            $title = (string) ($first['Title'] ?? '');
            if ($response->status === 401 || $response->status === 403 || stripos($code . ' ' . $title, 'AuthenticationFailed') !== false) {
                return ProviderCallResult::localError($code, $title !== '' ? $title : 'Barion hitelesítési hiba.', $requestSummary);
            }
            return ProviderCallResult::rejected($code, $title !== '' ? $title : 'A Barion elutasította a visszatérítést.', $requestSummary, ['status' => $response->status, 'errors' => $errors]);
        }

        $refunded = (array) ($body['RefundedTransactions'] ?? []);
        $refundId = '';
        foreach ($refunded as $item) {
            $id = trim((string) (((array) $item)['TransactionId'] ?? ''));
            if ($id !== '') {
                $refundId = $id;
                break;
            }
        }
        if ($refundId === '') {
            return ProviderCallResult::uncertain('ACCEPTED_WITHOUT_REFUND_ID', $requestSummary, ['status' => $response->status]);
        }

        return ProviderCallResult::success([
            'external_id' => $refundId,
            'external_ref2' => $marker,
            'business_reference' => (string) $ledgerRow->business_reference,
        ], $requestSummary, ['status' => $response->status, 'refunded_transactions' => count($refunded)]);
    }

    /**
     * Read-back for an UNCERTAIN refund: proves the refund only when the payment state shows a transaction
     * carrying our exact Comment marker. A negative lookup stays UNCERTAIN (never a rejection) because no
     * Barion API guarantee about immediate visibility exists (W4_EXECUTION_PLAN §5).
     */
    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        $paymentId = $this->paymentIdForRow($ledgerRow);
        if ($paymentId === '') {
            return ProviderCallResult::uncertain('BARION_PAYMENT_ID_MISSING');
        }
        $client = $this->clientForRow($ledgerRow);
        if (!$client->hasCredentials()) {
            return ProviderCallResult::uncertain('BARION_POS_KEY_MISSING');
        }
        $response = $client->getPaymentState($paymentId);
        if ($response->status === 0) {
            return ProviderCallResult::uncertain('STATE_TIMEOUT');
        }
        if ($response->status >= 500 || $response->status >= 400) {
            return ProviderCallResult::uncertain('STATE_UNAVAILABLE:' . $response->status);
        }
        $state = $response->json();
        $marker = self::commentMarker((string) $ledgerRow->idempotency_key);
        $matches = [];
        foreach ((array) ($state['Transactions'] ?? []) as $txn) {
            $txn = (array) $txn;
            if ((string) ($txn['Comment'] ?? '') === $marker) {
                $matches[] = $txn;
            }
        }
        if (count($matches) === 1) {
            $match = $matches[0];
            $status = (string) ($match['Status'] ?? '');
            if (BarionStatus::isSuccess($status) || in_array($status, ['Succeeded', 'PartiallySucceeded'], true)) {
                return ProviderCallResult::success([
                    'external_id' => (string) ($match['TransactionId'] ?? ''),
                    'external_ref2' => $marker,
                    'reconciled_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            return ProviderCallResult::uncertain('REFUND_STATUS:' . $status);
        }
        if (count($matches) > 1) {
            return ProviderCallResult::uncertain('AMBIGUOUS_MARKER_MATCH');
        }
        return ProviderCallResult::uncertain('REFUND_NOT_VISIBLE_YET');
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed>|null */
    public function pickRefundableTransaction(array $state, int $amountMinor, string $currency): ?array
    {
        foreach ((array) ($state['Transactions'] ?? []) as $txn) {
            $txn = (array) $txn;
            $type = (string) ($txn['TransactionType'] ?? '');
            $status = (string) ($txn['Status'] ?? '');
            if (!in_array($type, self::REFUNDABLE_TYPES, true)) {
                continue;
            }
            if (!BarionStatus::isSuccess($status)) {
                continue;
            }
            $total = BarionStatus::amountMinorFromProvider($txn['Total'] ?? null, $currency);
            if ($total === null || $total < $amountMinor) {
                continue;
            }
            if (trim((string) ($txn['TransactionId'] ?? '')) === '') {
                continue;
            }
            return $txn;
        }
        return null;
    }

    private function paymentIdForRow(object $ledgerRow): string
    {
        $parentTxnId = (int) ($ledgerRow->business_reference ?? 0);
        if ($parentTxnId > 0 && class_exists('\FluentCart\App\Models\OrderTransaction')) {
            try {
                $txn = \FluentCart\App\Models\OrderTransaction::query()->find($parentTxnId);
                if ($txn !== null) {
                    $vendor = trim((string) ($txn->vendor_charge_id ?? ''));
                    if ($vendor !== '') {
                        return $vendor;
                    }
                    $meta = is_array($txn->meta ?? null) ? $txn->meta : [];
                    $metaPayment = trim((string) ($meta['barion_payment_id'] ?? ''));
                    if ($metaPayment !== '') {
                        return $metaPayment;
                    }
                }
            } catch (\Throwable $e) {
                // fall through to the ledger lookup
            }
        }
        global $wpdb;
        $fromLedger = $wpdb->get_var($wpdb->prepare(
            "SELECT external_id FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND provider = 'barion' AND kind = 'payment_start' AND state = %s ORDER BY id DESC LIMIT 1",
            (int) $ledgerRow->order_id,
            ExternalOperation::EXTERNAL_CONFIRMED
        ));
        return trim((string) $fromLedger);
    }

    private function clientForRow(object $ledgerRow): BarionClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $mode = (string) $ledgerRow->mode === 'live' ? 'live' : 'test';
        $account = (string) $ledgerRow->account_id;
        if ($account !== '') {
            // Explicit account: a missing secret is a local error, NEVER a silent fallback to the
            // currently selected account's key (binding fidelity, goal §3).
            $key = ($this->credentials ?? new CredentialStore())->get('barion', $mode, $account, 'pos_key');
            return new BarionClient($mode, is_string($key) && trim($key) !== '' ? $key : '');
        }
        return new BarionClient($mode);
    }
}
