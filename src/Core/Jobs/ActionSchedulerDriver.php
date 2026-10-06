<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * Primary driver (ADR-005): delegates execution to FluentCart's bundled Action Scheduler when its
 * functions are present. The fch_jobs row is still written so history/lease/recovery are driver-agnostic.
 *
 * Action Scheduler is the pump: every runnable job row must have exactly one pending AS action. Retries
 * (backoff), duplicate enqueues of a still-pending row, and crash-recovered leases all funnel through
 * scheduleIfMissing() so a PENDING row can never be stranded without a future AS trigger.
 */
final class ActionSchedulerDriver implements JobDriverInterface
{
    public const ACTION_HOOK = 'fch_run_job';
    public const GROUP = 'fluentcart-hungary';

    public function __construct(private readonly JobStore $store)
    {
    }

    public static function isAvailable(): bool
    {
        return function_exists('as_enqueue_async_action') && function_exists('as_schedule_recurring_action');
    }

    public function name(): string
    {
        return 'action_scheduler';
    }

    public function enqueue(JobSpec $spec): bool
    {
        $created = $this->store->insert($spec);
        $row = $this->store->findByKey($spec->jobKey);
        if ($row === null) {
            return false;
        }

        if ($created) {
            self::scheduleIfMissing((int) $row->id);
            return true;
        }

        // Duplicate key: harmless while the row is still pending — make sure a pump exists for it.
        if (strtoupper((string) $row->state) === 'PENDING') {
            self::scheduleIfMissing((int) $row->id, self::delayUntil((string) ($row->available_at ?? '')));
            return true;
        }

        return false;
    }

    public function claimNext(string $workerId, int $leaseTtl = 300): ?object
    {
        return null;
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function supportsPull(): bool
    {
        return false;
    }

    /** Schedule the AS pump for one job row unless an identical action is already scheduled. */
    public static function scheduleIfMissing(int $jobId, int $delaySeconds = 0): void
    {
        if (!self::isAvailable() || $jobId <= 0) {
            return;
        }
        $args = ['job_id' => $jobId];
        if (function_exists('as_next_scheduled_action')
            && as_next_scheduled_action(self::ACTION_HOOK, $args, self::GROUP) !== false) {
            return;
        }
        as_schedule_single_action(time() + max(0, $delaySeconds), self::ACTION_HOOK, $args, self::GROUP, false);
    }

    /** Seconds until the job row's available_at (0 when due/unknown). */
    public static function delayUntil(string $availableAt): int
    {
        $availableAt = trim($availableAt);
        if ($availableAt === '') {
            return 0;
        }
        $ts = strtotime($availableAt . ' UTC');
        return $ts === false ? 0 : max(0, $ts - time());
    }
}
