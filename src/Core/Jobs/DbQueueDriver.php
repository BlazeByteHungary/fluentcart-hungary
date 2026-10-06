<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * Fallback driver (ADR-005): polling queue over fch_jobs with lease, attempts, backoff and crash recovery.
 * Used when Action Scheduler is absent/unavailable or when an operator forces `fch_queue_driver=db`.
 */
final class DbQueueDriver implements JobDriverInterface
{
    public function __construct(private readonly JobStore $store)
    {
    }

    public function name(): string
    {
        return 'db_queue';
    }

    public function enqueue(JobSpec $spec): bool
    {
        return $this->store->insert($spec);
    }

    public function claimNext(string $workerId, int $leaseTtl = 300): ?object
    {
        return $this->store->claimNext($workerId, $leaseTtl);
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function supportsPull(): bool
    {
        return true;
    }
}
