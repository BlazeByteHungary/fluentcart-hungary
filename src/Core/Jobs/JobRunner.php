<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

/**
 * Queue facade (ADR-005). Selects a driver, registers handlers, and executes leased fch_jobs rows.
 * Driver choice is automatic (Action Scheduler when present) with a manual override for diagnostics.
 */
final class JobRunner
{
    private static ?JobRunner $instance = null;

    private JobStore $store;

    private JobDriverInterface $driver;

    /** @var array<string,JobInterface> */
    private array $handlers = [];

    public function __construct(?JobDriverInterface $driver = null)
    {
        $this->store = new JobStore();
        $this->driver = $driver ?? self::selectDriver($this->store);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function store(): JobStore
    {
        return $this->store;
    }

    public function driver(): JobDriverInterface
    {
        return $this->driver;
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }

    public function registerHandler(JobInterface $handler): void
    {
        $this->handlers[$handler->hook()] = $handler;
    }

    /** @return array<string,JobInterface> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    public function enqueue(JobSpec $spec): bool
    {
        return $this->driver->enqueue($spec);
    }

    /** Pull and execute due jobs (DB driver). Returns the number executed; 0 for the async driver. */
    public function runDue(int $limit = 0): int
    {
        if (!$this->driver->supportsPull()) {
            return 0;
        }

        $executed = 0;
        $workerId = $this->workerId();
        $ttl = $this->leaseTtl();

        while (($row = $this->driver->claimNext($workerId, $ttl)) !== null) {
            $this->execute($row);
            $executed++;
            if ($limit > 0 && $executed >= $limit) {
                break;
            }
        }

        return $executed;
    }

    /** Entry point for the Action Scheduler callback. */
    public function executeById(int $id): void
    {
        $row = $this->store->findById($id);
        if ($row === null) {
            return;
        }
        $state = (string) $row->state;
        if (in_array($state, ['SUCCEEDED', 'FAILED', 'DEAD_LETTER'], true)) {
            return;
        }
        $this->execute($row);
    }

    public function releaseExpired(): int
    {
        $ids = $this->store->releaseExpiredIds();
        if ($this->driver instanceof ActionSchedulerDriver) {
            // Crash-recovered leases must get a new pump; otherwise the row would sit PENDING forever.
            foreach ($ids as $id) {
                ActionSchedulerDriver::scheduleIfMissing($id, 30);
            }
        }
        return count($ids);
    }

    /**
     * Operator-triggered enqueue for an explicit business action (invoice/label re-issue). The stable key
     * deduplicates while the work is queued or running, but a TERMINAL row with the same key must not
     * silently swallow a fresh intent (a previous no-op or completed run): the new request gets an
     * attempt-scoped key. Returns false only when the job store rejected the insert.
     */
    public function enqueueRerunnable(JobSpec $spec): bool
    {
        $existing = $this->store->findByKey($spec->jobKey);
        if ($existing === null) {
            return $this->enqueue($spec);
        }

        $state = strtoupper((string) $existing->state);
        if (in_array($state, ['PENDING', 'RESERVED', 'RUNNING'], true)) {
            // Already queued/running: enqueue() dedupes and keeps a pump scheduled for a PENDING row.
            return $this->enqueue($spec);
        }

        // Terminal row for the same business intent: reopen it (same key → parallel duplicate clicks
        // still share one row and one claim) and give it a pump.
        $id = (int) $existing->id;
        if ($this->store->reopen($id)) {
            if ($this->driver instanceof ActionSchedulerDriver) {
                ActionSchedulerDriver::scheduleIfMissing($id);
            }
            return true;
        }
        return $this->enqueue($spec);
    }

    /** A retryable failure leaves the row PENDING with backoff; give it a future AS pump. */
    private function scheduleRetryIfPending(int $id): void
    {
        if (!$this->driver instanceof ActionSchedulerDriver) {
            return;
        }
        $row = $this->store->findById($id);
        if ($row === null || strtoupper((string) $row->state) !== 'PENDING') {
            return;
        }
        ActionSchedulerDriver::scheduleIfMissing($id, ActionSchedulerDriver::delayUntil((string) ($row->available_at ?? '')));
    }

    private function execute(object $row): void
    {
        $id = (int) $row->id;
        $this->store->markRunning($id);
        $spec = JobSpec::fromRow($row);

        $handler = $this->handlers[$spec->hook] ?? null;
        if ($handler === null) {
            $this->store->markFailed($id, 'NO_HANDLER:' . $spec->hook);
            return;
        }

        try {
            $handler->handle($spec->args);
            $this->store->markSucceeded($id);
            do_action('fch/job_succeeded', $id, $spec->hook);
        } catch (\Throwable $e) {
            $this->store->markFailed($id, $e->getMessage());
            $this->scheduleRetryIfPending($id);
            do_action('fch/job_failed', $id, $spec->hook, $e);
        }
    }

    public static function selectDriver(JobStore $store): JobDriverInterface
    {
        $forced = self::forcedDriver();

        if ($forced === 'db') {
            return new DbQueueDriver($store);
        }
        if ($forced === 'action_scheduler' && ActionSchedulerDriver::isAvailable()) {
            return new ActionSchedulerDriver($store);
        }
        if ($forced !== 'db' && ActionSchedulerDriver::isAvailable()) {
            return new ActionSchedulerDriver($store);
        }

        return new DbQueueDriver($store);
    }

    /** @return string one of auto|action_scheduler|db */
    public static function forcedDriver(): string
    {
        $value = (string) apply_filters('fch/queue_driver', get_option('fch_queue_driver', 'auto'));
        $value = strtolower(trim($value));
        $map = [
            'db' => 'db',
            'db_queue' => 'db',
            'action_scheduler' => 'action_scheduler',
            'as' => 'action_scheduler',
            'auto' => 'auto',
            '' => 'auto',
        ];
        return $map[$value] ?? 'auto';
    }

    public function workerId(): string
    {
        $host = function_exists('gethostname') ? (string) gethostname() : 'cli';
        return substr($host . ':' . getmypid(), 0, 64);
    }

    public function leaseTtl(): int
    {
        return (int) apply_filters('fch/job_lease_ttl', 300);
    }
}
