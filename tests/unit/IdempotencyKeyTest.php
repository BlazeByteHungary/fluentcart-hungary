<?php
declare(strict_types=1);

use FluentCartHungary\Core\Ledger\ExternalOperation as Op;

fch_test('UT-11 idempotency key format fch:{order}:{kind}:{n}', function (): void {
    assert_same('fch:1001:create_invoice:0', Op::idempotencyKey(1001, 'create_invoice', 0));
    assert_same('fch:1001:payment_start:2', Op::idempotencyKey('1001', 'payment_start', 2));
});

fch_test('UT-11 idempotency key parse round-trip', function (): void {
    $parsed = Op::parseIdempotencyKey('fch:1001:label_create:3');
    assert_same(['order_id' => '1001', 'kind' => 'label_create', 'sequence' => 3], $parsed);
    assert_same(null, Op::parseIdempotencyKey('garbage'));
});

fch_test('UT-11 unknown kind / malformed order rejected', function (): void {
    assert_throws(static fn() => Op::idempotencyKey(1, 'not_a_kind', 0), \InvalidArgumentException::class, 'kind');
    assert_throws(static fn() => Op::idempotencyKey('', 'refund', 0), \InvalidArgumentException::class, 'order');
    assert_throws(static fn() => Op::idempotencyKey('a:b', 'refund', 0), \InvalidArgumentException::class, 'colon');
    assert_throws(static fn() => Op::idempotencyKey(1, 'refund', -1), \InvalidArgumentException::class, 'negative');
});

fch_test('UT-11 all schema kinds are recognised', function (): void {
    foreach (Op::KINDS as $kind) {
        assert_true(Op::isKnownKind($kind), $kind);
    }
});
