<?php
declare(strict_types=1);

use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Payments\RefundMath;
use FluentCartHungary\Modules\Payments\Barion\BarionRefundService;

/**
 * W4 refund money/identity vectors (W4_EXECUTION_PLAN §4/§6). Integer minor units only — HUF has no
 * decimals, EUR/USD exactly 2, and no float ever decides an amount.
 */

fch_test('RF-AVAIL available = charge - confirmed - reserved, clamped at zero', function (): void {
    assert_same(3790, RefundMath::available(3790, 0, 0));
    assert_same(2790, RefundMath::available(3790, 1000, 0));
    assert_same(1790, RefundMath::available(3790, 1000, 1000));
    assert_same(0, RefundMath::available(3790, 3790, 0));
    assert_same(0, RefundMath::available(3790, 3790, 1000));
    // An UNCERTAIN 1000 Ft reservation keeps the amount fenced even after the charge is exhausted.
    assert_same(0, RefundMath::available(3790, 2790, 1000));
});

fch_test('RF-VALIDATE rejects zero, negative, empty and over-refunds', function (): void {
    assert_same(false, RefundMath::validate(0, 3790)['ok']);
    assert_same('AMOUNT_NOT_POSITIVE', RefundMath::validate(0, 3790)['reason']);
    assert_same(false, RefundMath::validate(-5, 3790)['ok']);
    assert_same(false, RefundMath::validate(100, 0)['ok']);
    assert_same('NOTHING_REFUNDABLE', RefundMath::validate(100, 0)['reason']);
    assert_same(false, RefundMath::validate(2791, 2790)['ok']);
    assert_same('EXCEEDS_REFUNDABLE', RefundMath::validate(2791, 2790)['reason']);
    assert_same(true, RefundMath::validate(2790, 2790)['ok']);
    assert_same(true, RefundMath::validate(1000, 2790)['ok']);
});

fch_test('RF-SEQUENCE HUF 3790 -> 1000 then 2790 exactly; 2791 never fits', function (): void {
    $first = RefundMath::validateSequence(3790, [1000]);
    assert_same(true, $first['ok']);
    assert_same(2790, $first['remaining']);

    $second = RefundMath::validateSequence(3790, [1000, 2790]);
    assert_same(true, $second['ok']);
    assert_same(0, $second['remaining']);

    $tooMuch = RefundMath::validateSequence(3790, [1000, 2791]);
    assert_same(false, $tooMuch['ok']);
    assert_same('EXCEEDS_REFUNDABLE', $tooMuch['reason']);
    assert_same(2790, $tooMuch['remaining']);
});

fch_test('RF-EUR 19,99 splits into 10,00 + 9,99 with no rounding leak', function (): void {
    $split = RefundMath::validateSequence(1999, [1000, 999]);
    assert_same(true, $split['ok']);
    assert_same(0, $split['remaining']);

    $over = RefundMath::validateSequence(1999, [1000, 1000]);
    assert_same(false, $over['ok']);
    assert_same(999, $over['remaining']);

    // Provider decimal boundary: 1999 minor -> "19.99" -> back to 1999, integer-exact.
    assert_same('19.99', Money::fromMinor(1999, 'EUR'));
    assert_same(1999, Money::toMinor('19.99', 'EUR'));
    assert_same('10.00', Money::fromMinor(1000, 'EUR'));
    assert_same(999, Money::toMinor('9.99', 'EUR'));
    assert_same('3790', Money::fromMinor(3790, 'HUF'));
    assert_same(3790, Money::toMinor('3790', 'HUF'));
});

fch_test('RF-MARKER Barion comment marker is deterministic and ledger-key scoped', function (): void {
    $a = BarionRefundService::commentMarker('fch:991001:refund:1');
    $b = BarionRefundService::commentMarker('fch:991001:refund:1');
    $c = BarionRefundService::commentMarker('fch:991001:refund:2');
    assert_same($a, $b);
    assert_true($a !== $c);
    assert_true(str_contains($a, 'fch:991001:refund:1'));
});

fch_test('RF-BIND refundable types stay limited to the documented Barion set', function (): void {
    assert_same(['CardPayment', 'Shop', 'BankTransferPayment'], BarionRefundService::REFUNDABLE_TYPES);
});
