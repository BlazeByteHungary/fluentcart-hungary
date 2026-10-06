<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * Queue driver contract (ADR-005). Both drivers persist the driver-agnostic record in fch_jobs;
 * the AS driver additionally hands execution to Action Scheduler.
 */
interface JobDriverInterface
{
    public function name(): string;

    /** @return bool true when a new row was created, false when the job_key already existed (idempotent). */
    public function enqueue(JobSpec $spec): bool;

    /** @return object|null a claimed job row, or null when nothing is claimable. */
    public function claimNext(string $workerId, int $leaseTtl = 300): ?object;

    /** True when the driver offloads execution to an external scheduler (AS). */
    public function isAsync(): bool;

    /** True when the runner may pull and execute jobs itself (DB driver); false for AS. */
    public function supportsPull(): bool;
}
