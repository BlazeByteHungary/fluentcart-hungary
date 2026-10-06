<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Money;

/**
 * Explicit ISO 4217 minor-unit exponent table (ADR-007, 05_DATA_SCHEMA §3.3).
 * HUF = 0 decimals; EUR/USD/CZK/RON/PLN/GBP = 2. Unknown currencies are rejected, never guessed.
 */
final class CurrencyExponent
{
    /** @var array<string,int> */
    private const EXPONENTS = [
        'HUF' => 0,
        'EUR' => 2,
        'USD' => 2,
        'CZK' => 2,
        'RON' => 2,
        'PLN' => 2,
        'GBP' => 2,
    ];

    public static function normalize(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    public static function isKnown(string $currency): bool
    {
        return array_key_exists(self::normalize($currency), self::EXPONENTS);
    }

    /**
     * @throws \InvalidArgumentException when the currency has no contracted exponent.
     */
    public static function for(string $currency): int
    {
        $currency = self::normalize($currency);
        if (!array_key_exists($currency, self::EXPONENTS)) {
            throw new \InvalidArgumentException(sprintf('Unknown currency exponent for "%s".', $currency));
        }
        return self::EXPONENTS[$currency];
    }

    /** @return array<string,int> */
    public static function known(): array
    {
        return self::EXPONENTS;
    }
}
