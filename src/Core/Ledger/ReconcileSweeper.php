<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Periodic safety net: finds stale UNCERTAIN ledger rows and enqueues reconciliation jobs
 * (05_OPERATIONS §2; the ledger, not the queue, is the source of truth for recovery).
 */
final class ReconcileSweeper
{
    private LedgerRepository $ledger;

    private OperationRunner $runner;

    public function __construct(?LedgerRepository $ledger = null, ?OperationRunner $runner = null)
    {
        $this->ledger = $ledger ?? new LedgerRepository();
        $this->runner = $runner ?? new OperationRunner($this->ledger);
    }

    public function sweep(int $limit = 5, int $minAgeSeconds = 300): int
    {
        $enqueued = 0;
        foreach ($this->ledger->findStaleUncertain($limit, $minAgeSeconds) as $row) {
            if ($this->runner->enqueueReconcile((int) $row->id)) {
                $enqueued++;
            }
        }
        return $enqueued;
    }
}
