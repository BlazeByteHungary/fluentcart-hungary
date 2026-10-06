<?php
declare(strict_types=1);

use FluentCartHungary\Core\Money\CurrencyExponent;

fch_test('UT-17 currency exponent HUF=0', function (): void {
    assert_same(0, CurrencyExponent::for('HUF'));
});

fch_test('UT-17 currency exponent EUR/USD=2', function (): void {
    assert_same(2, CurrencyExponent::for('EUR'));
    assert_same(2, CurrencyExponent::for('USD'));
});

fch_test('UT-17 exponent normalizes case/whitespace', function (): void {
    assert_same(0, CurrencyExponent::for(' huf '));
});

fch_test('UT-17 unknown currency rejected (no guessing)', function (): void {
    assert_true(!CurrencyExponent::isKnown('XYZ'));
    assert_throws(static fn() => CurrencyExponent::for('XYZ'), \InvalidArgumentException::class, 'unknown currency');
});
