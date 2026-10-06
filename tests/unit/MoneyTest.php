<?php
declare(strict_types=1);

use FluentCartHungary\Core\Money\Money;

fch_test('UT-01 HUF is integer minor units (0 decimals)', function (): void {
    assert_same(12345, Money::toMinor('12345', 'HUF'));
    assert_same('12345', Money::fromMinor(12345, 'HUF'));
});

fch_test('UT-02 EUR round-trips 2 decimals exactly', function (): void {
    assert_same(12345, Money::toMinor('123.45', 'EUR'));
    assert_same('123.45', Money::fromMinor(12345, 'EUR'));
    assert_same(-12345, Money::toMinor('-123.45', 'EUR'));
    assert_same('-123.45', Money::fromMinor(-12345, 'EUR'));
});

fch_test('UT-02 trailing zeros and short fractions are padded', function (): void {
    assert_same(120, Money::toMinor('1.2', 'EUR'));
    assert_same('1.20', Money::fromMinor(120, 'EUR'));
    assert_same(100, Money::toMinor('1.00', 'EUR'));
});

fch_test('UT-02 whole int input is whole currency units', function (): void {
    assert_same(500, Money::toMinor(5, 'EUR'));
    assert_same(5, Money::toMinor(5, 'HUF'));
});

fch_test('UT-01 no float path: float input is rejected', function (): void {
    assert_throws(static fn() => Money::toMinor(12.34, 'EUR'), \TypeError::class, 'float input');
});

fch_test('UT-02 excess non-zero precision is rejected, not truncated', function (): void {
    assert_throws(static fn() => Money::toMinor('1.234', 'EUR'), \InvalidArgumentException::class, 'excess precision');
    assert_same(123, Money::toMinor('1.230', 'EUR'));
});

fch_test('UT-02 malformed decimal rejected', function (): void {
    assert_throws(static fn() => Money::toMinor('1,23', 'EUR'), \InvalidArgumentException::class, 'comma decimal');
    assert_throws(static fn() => Money::toMinor('', 'HUF'), \InvalidArgumentException::class, 'empty');
});

fch_test('UT-19 exact integer arithmetic and comparison', function (): void {
    assert_same(300, Money::add(100, 200, 'HUF'));
    assert_same(100, Money::sub(300, 200, 'HUF'));
    assert_same(1, Money::compare(200, 100, 'HUF'));
    assert_true(Money::equals(100, 100, 'EUR'));
});
