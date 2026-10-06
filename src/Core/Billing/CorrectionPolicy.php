<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Billing;

/**
 * Merchant policy for linking a CONFIRMED money refund to an invoice correction (W4 invoicing scope).
 *
 * Deliberately narrow and explicit:
 *  - `off` (default): a refund NEVER triggers a document correction automatically.
 *  - `storno_on_full_refund`: when a refund is confirmed AND the order is fully refunded (proven by the
 *    FluentCart refund transactions: refunded amount >= paid amount), the active confirmed automatic
 *    invoice is stornoed.
 *
 * A PARTIAL refund never triggers an automatic full storno — the merchant decides that explicitly, which
 * is why there is no auto policy for partial amounts. Amount-changing corrected documents are not
 * auto-generated at all (item-level correction semantics are not provable from the provider contracts;
 * they are a documented, guided manual action).
 */
final class CorrectionPolicy
{
    public const OFF = 'off';
    public const STORNO_ON_FULL_REFUND = 'storno_on_full_refund';

    /** @return list<string> */
    public static function policies(): array
    {
        return [self::OFF, self::STORNO_ON_FULL_REFUND];
    }

    public static function isKnown(string $policy): bool
    {
        return in_array($policy, self::policies(), true);
    }

    public static function normalize(string $policy): string
    {
        $policy = strtolower(trim($policy));
        return self::isKnown($policy) ? $policy : self::OFF;
    }

    /**
     * The correction kind an automatic linkage must dispatch, or null when nothing may happen.
     * Pure decision function (unit-tested): only a full refund maps to a correction.
     */
    public static function autoKindForRefund(string $policy, bool $fullRefund): ?string
    {
        if (!self::isKnown($policy) || !$fullRefund) {
            return null;
        }
        return $policy === self::STORNO_ON_FULL_REFUND ? 'storno_invoice' : null;
    }
}
