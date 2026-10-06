<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\CoreShipping;

/**
 * Pure integer COD-fee calculator (W1.1 legacy `HS-006` fixed/percent + threshold, `HS-007` 5-Ft rounding).
 *
 * Money contract: FluentCart stores every amount as integer "cents" — its `Helper::toCent()` multiplies by
 * 100 even for HUF (vendor/fluentcart-core/fluent-cart/app/Helpers/Helper.php:591-597). HUF therefore carries
 * two notional decimals inside FluentCart, so a 5-Ft step is 500 cents. We never use floats.
 *
 * Legacy evidence (HS-006, `PLUGINS/HU_SHIPPING/wc-pont/pont.php:1059-1103`; audited in
 * docs/discovery/01_HU_SHIPPING_AUDIT.md:67-69):
 *  - fixed amount (e.g. `300`) or integer percentage (`2%`),
 *  - fee skipped when the cart total reaches the configured free-shipping threshold.
 *
 * Legacy rounding evidence (HS-007; `PLUGINS/HU_SHIPPING/wc-pont/pont.php:634-643`):
 *   `$total = round( $total/5, 0 ) * 5;` — PHP `round()` is half-away-from-zero, so the legacy rule is
 *   round-to-nearest-5, halves up, HUF only. That direction is provable from the legacy source, so it is
 *   implemented (not marked UNKNOWN) and covered by tests.
 */
final class CodFeeCalculator
{
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENT = 'percent';

    /** One 5-Ft step in FluentCart cents (5 * 100). */
    public const HUF_STEP_CENTS = 500;

    /**
     * @param int                  $subtotalCents Cart subtotal in FluentCart cents (goods only)
     * @param array{type?:string,amount?:int,threshold?:int} $config
     * @param bool                 $isCod        Whether the selected payment method is COD (`offline_payment`)
     * @param string               $currency      ISO currency of the store
     * @return int fee in FluentCart cents; 0 when not applicable
     */
    public function calculate(int $subtotalCents, array $config, bool $isCod, string $currency): int
    {
        if (!$isCod || $subtotalCents < 0) {
            return 0;
        }

        $type = strtolower(trim((string) ($config['type'] ?? '')));
        $amount = (int) ($config['amount'] ?? 0);
        $threshold = (int) ($config['threshold'] ?? 0);

        if ($amount <= 0) {
            return 0;
        }

        // Threshold = free-shipping / no-COD-fee value: at or above it the fee is skipped (legacy HS-006).
        if ($threshold > 0 && $subtotalCents >= $threshold) {
            return 0;
        }

        if ($type === self::TYPE_FIXED) {
            $fee = $amount;
        } elseif ($type === self::TYPE_PERCENT) {
            // Integer percentage, half-up, no float division.
            $fee = intdiv($subtotalCents * $amount + 50, 100);
        } else {
            return 0;
        }

        if (strtoupper(trim($currency)) === 'HUF') {
            $fee = self::roundToNearest5Huf($fee);
        }

        return max(0, $fee);
    }

    /**
     * Round an integer cent amount to the nearest 5 Ft, halves away from zero (legacy HS-007 half-up).
     * Implemented with integer arithmetic only: `intdiv(x + step/2, step) * step`.
     */
    public static function roundToNearest5Huf(int $cents): int
    {
        if ($cents <= 0) {
            return 0;
        }
        return intdiv($cents + intdiv(self::HUF_STEP_CENTS, 2), self::HUF_STEP_CENTS) * self::HUF_STEP_CENTS;
    }

    /** True when the store currency is subject to the legacy 5-Ft COD rounding. */
    public static function appliesHufRounding(string $currency): bool
    {
        return strtoupper(trim($currency)) === 'HUF';
    }
}
