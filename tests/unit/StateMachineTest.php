<?php
declare(strict_types=1);

use FluentCartHungary\Core\Ledger\ExternalOperation as Op;

fch_test('UT-09 legal ledger transitions', function (): void {
    assert_true(Op::isValidTransition(Op::NOT_STARTED, Op::IN_PROGRESS));
    assert_true(Op::isValidTransition(Op::NOT_STARTED, Op::SUPERSEDED));
    assert_true(Op::isValidTransition(Op::IN_PROGRESS, Op::EXTERNAL_CONFIRMED));
    assert_true(Op::isValidTransition(Op::IN_PROGRESS, Op::PROVEN_REJECTED));
    assert_true(Op::isValidTransition(Op::IN_PROGRESS, Op::UNCERTAIN));
    assert_true(Op::isValidTransition(Op::IN_PROGRESS, Op::NOT_STARTED));
    assert_true(Op::isValidTransition(Op::UNCERTAIN, Op::AWAITING_RECONCILIATION));
    assert_true(Op::isValidTransition(Op::AWAITING_RECONCILIATION, Op::EXTERNAL_CONFIRMED));
    assert_true(Op::isValidTransition(Op::AWAITING_RECONCILIATION, Op::PROVEN_REJECTED));
    assert_true(Op::isValidTransition(Op::AWAITING_RECONCILIATION, Op::MANUALLY_RESOLVED));
    assert_true(Op::isValidTransition(Op::AWAITING_RECONCILIATION, Op::SUPERSEDED));
});

fch_test('UT-10 NEG: EXTERNAL_CONFIRMED -> IN_PROGRESS rejected (no regression)', function (): void {
    assert_true(!Op::isValidTransition(Op::EXTERNAL_CONFIRMED, Op::IN_PROGRESS));
    assert_throws(static fn() => Op::assertTransition(Op::EXTERNAL_CONFIRMED, Op::IN_PROGRESS), \DomainException::class, 'regression');
});

fch_test('UT-10 NEG: terminal states cannot transition (ledger)', function (): void {
    foreach (Op::TERMINAL as $terminal) {
        assert_same([], Op::allowedTransitions($terminal));
        assert_true(!Op::isValidTransition($terminal, Op::IN_PROGRESS));
        assert_true(!Op::isValidTransition($terminal, Op::NOT_STARTED));
    }
});

fch_test('UT-10 NEG: EXTERNAL_CONFIRMED -> PROVEN_REJECTED forbidden', function (): void {
    assert_true(!Op::isValidTransition(Op::EXTERNAL_CONFIRMED, Op::PROVEN_REJECTED));
});

fch_test('UT-10 NEG: create worker cannot claim UNCERTAIN / AWAITING_RECONCILIATION', function (): void {
    assert_true(!Op::isValidTransition(Op::UNCERTAIN, Op::IN_PROGRESS));
    assert_true(!Op::isValidTransition(Op::AWAITING_RECONCILIATION, Op::IN_PROGRESS));
});

fch_test('UT-09 mirror profile allows superseding a proved outcome', function (): void {
    assert_true(Op::isValidTransition(Op::EXTERNAL_CONFIRMED, Op::SUPERSEDED, 'mirror'));
    assert_true(!Op::isValidTransition(Op::EXTERNAL_CONFIRMED, Op::SUPERSEDED, 'ledger'));
    assert_true(!Op::isValidTransition(Op::SUPERSEDED, Op::EXTERNAL_CONFIRMED, 'mirror'));
});

fch_test('UT-09 unknown state tokens rejected', function (): void {
    assert_true(!Op::isKnownState('LOCAL_ERROR'));
    assert_true(!Op::isValidTransition('NOT_STARTED', 'NONSENSE'));
});
