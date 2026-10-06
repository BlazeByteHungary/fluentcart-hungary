<?php
declare(strict_types=1);

/**
 * Global helper functions with the normative names from 05_OPERATIONS / 05_DATA_SCHEMA.
 * Every wrapper is guarded so a host cannot fatal on a redeclaration.
 */

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Money\CurrencyExponent;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Settings\CredentialStore;

if (!function_exists('fch_mask_secret')) {
    function fch_mask_secret(string $value): string
    {
        return CredentialStore::mask($value);
    }
}

if (!function_exists('fch_redact_payload')) {
    /**
     * Redact secret field names from a payload before logging/storing (05_OPERATIONS §4.2).
     *
     * @param mixed $payload
     * @return mixed
     */
    function fch_redact_payload(mixed $payload): mixed
    {
        $secretNames = [
            'szamlaagentkulcs', 'jelszo', 'x-api-key', 'api_key', 'apikey', 'poskey', 'pos_key',
            'x-pos-key', 'secret_key', 'secretkey', 'cardsecret', 'card_secret', 'client_secret',
            'password', 'passwd', 'signing key', 'signing_key', 'apipassword', 'api_password',
            'api-key', 'jwt', 'authorization', 'credential', 'credentials', 'private_key',
        ];

        if (is_array($payload)) {
            $out = [];
            foreach ($payload as $key => $value) {
                $out[$key] = is_string($key) && fch_is_secret_field($key, $secretNames)
                    ? '***REDACTED***'
                    : fch_redact_payload($value);
            }
            return $out;
        }

        return $payload;
    }
}

if (!function_exists('fch_is_secret_field')) {
    /** @param list<string> $secretNames */
    function fch_is_secret_field(string $key, array $secretNames): bool
    {
        $needle = strtolower(trim($key));
        if (in_array($needle, $secretNames, true)) {
            return true;
        }
        foreach (['secret', 'password', 'passwd', 'jelszo', 'poskey', 'pos-key', 'api-key', 'api_key', 'apikey'] as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('fch_currency_exponent')) {
    function fch_currency_exponent(string $currency): int
    {
        return CurrencyExponent::for($currency);
    }
}

if (!function_exists('fch_minor')) {
    function fch_minor(int $amount, string $currency): int
    {
        return Money::minor($amount, $currency);
    }
}

if (!function_exists('fch_to_minor')) {
    function fch_to_minor(string|int $decimal, string $currency): int
    {
        return Money::toMinor($decimal, $currency);
    }
}

if (!function_exists('fch_from_minor')) {
    function fch_from_minor(int $minor, string $currency): string
    {
        return Money::fromMinor($minor, $currency);
    }
}

if (!function_exists('fch_idempotency_key')) {
    function fch_idempotency_key(int|string $orderId, string $kind, int $sequence): string
    {
        return ExternalOperation::idempotencyKey($orderId, $kind, $sequence);
    }
}
