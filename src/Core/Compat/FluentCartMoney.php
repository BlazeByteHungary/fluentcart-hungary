<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Compat;

use FluentCartHungary\Core\Money\Money;

/**
 * Money conversion at the FluentCart boundary (compatibility layer; ADR-007 + W1_MONEY_BOUNDARIES).
 *
 * FluentCart stores every amount as `amount × 100` for **every** currency — its `Helper::toCent()`
 * multiplies by 100 unconditionally and `Helper::toDecimal()` divides by 100
 * (vendor/fluentcart-core/fluent-cart/app/Helpers/Helper.php:496,590-597), so a 2 540 Ft order is stored
 * as `254000`. Our domain uses ISO 4217 minor units (HUF = 0 → forint, EUR = 2 → cent) per ADR-007, and
 * the provider contracts expect that same domain unit (Barion `Total` decimal with HUF 0 decimals,
 * MPL `services.cod`/`services.value` in HUF integer, Számlázz HUF item amounts as integers).
 *
 * Therefore every FluentCart amount read/written by a provider module MUST pass through this class:
 *   - exponent 2 currencies (EUR/USD/...): FluentCart cents === our minor units → identity;
 *   - HUF (exponent 0): FluentCart stores fillér-like hundredths → divide by 100.
 *
 * No global ×100/÷100 is ever applied; conversion is currency-aware and lives only at the FC boundary.
 * Non-divisible HUF cents are rounded half-away-from-zero (the Barion contract places rounding with the
 * shop; the provider amount match then compares equal minor units on both sides).
 */
final class FluentCartMoney
{
    /** Convert a FluentCart stored amount (×100) to our ISO-exponent minor units. */
    public static function fromCartCents(int|float|string $cartCents, string $currency): int
    {
        $cents = self::normalizeCents($cartCents);
        $exp = Money::exponent($currency);

        if ($exp === 2) {
            return $cents;
        }
        if ($exp === 0) {
            return self::roundHalfAway($cents, 100);
        }

        throw new \InvalidArgumentException(sprintf(
            'FluentCart boundary conversion is not defined for a %d-exponent currency (%s).',
            $exp,
            strtoupper(trim($currency))
        ));
    }

    /** Convert our ISO-exponent minor units to the FluentCart stored amount (×100). */
    public static function toCartCents(int $minor, string $currency): int
    {
        $exp = Money::exponent($currency);

        if ($exp === 2) {
            return $minor;
        }
        if ($exp === 0) {
            return $minor * 100;
        }

        throw new \InvalidArgumentException(sprintf(
            'FluentCart boundary conversion is not defined for a %d-exponent currency (%s).',
            $exp,
            strtoupper(trim($currency))
        ));
    }

    /**
     * Normalize an FC cents value that may arrive as int/float/numeric string. Mirrors FC's own
     * `Helper::roundCent()` guards: float artifacts are rounded to the nearest integer and values beyond
     * float's exact-integer range are rejected instead of silently wrapped.
     */
    private static function normalizeCents(int|float|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                throw new \InvalidArgumentException('FluentCart cents value is not numeric.');
            }
            $value = (float) $value;
        }
        if (!is_finite($value) || $value > 9.0e15 || $value < -9.0e15) {
            throw new \InvalidArgumentException('FluentCart cents value is out of range: ' . var_export($value, true));
        }
        return (int) round($value);
    }

    /** Exact integer division with half-away-from-zero rounding on the remainder. */
    private static function roundHalfAway(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if ($remainder === 0) {
            return $quotient;
        }
        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }
        return $quotient;
    }
}
