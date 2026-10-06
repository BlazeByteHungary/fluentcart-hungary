<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Money\Money;

/**
 * Billingo API v3 vocabulary, error classification and provider-format helpers
 * (04_BILLINGO_CONTRACT.md §3-§6; DOCS/BILLINGO/BILLINGO_DEVELOPERS_API_V3.md).
 *
 * Pure logic only (no WordPress): unit-testable. Error mapping follows the documented status codes:
 * 401 auth, 402 no subscription, 403 forbidden, 404 absent, 422 validation, 429 rate limit (300/min).
 * A create is only re-dispatchable for local (pre-dispatch) failures; a lost/timeout response is
 * UNCERTAIN and is resolved by `GET /documents/vendor/{vendor_id}` — never by a blind second create.
 */
final class BillingoStatus
{
    public const PROVIDER = 'billingo';
    public const BASE = 'https://api.billingo.hu/v3';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_LOCAL_ERROR = 'local_error';
    public const OUTCOME_UNCERTAIN = 'uncertain';

    /** Document types we can produce (04 §3.1). Only `invoice` is auto-issued by policy. */
    public const DOC_TYPES = [
        'invoice', 'proforma', 'advance', 'draft', 'receipt', 'cancellation', 'modification', 'waybill',
    ];

    /** FC payment-method slug → Billingo payment_method enum (04 §4; API doc payment_method table). */
    private const DEFAULT_PAYMENT_METHODS = [
        'offline_payment' => 'cash_on_delivery',
        'barion' => 'barion',
        'simplepay' => 'online_bankcard',
        'bank_transfer' => 'elore_utalas',
        'stripe' => 'online_bankcard',
        'paypal' => 'paypal',
    ];

    public static function isSupportedCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), ['HUF', 'EUR', 'USD', 'GBP', 'CHF', 'RON', 'PLN', 'CZK', 'SEK', 'DKK'], true);
    }

    /** @param array<string,string> $overrides FC slug → Billingo method */
    public static function paymentMethodFor(string $fcMethod, array $overrides = [], string $default = 'elore_utalas'): string
    {
        $fcMethod = strtolower(trim($fcMethod));
        if ($fcMethod !== '' && isset($overrides[$fcMethod]) && $overrides[$fcMethod] !== '') {
            return (string) $overrides[$fcMethod];
        }
        if (isset(self::DEFAULT_PAYMENT_METHODS[$fcMethod])) {
            return self::DEFAULT_PAYMENT_METHODS[$fcMethod];
        }
        return $default !== '' ? $default : 'elore_utalas';
    }

    /**
     * Provider-side amount: HUF (zero-decimal) as int, two-decimal currencies as float built from the
     * integer minor units (never from floating arithmetic on money).
     */
    public static function amountForProvider(int $minor, string $currency): int|float
    {
        $currency = strtoupper($currency);
        if (Money::exponent($currency) === 0) {
            return $minor;
        }
        return (float) number_format($minor / 100, 2, '.', '');
    }

    /**
     * Stable, length-safe external reference (04 §6.1). The exact Billingo maximum length is UNKNOWN
     * (changelog 3.0.15.23); the value stays short, alphanumeric+dash, and unique per attempt.
     */
    public static function vendorId(int|string $orderId, string $provider, string $docRole, int $sequence): string
    {
        $role = preg_replace('/[^a-z0-9]/', '', strtolower($docRole)) ?: 'doc';
        $vendor = sprintf('fch-%s-%s-%s-%d', (string) $orderId, strtolower($provider), $role, $sequence);
        return substr($vendor, 0, 64);
    }

    /**
     * Classify a provider HTTP outcome for the ledger.
     *
     * @return array{outcome:string,code:string}
     */
    public static function classifyHttp(int $httpStatus): array
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return ['outcome' => self::OUTCOME_SUCCESS, 'code' => 'OK'];
        }
        return match ($httpStatus) {
            401 => ['outcome' => self::OUTCOME_LOCAL_ERROR, 'code' => 'AUTH_FAILED'],
            402 => ['outcome' => self::OUTCOME_LOCAL_ERROR, 'code' => 'NO_SUBSCRIPTION'],
            403 => ['outcome' => self::OUTCOME_LOCAL_ERROR, 'code' => 'FORBIDDEN'],
            404 => ['outcome' => self::OUTCOME_REJECTED, 'code' => 'NOT_FOUND'],
            422 => ['outcome' => self::OUTCOME_REJECTED, 'code' => 'VALIDATION'],
            429 => ['outcome' => self::OUTCOME_LOCAL_ERROR, 'code' => 'RATE_LIMITED'],
            0, 408 => ['outcome' => self::OUTCOME_UNCERTAIN, 'code' => 'TIMEOUT_AFTER_DISPATCH'],
            default => $httpStatus >= 500
                ? ['outcome' => self::OUTCOME_UNCERTAIN, 'code' => 'PROVIDER_ERROR_' . $httpStatus]
                : ['outcome' => self::OUTCOME_REJECTED, 'code' => 'HTTP_' . $httpStatus],
        };
    }

    /**
     * Merchant-visible, secret-free error label from a Billingo error body
     * (`{message, errors: {field: [..]}}`).
     *
     * @param array<string,mixed>|null $body
     */
    public static function errorLabel(?array $body, int $httpStatus = 0): string
    {
        $label = trim((string) ($body['message'] ?? ''));
        $errors = $body['errors'] ?? null;
        if (is_array($errors)) {
            $parts = [];
            foreach ($errors as $field => $messages) {
                $messages = is_array($messages) ? $messages : [$messages];
                foreach ($messages as $message) {
                    $parts[] = trim((string) $field) . ': ' . trim((string) $message);
                }
                if (count($parts) >= 3) {
                    break;
                }
            }
            if ($parts !== []) {
                $label = $label !== '' ? $label . ' (' . implode('; ', $parts) . ')' : implode('; ', $parts);
            }
        }
        if ($label === '') {
            $label = match ($httpStatus) {
                401 => 'Érvénytelen vagy hiányzó API kulcs.',
                402 => 'Nincs aktív Billingo előfizetés/API csomag.',
                403 => 'Az API kulcs jogosultsága nem elegendő.',
                429 => 'Elértük a Billingo sebességkorlátot (300 kérés/perc).',
                default => 'Billingo hiba (HTTP ' . $httpStatus . ').',
            };
        }
        return substr($label, 0, 300);
    }
}
