<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Payments\Barion\BarionStatus;

$paymentId = '0123456789abcdef0123456789abcdef';

$successState = [
    'PaymentId' => $paymentId,
    'PaymentRequestId' => 'fch-990001-1-test',
    'Status' => 'Succeeded',
    'PaymentType' => 'Immediate',
    'Total' => 12345,
    'Currency' => 'HUF',
    'TraceId' => 'trace-1',
];

$expect = [
    'external_payment_id' => $paymentId,
    'external_request_id' => 'fch-990001-1-test',
    'amount_minor' => 12345,
    'currency' => 'HUF',
];

fch_test('BA-STATUS every raw status maps to a flow bucket', function (): void {
    $pending = ['Prepared', 'Started', 'InProgress', 'Waiting', 'Reserved', 'Authorized'];
    foreach ($pending as $status) {
        assert_same(BarionStatus::PENDING, BarionStatus::map($status), $status);
        assert_true(BarionStatus::isPending($status), $status);
        assert_true(!BarionStatus::isSuccess($status), $status);
        assert_true(!BarionStatus::isFinalFailure($status), $status);
    }

    assert_same(BarionStatus::SUCCESS, BarionStatus::map('Succeeded'));
    assert_true(BarionStatus::isSuccess('Succeeded'));

    $failures = ['Canceled', 'Expired', 'Failed', 'PartiallySucceeded'];
    foreach ($failures as $status) {
        assert_same(BarionStatus::FAILED, BarionStatus::map($status), $status);
        assert_true(BarionStatus::isFinalFailure($status), $status);
        assert_true(!BarionStatus::isSuccess($status), $status);
    }

    assert_same(BarionStatus::UNKNOWN, BarionStatus::map('SomethingNew'));
    assert_true(!BarionStatus::isFinalFailure('SomethingNew'));
    assert_same(11, count(BarionStatus::knownStatuses()));
});

fch_test('BA-STATUS matching callback validates ok', function () use ($successState, $expect): void {
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(true, $result['ok']);
    assert_same('ok', $result['reason']);
});

fch_test('BA-STATUS NEG amount mismatch is rejected', function () use ($successState, $expect): void {
    $expect['amount_minor'] = 999;
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(false, $result['ok']);
    assert_same('amount_mismatch', $result['reason']);
});

fch_test('BA-STATUS NEG currency mismatch is rejected', function () use ($successState, $expect): void {
    $expect['currency'] = 'EUR';
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(false, $result['ok']);
    assert_same('currency_mismatch', $result['reason']);
});

fch_test('BA-STATUS NEG reference mismatch is rejected', function () use ($successState, $expect): void {
    $expect['external_request_id'] = 'fch-990001-9-other';
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(false, $result['ok']);
    assert_same('reference_mismatch', $result['reason']);
});

fch_test('BA-STATUS NEG paymentId mismatch is rejected', function () use ($successState, $expect): void {
    $expect['external_payment_id'] = 'ffffffffffffffffffffffffffffffff';
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(false, $result['ok']);
    assert_same('payment_id_mismatch', $result['reason']);
});

fch_test('BA-STATUS NEG missing paymentId is rejected', function () use ($successState, $expect): void {
    unset($successState['PaymentId']);
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(false, $result['ok']);
    assert_same('payment_id_missing', $result['reason']);
});

fch_test('BA-STATUS NEG non-succeeded status is not proovable (rejected)', function () use ($successState, $expect): void {
    $successState['Status'] = 'Canceled';
    $result = BarionStatus::validateCallback($successState, $expect);
    assert_same(false, $result['ok']);
    assert_same('status_not_succeeded', $result['reason']);
    assert_true(BarionStatus::isFinalFailure('Canceled'));
});

fch_test('BA-AMOUNT HUF rules: integer forint, > 0, minimum 10 Ft', function (): void {
    assert_same(['ok' => true, 'reason' => 'ok'], BarionStatus::validateAmount(12345, 'HUF'));
    assert_true(BarionStatus::isAcceptableAmount(10, 'HUF'));
    assert_same('amount_not_positive', BarionStatus::validateAmount(0, 'HUF')['reason']);
    assert_same('amount_not_positive', BarionStatus::validateAmount(-5, 'HUF')['reason']);
    assert_same('amount_below_minimum', BarionStatus::validateAmount(9, 'HUF')['reason']);
    assert_same(10, BarionStatus::minimumMinor('huf'));
});

fch_test('BA-AMOUNT minimums for other contracted currencies', function (): void {
    assert_true(BarionStatus::isAcceptableAmount(10, 'EUR'));   // 0.10 EUR
    assert_same('amount_below_minimum', BarionStatus::validateAmount(9, 'EUR')['reason']);
    assert_true(BarionStatus::isAcceptableAmount(10, 'USD'));
    assert_same(100, BarionStatus::minimumMinor('CZK'));        // 1 CZK
    assert_same('amount_below_minimum', BarionStatus::validateAmount(99, 'CZK')['reason']);
    assert_same('currency_unknown', BarionStatus::validateAmount(100, 'XXX')['reason']);
});

fch_test('BA-AMOUNT provider total parsing uses integer math (no floats)', function (): void {
    assert_same(12345, BarionStatus::amountMinorFromProvider(12345, 'HUF'));
    assert_same(12345, BarionStatus::amountMinorFromProvider(12345.0, 'HUF'));
    assert_same(12345, BarionStatus::amountMinorFromProvider('12345', 'HUF'));
    assert_same(12345, BarionStatus::amountMinorFromProvider('12345.00', 'HUF'));
    assert_same(null, BarionStatus::amountMinorFromProvider('12345.5', 'HUF'));
    assert_same(null, BarionStatus::amountMinorFromProvider('abc', 'HUF'));
    assert_same(1234, BarionStatus::amountMinorFromProvider(12.34, 'EUR'));
    assert_same(10, BarionStatus::amountMinorFromProvider('0.10', 'EUR'));
});

fch_test('BA-IDEMPOTENCY key shape fch:{order}:payment_start:{n}', function (): void {
    assert_same('fch:990001:payment_start:0', BarionStatus::idempotencyKey(990001, 0));
    assert_same('fch:990001:payment_start:2', BarionStatus::idempotencyKey('990001', 2));
});
