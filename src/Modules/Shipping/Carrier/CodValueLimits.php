<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Carrier;

/**
 * Per-carrier COD value rules shared by the W2 modules (checkout validation + pre-dispatch enforcement).
 *
 * Amounts are DOMAIN minor units (HUF = whole forint). Registry values come from the provider contracts:
 *  - gls:     CODAmount >= 0; per-country ranges are NOT in the current MyGLS doc (legacy HU 50k–500k is
 *             UNPROVEN) → enforcement is settings-driven (`cod_min_minor`/`cod_max_minor`), default only >= 0.
 *  - dpd:     BU-config dependent maxima (Back-Office; ACCOUNT_DEPENDENT) → settings-driven.
 *  - packeta: value maxima per TOS (ACCOUNT_DEPENDENT); HUF COD must be a MULTIPLE OF 5.
 *  - foxpost: integer 0..1 000 000 (guide §6.1 / OpenAPI int64).
 *  - mpl:     500 000 HUF COD cap (W1, unchanged).
 */
final class CodValueLimits
{
    /** @var array<string,array{min:int,max:int,multiple_of:?int}> */
    private static array $limits = [
        'foxpost' => ['min' => 0, 'max' => 1000000, 'multiple_of' => null],
        'packeta' => ['min' => 0, 'max' => 0, 'multiple_of' => 5], // max 0 = no built-in cap (TOS-driven)
    ];

    /** @param array{min?:int,max?:int,multiple_of?:?int} $limits */
    public static function register(string $carrier, array $limits): void
    {
        $carrier = function_exists('sanitize_key') ? sanitize_key($carrier) : (string) preg_replace('/[^a-z0-9_]/', '', strtolower($carrier));
        if ($carrier === '') {
            return;
        }
        self::$limits[$carrier] = [
            'min' => max(0, (int) ($limits['min'] ?? 0)),
            'max' => max(0, (int) ($limits['max'] ?? 0)),
            'multiple_of' => isset($limits['multiple_of']) && (int) $limits['multiple_of'] > 1
                ? (int) $limits['multiple_of']
                : null,
        ];
    }

    /** Effective rule for a carrier with the operator settings applied (settings win over built-ins). */
    public static function effective(string $carrier, ?int $settingMin = null, ?int $settingMax = null): array
    {
        $key = function_exists('sanitize_key') ? sanitize_key($carrier) : (string) preg_replace('/[^a-z0-9_]/', '', strtolower($carrier));
        $base = self::$limits[$key] ?? ['min' => 0, 'max' => 0, 'multiple_of' => null];
        if ($settingMin !== null) {
            $base['min'] = max(0, $settingMin);
        }
        if ($settingMax !== null) {
            $base['max'] = max(0, $settingMax);
        }
        return $base;
    }

    /**
     * @return array{ok:bool,reason:?string}
     */
    public static function check(string $carrier, int $codMinor, ?int $settingMin = null, ?int $settingMax = null): array
    {
        $rule = self::effective($carrier, $settingMin, $settingMax);
        if ($codMinor < 0) {
            return ['ok' => false, 'reason' => 'cod_negative'];
        }
        if ($codMinor < $rule['min']) {
            return ['ok' => false, 'reason' => 'cod_below_min'];
        }
        if ($rule['max'] > 0 && $codMinor > $rule['max']) {
            return ['ok' => false, 'reason' => 'cod_above_max'];
        }
        if ($rule['multiple_of'] !== null && $codMinor > 0 && $codMinor % $rule['multiple_of'] !== 0) {
            return ['ok' => false, 'reason' => 'cod_not_multiple_of_' . $rule['multiple_of']];
        }
        return ['ok' => true, 'reason' => null];
    }

    public static function reset(): void
    {
        self::$limits = [
            'foxpost' => ['min' => 0, 'max' => 1000000, 'multiple_of' => null],
            'packeta' => ['min' => 0, 'max' => 0, 'multiple_of' => 5],
        ];
    }
}
