<?php
declare(strict_types=1);

use FluentCartHungary\Core\Jobs\ActionSchedulerDriver;
use FluentCartHungary\Core\Ledger\ExternalOperation;

/**
 * Regression tests for the queue pump and the reconcile-happy-path transition order (admin-integration
 * session fixes): a retryable failure must yield a future AS pump delay, and mirrors may only reach a
 * proved outcome through the documented UNCERTAIN -> AWAITING_RECONCILIATION hop.
 */

fch_test('UT-AS-01 delayUntil returns the remaining seconds for a future timestamp', function (): void {
    $future = gmdate('Y-m-d H:i:s', time() + 120);
    $delay = ActionSchedulerDriver::delayUntil($future);
    assert_true($delay >= 112 && $delay <= 121, 'expected ~120s delay, got ' . $delay);
});

fch_test('UT-AS-02 delayUntil clamps past/empty values to zero', function (): void {
    assert_same(0, ActionSchedulerDriver::delayUntil(''));
    assert_same(0, ActionSchedulerDriver::delayUntil('2020-01-01 00:00:00'));
    assert_same(0, ActionSchedulerDriver::delayUntil('not-a-date'));
});

fch_test('UT-SM-01 mirror UNCERTAIN may not jump to EXTERNAL_CONFIRMED (must hop via AWAITING)', function (): void {
    $reached = false;
    try {
        ExternalOperation::assertTransition(
            ExternalOperation::UNCERTAIN,
            ExternalOperation::EXTERNAL_CONFIRMED,
            'mirror'
        );
        $reached = true;
    } catch (\Throwable $e) {
        // expected: the mirror machine forbids the direct jump
    }
    assert_true($reached === false, 'UNCERTAIN -> EXTERNAL_CONFIRMED must be invalid on mirrors');

    ExternalOperation::assertTransition(
        ExternalOperation::UNCERTAIN,
        ExternalOperation::AWAITING_RECONCILIATION,
        'mirror'
    );
    ExternalOperation::assertTransition(
        ExternalOperation::AWAITING_RECONCILIATION,
        ExternalOperation::EXTERNAL_CONFIRMED,
        'mirror'
    );
});
