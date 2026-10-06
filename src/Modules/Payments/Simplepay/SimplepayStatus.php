<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Money\Money;

/**
 * SimplePay status/event vocabulary and provider-response validation (04_OTP_SIMPLE_CONTRACT.md §3, §4, §5;
 * SimplePay_2.x_Payment doc §2, §3.14, §3.17).
 *
 * Pure logic only (no WordPress): unit-testable.
 *
 * Statuses (doc §2): INIT, TIMEOUT, CANCELLED, NOTAUTHORIZED, INPAYMENT, INFRAUD, AUTHORIZED, REVERSED,
 * FINISHED. Back events: SUCCESS, FAIL, CANCEL, TIMEOUT. Only FINISHED proves a completed one-step card
 * payment; AUTHORIZED belongs to the (not enabled) two-step flow and must never settle an order on its own.
 */
final class SimplepayStatus
{
    public const PROVIDER = 'simplepay';

    public const STATUS_INIT = 'INIT';
    public const STATUS_TIMEOUT = 'TIMEOUT';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_NOTAUTHORIZED = 'NOTAUTHORIZED';
    public const STATUS_INPAYMENT = 'INPAYMENT';
    public const STATUS_INFRAUD = 'INFRAUD';
    public const STATUS_AUTHORIZED = 'AUTHORIZED';
    public const STATUS_REVERSED = 'REVERSED';
    public const STATUS_FINISHED = 'FINISHED';

    public const EVENT_SUCCESS = 'SUCCESS';
    public const EVENT_FAIL = 'FAIL';
    public const EVENT_CANCEL = 'CANCEL';
    public const EVENT_TIMEOUT = 'TIMEOUT';

    /** Outcomes applied to the attempt mirror by SimplepayAttemptService. */
    public const ACTION_CONFIRM = 'confirmed';
    public const ACTION_REJECT = 'rejected';
    public const ACTION_PENDING = 'pending';
    public const ACTION_IGNORE = 'ignore';

    /** @var list<string> currencies with a documented SimplePay merchant account (doc §4). */
    public const CURRENCIES = ['HUF', 'EUR', 'USD'];

    public static function isSupportedCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    /** FINISHED is the only fulfillable one-step card status (doc §3.14). */
    public static function isFulfillable(string $status): bool
    {
        return strtoupper($status) === self::STATUS_FINISHED;
    }

    /** Terminal no-charge statuses (doc §2/§3.14): no money was taken. */
    public static function isFinalFailure(string $status): bool
    {
        return in_array(strtoupper($status), [
            self::STATUS_TIMEOUT,
            self::STATUS_CANCELLED,
            self::STATUS_NOTAUTHORIZED,
            self::STATUS_REVERSED,
        ], true);
    }

    /** Non-terminal / not-fulfillable statuses (INIT, INPAYMENT, INFRAUD, AUTHORIZED two-step hold). */
    public static function isPending(string $status): bool
    {
        $status = strtoupper($status);
        return $status === self::STATUS_INIT
            || $status === self::STATUS_INPAYMENT
            || $status === self::STATUS_INFRAUD
            || $status === self::STATUS_AUTHORIZED;
    }

    /** Map a provider status to the attempt action (pure). */
    public static function actionFor(string $status): string
    {
        if (self::isFulfillable($status)) {
            return self::ACTION_CONFIRM;
        }
        if (self::isFinalFailure($status)) {
            return self::ACTION_REJECT;
        }
        if (self::isPending($status)) {
            return self::ACTION_PENDING;
        }
        return self::ACTION_IGNORE;
    }

    /**
     * Provider `total` (int/float/string from JSON) -> integer minor units.
     * Money::toMinor rejects floats by design, so floats are formatted per the currency exponent first.
     */
    public static function providerAmountToMinor(mixed $total, string $currency): ?int
    {
        try {
            if (is_int($total)) {
                return Money::toMinor($total, $currency);
            }
            if (is_string($total)) {
                $trimmed = trim($total);
                return $trimmed === '' ? null : Money::toMinor($trimmed, $currency);
            }
            if (is_float($total)) {
                $exp = Money::exponent($currency);
                $pow = 10 ** $exp;
                $scaled = $total * $pow;
                // Reject excess precision instead of silently rounding provider money.
                if (abs($scaled - round($scaled)) > 0.000001) {
                    return null;
                }
                $formatted = number_format(round($scaled) / $pow, $exp, '.', '');
                if ($exp > 0) {
                    // Trim insignificant fraction zeros only ("12.00" -> "12", "12.30" -> "12.3").
                    $formatted = rtrim(rtrim($formatted, '0'), '.');
                }
                return $formatted === '' ? null : Money::toMinor($formatted, $currency);
            }
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return null;
    }

    /**
     * Validate a `query` transaction entry against the attempt (doc §3.17 sample; contract §5 row 4).
     * The documented entry carries merchant, orderRef, total, transactionId, status, resultCode, dates,
     * method — but no currency; the currency binding is the currency-specific merchant account.
     *
     * @param array<string,mixed> $tx     one element of the query response `transactions[]`
     * @param array<string,mixed> $expect {merchant:string, order_ref:string, transaction_id:string,
     *                                    amount_minor:int, currency:string}
     * @return array{ok:bool,reason:string}
     */
    public static function validateQueryTransaction(array $tx, array $expect): array
    {
        $merchant = trim((string) ($tx['merchant'] ?? ''));
        $expectedMerchant = trim((string) ($expect['merchant'] ?? ''));
        if ($expectedMerchant === '' || $merchant === '') {
            return self::fail('merchant_missing');
        }
        if (!hash_equals($expectedMerchant, $merchant)) {
            return self::fail('merchant_mismatch');
        }

        $status = strtoupper(trim((string) ($tx['status'] ?? '')));
        if ($status === '') {
            return self::fail('status_missing');
        }

        $txId = trim((string) ($tx['transactionId'] ?? ''));
        $expectedTxId = trim((string) ($expect['transaction_id'] ?? ''));
        if ($expectedTxId !== '' && $txId !== '' && !hash_equals($expectedTxId, $txId)) {
            return self::fail('transaction_id_mismatch');
        }
        $orderRef = trim((string) ($tx['orderRef'] ?? ''));
        $expectedOrderRef = trim((string) ($expect['order_ref'] ?? ''));
        if ($expectedOrderRef !== '' && $orderRef !== '' && !hash_equals($expectedOrderRef, $orderRef)) {
            return self::fail('order_ref_mismatch');
        }
        if ($txId === '' && $orderRef === '') {
            return self::fail('reference_missing');
        }

        $currency = strtoupper((string) ($expect['currency'] ?? ''));
        if ($currency === '' || !self::isSupportedCurrency($currency)) {
            return self::fail('currency_unsupported');
        }
        if (isset($tx['currency']) && (string) $tx['currency'] !== '' && strtoupper((string) $tx['currency']) !== $currency) {
            return self::fail('currency_mismatch');
        }

        if (!array_key_exists('total', $tx)) {
            return self::fail('amount_missing');
        }
        $minor = self::providerAmountToMinor($tx['total'], $currency);
        if ($minor === null) {
            return self::fail('amount_unparsable');
        }
        if ($minor !== (int) ($expect['amount_minor'] ?? -1)) {
            return self::fail('amount_mismatch');
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Pick the transaction entry matching the attempt reference (transactionId first, then orderRef).
     * With a unique per-attempt orderRef there is normally exactly one; FINISHED wins if several exist.
     *
     * @param array<string,mixed> $response query response body
     * @return array<string,mixed>|null
     */
    public static function selectQueryTransaction(array $response, string $transactionId, string $orderRef): ?array
    {
        $transactions = $response['transactions'] ?? null;
        if (!is_array($transactions)) {
            return null;
        }
        $candidates = [];
        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $txId = trim((string) ($tx['transactionId'] ?? ''));
            $ref = trim((string) ($tx['orderRef'] ?? ''));
            $idMatch = $transactionId !== '' && $txId !== '' && $txId === $transactionId;
            $refMatch = $orderRef !== '' && $ref !== '' && $ref === $orderRef;
            if ($idMatch || $refMatch) {
                $candidates[] = $tx;
            }
        }
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $tx) {
            if (self::isFulfillable((string) ($tx['status'] ?? ''))) {
                return $tx;
            }
        }
        return $candidates[0];
    }

    /** Per-attempt ledger/attempt key. Provider-scoped: Barion owns `fch:{order}:payment_start:{n}`. */
    public static function idempotencyKey(int|string $orderId, int $attemptNo): string
    {
        return sprintf('fch:%s:simplepay:payment_start:%d', (string) $orderId, $attemptNo);
    }

    /** Attempt-scoped merchant reference; unique per attempt so a late success is attributable. */
    public static function orderRef(int|string $orderId, int $attemptNo, ?string $random = null): string
    {
        $random = $random !== null ? preg_replace('/[^a-f0-9]/', '', strtolower($random)) : bin2hex(random_bytes(4));
        if (!is_string($random) || $random === '') {
            $random = bin2hex(random_bytes(4));
        }
        return sprintf('fch-%s-%d-%s', (string) $orderId, $attemptNo, substr($random, 0, 8));
    }

    /** @return array{ok:bool,reason:string} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
