<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Money\Money;

/**
 * Pure Barion status mapping, amount rules and callback validation (no WordPress, no HTTP).
 *
 * Provider facts (04_BARION_CONTRACT.md):
 * - status enum §10 ("Exact status enum"): Prepared(10), Started(20), InProgress(21), Waiting(22),
 *   Reserved(25), Authorized(26), Canceled(30), Succeeded(40), Failed(50), PartiallySucceeded(60),
 *   Expired(70).
 * - the shop must compare Total/Currency itself; the provider does not (§3 item 4; §4).
 * - "no signature; verify via status API" (§3 item 7; §5 row 11).
 * - HUF = 0 decimals; minimum amounts HUF 10 / EUR 0.1 / USD 0.1 / CZK 1 (§4).
 * - money is integer minor units, no floats (design constraints §2; ADR-007).
 *
 * The mapping is the single source of truth for BarionGateway/BarionIpnHandler/BarionReconciler.
 */
final class BarionStatus
{
    /** Our flow buckets (mirror 05_DATA_SCHEMA §7.2): success => EXTERNAL_CONFIRMED, failed => PROVEN_REJECTED. */
    public const SUCCESS = 'success';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    /** Raw provider status tokens (04_BARION §10). */
    public const PREPARED = 'Prepared';
    public const STARTED = 'Started';
    public const IN_PROGRESS = 'InProgress';
    public const WAITING = 'Waiting';
    public const RESERVED = 'Reserved';
    public const AUTHORIZED = 'Authorized';
    public const CANCELED = 'Canceled';
    public const SUCCEEDED = 'Succeeded';
    public const FAILED_STATUS = 'Failed';
    public const PARTIALLY_SUCCEEDED = 'PartiallySucceeded';
    public const EXPIRED = 'Expired';

    /** @var array<string,string> raw provider status => flow bucket */
    private const MAP = [
        self::PREPARED => self::PENDING,
        self::STARTED => self::PENDING,
        self::IN_PROGRESS => self::PENDING,
        self::WAITING => self::PENDING,
        self::RESERVED => self::PENDING,
        self::AUTHORIZED => self::PENDING,
        self::SUCCEEDED => self::SUCCESS,
        // Canceled/Expired/Failed are terminal failures; PartiallySucceeded is treated as a failure for
        // settlement because the ordered amount was not fully paid (04_BARION §4: Total may be lower).
        self::CANCELED => self::FAILED,
        self::FAILED_STATUS => self::FAILED,
        self::EXPIRED => self::FAILED,
        self::PARTIALLY_SUCCEEDED => self::FAILED,
    ];

    /** Minimum payment amount per currency in integer minor units (04_BARION §4). */
    private const MINIMUM_MINOR = [
        'HUF' => 10,   // 10 Ft, exponent 0
        'EUR' => 10,   // 0.10 EUR, exponent 2
        'USD' => 10,   // 0.10 USD, exponent 2
        'CZK' => 100,  // 1 CZK, exponent 2
    ];

    /** @return array<string,string> raw status => flow bucket */
    public static function mapAll(): array
    {
        return self::MAP;
    }

    /** @return list<string> */
    public static function knownStatuses(): array
    {
        return array_keys(self::MAP);
    }

    /** Map a raw provider status token to our flow bucket; unknown tokens stay UNKNOWN (never guessed). */
    public static function map(string $status): string
    {
        return self::MAP[trim($status)] ?? self::UNKNOWN;
    }

    public static function isSuccess(string $status): bool
    {
        return self::map($status) === self::SUCCESS;
    }

    public static function isPending(string $status): bool
    {
        return self::map($status) === self::PENDING;
    }

    /** Canceled / Expired / Failed / PartiallySucceeded are terminal non-success outcomes (04_BARION §10). */
    public static function isFinalFailure(string $status): bool
    {
        return self::map($status) === self::FAILED;
    }

    /**
     * Minimum accepted amount (minor units) for a contracted currency, or null when not contracted.
     * 04_BARION §4: HUF 10 Ft, EUR 0.1 EUR, USD 0.1 USD, CZK 1 CZK.
     */
    public static function minimumMinor(string $currency): ?int
    {
        return self::MINIMUM_MINOR[strtoupper(trim($currency))] ?? null;
    }

    /**
     * Validate an outbound amount against §4 rules: integer minor units, > 0, >= currency minimum.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function validateAmount(int $minor, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        $minimum = self::minimumMinor($currency);
        if ($minimum === null) {
            return ['ok' => false, 'reason' => 'currency_unknown'];
        }
        if ($minor <= 0) {
            return ['ok' => false, 'reason' => 'amount_not_positive'];
        }
        if ($minor < $minimum) {
            return ['ok' => false, 'reason' => 'amount_below_minimum'];
        }
        return ['ok' => true, 'reason' => 'ok'];
    }

    public static function isAcceptableAmount(int $minor, string $currency): bool
    {
        return self::validateAmount($minor, $currency)['ok'];
    }

    /**
     * Parse a provider Total (int/float/numeric-string decimal) into integer minor units using integer
     * math only. Returns null when the value is malformed or has excess precision for the currency
     * (04_BARION §4; no float arithmetic).
     */
    public static function amountMinorFromProvider(mixed $total, string $currency): ?int
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $total === null) {
            return null;
        }
        $value = is_int($total) ? $total : (is_string($total) ? trim($total) : (is_float($total) ? (string) $total : null));
        if (!is_int($value) && ($value === null || $value === '')) {
            return null;
        }
        try {
            return Money::toMinor($value, $currency);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Server-side validation of a GetPaymentState payload against the stored attempt.
     * Checks paymentId / status / currency / amount / PaymentRequestId (04_BARION §3 item 4; §4; §7).
     *
     * @param array<string,mixed> $state  decoded GetPaymentState response
     * @param array<string,mixed> $expect external_payment_id, amount_minor, currency, external_request_id
     * @return array{ok:bool,reason:string}
     */
    public static function validateCallback(array $state, array $expect): array
    {
        $paymentId = (string) ($state['PaymentId'] ?? '');
        if ($paymentId === '') {
            return self::fail('payment_id_missing');
        }
        $expectedPaymentId = (string) ($expect['external_payment_id'] ?? '');
        if ($expectedPaymentId !== '' && !hash_equals($expectedPaymentId, $paymentId)) {
            return self::fail('payment_id_mismatch');
        }

        $status = (string) ($state['Status'] ?? '');
        if (!self::isSuccess($status)) {
            return self::fail('status_not_succeeded');
        }

        $currency = strtoupper((string) ($state['Currency'] ?? ''));
        $expectedCurrency = strtoupper((string) ($expect['currency'] ?? ''));
        if ($expectedCurrency !== '' && $currency !== $expectedCurrency) {
            return self::fail('currency_mismatch');
        }

        $receivedMinor = self::amountMinorFromProvider($state['Total'] ?? null, $currency !== '' ? $currency : $expectedCurrency);
        if ($receivedMinor === null) {
            return self::fail('amount_invalid');
        }
        if (array_key_exists('amount_minor', $expect) && (int) $expect['amount_minor'] !== $receivedMinor) {
            return self::fail('amount_mismatch');
        }

        $expectedRequestId = (string) ($expect['external_request_id'] ?? '');
        if ($expectedRequestId !== '') {
            $requestId = (string) ($state['PaymentRequestId'] ?? '');
            if ($requestId !== $expectedRequestId) {
                return self::fail('reference_mismatch');
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /** Canonical per-order ledger/attempt key: fch:{order_id}:payment_start:{n} (design constraints §2; §5.2). */
    public static function idempotencyKey(int|string $orderId, int $sequence): string
    {
        return ExternalOperation::idempotencyKey($orderId, 'payment_start', $sequence);
    }

    /** @return array{ok:bool,reason:string} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
