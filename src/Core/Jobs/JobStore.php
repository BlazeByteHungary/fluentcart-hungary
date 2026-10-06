<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

use FluentCartHungary\Core\Database\Schema;

/**
 * fch_jobs persistence and atomic claim/lease logic (05_DATA_SCHEMA §5.6, §8.1; ADR-005).
 * This is the driver-agnostic job record: both the Action Scheduler driver and the DB driver use it.
 */
final class JobStore
{
    /** @var list<int> backoff seconds per attempt (05_OPERATIONS §2.4) */
    private const BACKOFF = [60, 300, 1800, 7200, 43200, 86400];

    public function table(): string
    {
        return Schema::table('fch_jobs');
    }

    public function insert(JobSpec $spec): bool
    {
        global $wpdb;
        $table = $this->table();
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (job_key, hook, args_json, state, priority, max_attempts, available_at, scheduled_by)
             VALUES (%s, %s, %s, 'PENDING', %d, %d, UTC_TIMESTAMP(), %s)",
            $spec->jobKey,
            $spec->hook,
            $spec->argsJson(),
            $spec->priority,
            $spec->maxAttempts,
            $spec->scheduledBy
        );
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected === 1;
    }

    public function findById(int $id): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
        return $row ?: null;
    }

    public function findByKey(string $jobKey): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE job_key = %s", $jobKey));
        return $row ?: null;
    }

    /**
     * Atomically claim the next due PENDING job (SELECT then guarded UPDATE; affected-rows is the lock, §8.1).
     */
    public function claimNext(string $workerId, int $leaseTtl = 300): ?object
    {
        global $wpdb;
        $table = $this->table();

        for ($i = 0; $i < 5; $i++) {
            $candidate = $wpdb->get_row(
                "SELECT * FROM `{$table}` WHERE state = 'PENDING' AND available_at <= UTC_TIMESTAMP() ORDER BY priority ASC, id ASC LIMIT 1"
            );
            if (!$candidate) {
                return null;
            }

            $claimed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET state = 'RESERVED', lease_owner = %s, lease_expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND,
                        attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
                     WHERE id = %d AND state = 'PENDING' AND available_at <= UTC_TIMESTAMP()",
                    $workerId,
                    $leaseTtl,
                    (int) $candidate->id
                )
            );

            if ((int) $claimed === 1) {
                return $this->findById((int) $candidate->id);
            }
        }

        return null;
    }

    public function markRunning(int $id): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET state = 'RUNNING', updated_at = UTC_TIMESTAMP() WHERE id = %d", $id));
    }

    public function markSucceeded(int $id): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state = 'SUCCEEDED', completed_at = UTC_TIMESTAMP(), lease_owner = NULL, lease_expires_at = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = %d",
                $id
            )
        );
    }

    /** Retry with backoff, or FAILED when attempts are exhausted. */
    public function markFailed(int $id, string $error): void
    {
        global $wpdb;
        $table = $this->table();
        $row = $this->findById($id);
        if (!$row) {
            return;
        }

        $attempts = (int) $row->attempts;
        $maxAttempts = (int) $row->max_attempts;
        $message = substr($error, 0, 4000);

        if ($attempts >= $maxAttempts) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET state = 'FAILED', last_error = %s, lease_owner = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = %d",
                    $message,
                    $id
                )
            );
            return;
        }

        $delay = self::backoffDelay($attempts);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state = 'PENDING', last_error = %s, available_at = UTC_TIMESTAMP() + INTERVAL %d SECOND,
                    lease_owner = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = %d",
                $message,
                $delay,
                $id
            )
        );
    }

    /**
     * Crash recovery with identity: the ids of the recovered rows, so the async driver can re-schedule
     * a pump for each (releaseExpired() alone leaves them PENDING with no future trigger).
     *
     * @return list<int>
     */
    public function releaseExpiredIds(): array
    {
        global $wpdb;
        $table = $this->table();
        $ids = $wpdb->get_col(
            "SELECT id FROM `{$table}` WHERE state IN ('RESERVED','RUNNING') AND lease_expires_at IS NOT NULL AND lease_expires_at < UTC_TIMESTAMP()"
        );
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $this->releaseExpired();
        return array_map('intval', $ids);
    }

    /** Crash recovery: expired RESERVED/RUNNING leases return to PENDING (attempts preserved, §2.3). */
    public function releaseExpired(): int
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query(
            "UPDATE `{$table}` SET state = 'PENDING', lease_owner = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE state IN ('RESERVED','RUNNING') AND lease_expires_at IS NOT NULL AND lease_expires_at < UTC_TIMESTAMP()"
        );
        return (int) $affected;
    }

    /** @return array<string,int> */
    public function countsByState(): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results("SELECT state, COUNT(*) AS c FROM `{$table}` GROUP BY state", ARRAY_A);
        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['state']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Reopen a TERMINAL job for a fresh operator-triggered attempt under the SAME key (one business
     * identity per intent; parallel duplicate submissions therefore share one row and one claim).
     * Only terminal states are reopened — a queued/running row keeps deduplicating as-is.
     */
    public function reopen(int $id): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET state = 'PENDING', attempts = 0, last_error = NULL, available_at = UTC_TIMESTAMP(),
                lease_owner = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND state IN ('SUCCEEDED','FAILED','DEAD_LETTER')",
            $id
        ));
        return (int) $affected === 1;
    }

    public function deadLetter(int $id, string $reason = 'manual'): void
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET state = 'DEAD_LETTER', last_error = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d", $reason, $id));
    }

    public static function backoffDelay(int $attempts): int
    {
        $index = max(0, min($attempts - 1, count(self::BACKOFF) - 1));
        $base = self::BACKOFF[$index];
        $jitter = (int) round($base * (0.8 + (mt_rand(0, 40) / 100)));
        return max(1, $jitter);
    }
}
