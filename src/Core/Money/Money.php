<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Money;

/**
 * Integer minor-unit money (ADR-007). No float arithmetic is allowed anywhere on the money path:
 * decimal strings are parsed with integer math only and float input is rejected.
 */
final class Money
{
    public static function exponent(string $currency): int
    {
        return CurrencyExponent::for($currency);
    }

    /** Identity helper: validates the currency and returns the integer minor amount. */
    public static function minor(int $amount, string $currency): int
    {
        CurrencyExponent::for($currency);
        return $amount;
    }

    /**
     * Convert a whole/decimal amount to integer minor units.
     * Accepts a decimal string or a whole-number int; rejects floats (no silent binary rounding).
     *
     * @param string|int $decimal
     * @throws \InvalidArgumentException on float input, malformed input, or excess non-zero precision.
     */
    public static function toMinor(string|int $decimal, string $currency): int
    {
        $exp = CurrencyExponent::for($currency);

        if (is_int($decimal)) {
            if ($exp === 0) {
                return $decimal;
            }
            return $decimal * self::pow10($exp);
        }

        $value = trim($decimal);
        if ($value === '') {
            throw new \InvalidArgumentException('Empty decimal string.');
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new \InvalidArgumentException(sprintf('Malformed decimal "%s".', $value));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = $m[2];
        $frac = $m[3] ?? '';

        if (strlen($frac) > $exp) {
            $extra = substr($frac, $exp);
            if (rtrim($extra, '0') !== '') {
                throw new \InvalidArgumentException(
                    sprintf('Value "%s" has more fractional digits than %s allows (%d).', $value, CurrencyExponent::normalize($currency), $exp)
                );
            }
            $frac = substr($frac, 0, $exp);
        }
        $frac = str_pad($frac, $exp, '0');

        $wholeMinor = (int) $whole * self::pow10($exp);
        $fracMinor = $frac === '' ? 0 : (int) $frac;

        return $sign * ($wholeMinor + $fracMinor);
    }

    /** Render integer minor units as a canonical decimal string (HUF: "12345", EUR: "123.45"). */
    public static function fromMinor(int $minor, string $currency): string
    {
        $exp = CurrencyExponent::for($currency);
        $sign = $minor < 0 ? '-' : '';
        $abs = $minor < 0 ? -$minor : $minor;

        if ($exp === 0) {
            return $sign . (string) $abs;
        }

        $divisor = self::pow10($exp);
        $whole = intdiv($abs, $divisor);
        $frac = $abs % $divisor;

        return $sign . $whole . '.' . str_pad((string) $frac, $exp, '0', STR_PAD_LEFT);
    }

    public static function add(int $a, int $b, string $currency): int
    {
        CurrencyExponent::for($currency);
        return $a + $b;
    }

    public static function sub(int $a, int $b, string $currency): int
    {
        CurrencyExponent::for($currency);
        return $a - $b;
    }

    /** Multiply a minor amount by an integer factor (percentages pass a pre-computed integer ratio). */
    public static function mul(int $minor, int $factor, string $currency): int
    {
        CurrencyExponent::for($currency);
        return $minor * $factor;
    }

    public static function compare(int $a, int $b, string $currency): int
    {
        CurrencyExponent::for($currency);
        return $a <=> $b;
    }

    public static function equals(int $a, int $b, string $currency): bool
    {
        return self::compare($a, $b, $currency) === 0;
    }

    private static function pow10(int $exp): int
    {
        $value = 1;
        for ($i = 0; $i < $exp; $i++) {
            $value *= 10;
        }
        return $value;
    }
}
