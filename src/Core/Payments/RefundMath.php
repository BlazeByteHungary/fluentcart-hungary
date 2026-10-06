<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Payments;

/**
 * Pure integer math for refund availability (W4_EXECUTION_PLAN §4; 05_MONEY_CONTRACTS).
 *
 * All amounts are domain minor units (HUF = forint, EUR/USD = cent). There is no floating point money
 * anywhere in this class; provider decimals only ever appear at the transport boundary (Money::fromMinor).
 */
final class RefundMath
{
    /**
     * Amount still refundable: proven charge minus confirmed refunds minus open reservations.
     * Every term is minor units of the same currency; a negative result clamps to 0.
     */
    public static function available(int $chargedMinor, int $confirmedRefundedMinor, int $openReservedMinor): int
    {
        $available = $chargedMinor - $confirmedRefundedMinor - $openReservedMinor;
        return max(0, $available);
    }

    /**
     * Validates a requested refund against the available amount.
     *
     * @return array{ok:bool,reason:?string}
     */
    public static function validate(int $requestedMinor, int $availableMinor): array
    {
        if ($requestedMinor <= 0) {
            return ['ok' => false, 'reason' => 'AMOUNT_NOT_POSITIVE'];
        }
        if ($availableMinor <= 0) {
            return ['ok' => false, 'reason' => 'NOTHING_REFUNDABLE'];
        }
        if ($requestedMinor > $availableMinor) {
            return ['ok' => false, 'reason' => 'EXCEEDS_REFUNDABLE'];
        }
        return ['ok' => true, 'reason' => null];
    }

    /**
     * Splits a total into sequential partial refunds that must never exceed it (EUR 19,99 → 10,00 + 9,99).
     *
     * @param list<int> $requested
     * @return array{ok:bool,reason:?string,remaining:int}
     */
    public static function validateSequence(int $chargedMinor, array $requested): array
    {
        $remaining = $chargedMinor;
        foreach ($requested as $amount) {
            $check = self::validate((int) $amount, $remaining);
            if (!$check['ok']) {
                return ['ok' => false, 'reason' => $check['reason'], 'remaining' => $remaining];
            }
            $remaining -= (int) $amount;
        }
        return ['ok' => true, 'reason' => null, 'remaining' => $remaining];
    }
}
