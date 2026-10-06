<?php
declare(strict_types=1);

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Money\Money;

/**
 * FluentCart boundary money contract (W1_MONEY_BOUNDARIES): FC stores amount×100 for every currency;
 * our domain uses ISO exponents (HUF=0). These tests pin the conversion and its rounding.
 */

fch_test('UT-FCM-01 HUF: FC cents → forint (/100) and back', function (): void {
    assert_same(4240, FluentCartMoney::fromCartCents(424000, 'HUF'));
    assert_same(3790, FluentCartMoney::fromCartCents(379000, 'HUF'));
    assert_same(450, FluentCartMoney::fromCartCents(45000, 'HUF'));
    assert_same(0, FluentCartMoney::fromCartCents(0, 'HUF'));
    assert_same(424000, FluentCartMoney::toCartCents(4240, 'HUF'));
});

fch_test('UT-FCM-02 EUR: FC cents === ISO minor units (identity both ways)', function (): void {
    assert_same(12345, FluentCartMoney::fromCartCents(12345, 'EUR'));
    assert_same(12345, FluentCartMoney::toCartCents(12345, 'EUR'));
    assert_same('123.45', Money::fromMinor(FluentCartMoney::fromCartCents(12345, 'EUR'), 'EUR'));
});

fch_test('UT-FCM-03 HUF: non-divisible cents round half-away-from-zero, sign preserved', function (): void {
    assert_same(1, FluentCartMoney::fromCartCents(50, 'HUF'));
    assert_same(2, FluentCartMoney::fromCartCents(150, 'HUF'));
    assert_same(-1, FluentCartMoney::fromCartCents(-50, 'HUF'));
    assert_same(-2, FluentCartMoney::fromCartCents(-150, 'HUF'));
    assert_same(4241, FluentCartMoney::fromCartCents(424050, 'HUF'));
});

fch_test('UT-FCM-04 float artifacts and numeric strings normalize (FC roundCent parity)', function (): void {
    assert_same(4240, FluentCartMoney::fromCartCents(424000.0, 'HUF'));
    assert_same(4240, FluentCartMoney::fromCartCents('424000', 'HUF'));
    assert_same(199900, FluentCartMoney::fromCartCents('199900', 'EUR'));
    // JS-style float artifact for 1999 cents (FC Helper::roundCent documents this exact case).
    assert_same(1999, FluentCartMoney::fromCartCents(1998.9999999999998, 'EUR'));
    assert_same(3790, FluentCartMoney::fromCartCents(378999.99999999994, 'HUF'));
});

fch_test('UT-FCM-05 invalid input is rejected, never guessed', function (): void {
    assert_throws(static fn() => FluentCartMoney::fromCartCents('', 'HUF'), \InvalidArgumentException::class);
    assert_throws(static fn() => FluentCartMoney::fromCartCents('abc', 'HUF'), \InvalidArgumentException::class);
    assert_throws(static fn() => FluentCartMoney::fromCartCents(100, 'CHF'), \InvalidArgumentException::class);
});

fch_test('UT-FCM-06 the 4 240 Ft example is stable end to end', function (): void {
    // FC DB: 3790 Ft product + 450 Ft fee → stored 379000 / 45000 cents, order 424000 cents.
    $goods = FluentCartMoney::fromCartCents(379000, 'HUF');
    $fee = FluentCartMoney::fromCartCents(45000, 'HUF');
    $total = FluentCartMoney::fromCartCents(379000 + 45000, 'HUF');
    assert_same(3790, $goods);
    assert_same(450, $fee);
    assert_same(4240, $total);
    // Barion decimal Total for HUF (0 decimals) and MPL integer amounts come from the same minor value.
    assert_same('4240', Money::fromMinor($total, 'HUF'));
});
