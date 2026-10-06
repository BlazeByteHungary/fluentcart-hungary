<?php
declare(strict_types=1);

use FluentCartHungary\Core\Jobs\JobStore;

fch_test('PERF-02 backoff grows per attempt and is bounded', function (): void {
    $first = JobStore::backoffDelay(1);
    $second = JobStore::backoffDelay(2);
    $last = JobStore::backoffDelay(99);

    assert_true($first >= 48 && $first <= 72, 'attempt 1 ~60s +/-20%');
    assert_true($second >= 240 && $second <= 360, 'attempt 2 ~300s +/-20%');
    assert_true($last >= 69120 && $last <= 103680, 'capped at 24h +/-20%');
    assert_true($second > $first || $first > 100, 'backoff is non-trivial');
});

fch_test('PERF-02 backoff never returns zero/negative', function (): void {
    foreach ([0, 1, 2, 3, 4, 5, 6, 7, 50] as $attempt) {
        assert_true(JobStore::backoffDelay($attempt) > 0);
    }
});
