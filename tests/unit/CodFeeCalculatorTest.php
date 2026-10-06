<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\CoreShipping\CodFeeCalculator;

fch_test('COD-FEE fixed amount is returned in cents', function (): void {
    $calc = new CodFeeCalculator();
    assert_same(45000, $calc->calculate(1000000, ['type' => 'fixed', 'amount' => 45000], true, 'HUF'));
});

fch_test('COD-FEE non-COD never charges', function (): void {
    $calc = new CodFeeCalculator();
    assert_same(0, $calc->calculate(1000000, ['type' => 'fixed', 'amount' => 45000], false, 'HUF'));
});

fch_test('COD-FEE percent uses integer half-up math', function (): void {
    $calc = new CodFeeCalculator();
    // 2% of 1 000 000 cents = 20 000 -> already a 5-Ft multiple.
    assert_same(20000, $calc->calculate(1000000, ['type' => 'percent', 'amount' => 2], true, 'HUF'));
    // 1% of 10050 = 100.5 -> half-up 101; EUR carries no 5-Ft rounding.
    assert_same(101, $calc->calculate(10050, ['type' => 'percent', 'amount' => 1], true, 'EUR'));
});

fch_test('COD-FEE threshold suppresses the fee at/above the limit', function (): void {
    $calc = new CodFeeCalculator();
    $config = ['type' => 'fixed', 'amount' => 45000, 'threshold' => 2000000];
    assert_same(0, $calc->calculate(2000000, $config, true, 'HUF'));
    assert_same(0, $calc->calculate(3000000, $config, true, 'HUF'));
    assert_same(45000, $calc->calculate(1999999, $config, true, 'HUF'));
});

fch_test('HS-007 5-Ft rounding is nearest-5, halves up, integer-only', function (): void {
    $calc = new CodFeeCalculator();
    // 452 Ft -> 450 Ft (45200 cents -> 45000)
    assert_same(45000, $calc->calculate(1000000, ['type' => 'fixed', 'amount' => 45200], true, 'HUF'));
    // 453 Ft -> 455 Ft
    assert_same(45500, $calc->calculate(1000000, ['type' => 'fixed', 'amount' => 45300], true, 'HUF'));
    // 452.5 Ft -> half away from zero -> 455 Ft
    assert_same(45500, CodFeeCalculator::roundToNearest5Huf(45250));
    // 452.49... -> 450 Ft
    assert_same(45000, CodFeeCalculator::roundToNearest5Huf(45249));
    assert_same(0, CodFeeCalculator::roundToNearest5Huf(0));
});

fch_test('COD-FEE non-HUF is not 5-rounded', function (): void {
    $calc = new CodFeeCalculator();
    assert_same(452, $calc->calculate(1000000, ['type' => 'fixed', 'amount' => 452], true, 'EUR'));
    assert_same(false, CodFeeCalculator::appliesHufRounding('EUR'));
    assert_same(true, CodFeeCalculator::appliesHufRounding('huf'));
});

fch_test('COD-FEE zero/negative and unknown config never charge', function (): void {
    $calc = new CodFeeCalculator();
    assert_same(0, $calc->calculate(1000, ['type' => 'fixed', 'amount' => 0], true, 'HUF'));
    assert_same(0, $calc->calculate(1000, ['type' => 'nonsense', 'amount' => 100], true, 'HUF'));
    assert_same(0, $calc->calculate(-1, ['type' => 'fixed', 'amount' => 100], true, 'HUF'));
});
