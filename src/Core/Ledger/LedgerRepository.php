<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

use FluentCartHungary\Core\Database\Schema;

/**
 * External-effects ledger repository (05_DATA_SCHEMA §5.1, §7.1, §8). The ledger is the source of truth
 * for what happened externally; queue rows only retry. No auto create-retry semantics are baked in:
 * UNCERTAIN/AWAITING_RECONCILIATION are never claimable by the create worker.
 */
final class LedgerRepository
{
    /** Columns a guarded transition may write alongside `state`. */
    private const MUTABLE = [
        'external_id',
        'external_ref2',
        'business_reference',
        'local_error_code',
        'local_error_message',
        'next_retry_at',
        'uncertain_reason',
        'lease_owner',
        'lease_expires_at',
        'reconciled_at',
        'resolved_by',
        'resolved_note',
        'superseded_by_id',
        'request_payload_redacted',
        'response_payload_redacted',
        'amount_minor',
        'currency',
        'request_fingerprint',
        'order_fin_snapshot_hash',
        'attempt_count',
        'max_attempts',
        'owner_type',
        'owner_id',
        'order_uuid',
    ];

    private const INT_COLUMNS = [
        'order_id', 'owner_id', 'attempt_count', 'max_attempts', 'amount_minor',
        'resolved_by', 'superseded_by_id',
    ];

    public function table(): string
    {
        return Schema::table('fch_external_operations');
    }

    /**
     * Atomic get-or-create by idempotency_key. A duplicate is a no-op that returns the existing row
     * (05_DATA_SCHEMA §8.3; UT-12/IT-09). INSERT IGNORE + affected-rows is the lock.
     *
     * @return array{created:bool,row:object}
     */
    public function claim(array $attrs): array
    {
        global $wpdb;

        $required = ['idempotency_key', 'module', 'provider', 'kind', 'mode', 'account_id'];
        foreach ($required as $key) {
            if (!isset($attrs[$key]) || $attrs[$key] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required ledger field "%s".', $key));
            }
        }

        $kind = (string) $attrs['kind'];
        if (!ExternalOperation::isKnownKind($kind)) {
            throw new \InvalidArgumentException(sprintf('Unknown operation kind "%s".', $kind));
        }

        $state = (string) ($attrs['state'] ?? ExternalOperation::NOT_STARTED);
        if (!ExternalOperation::isKnownState($state)) {
            throw new \InvalidArgumentException(sprintf('Unknown operation state "%s".', $state));
        }

        $row = [
            'idempotency_key' => (string) $attrs['idempotency_key'],
            'module' => (string) $attrs['module'],
            'provider' => (string) $attrs['provider'],
            'kind' => $kind,
            'mode' => (string) $attrs['mode'],
            'account_id' => (string) $attrs['account_id'],
            'state' => $state,
            'max_attempts' => (int) ($attrs['max_attempts'] ?? 5),
        ];

        foreach (['order_id', 'order_uuid', 'owner_type', 'owner_id', 'currency', 'amount_minor', 'business_reference', 'order_fin_snapshot_hash', 'request_fingerprint'] as $optional) {
            if (array_key_exists($optional, $attrs) && $attrs[$optional] !== null) {
                $row[$optional] = $attrs[$optional];
            }
        }

        $table = $this->table();
        $columns = array_keys($row);
        $placeholders = [];
        foreach ($columns as $column) {
            $placeholders[] = $this->formatFor($column, $row[$column]);
        }
        $sql = sprintf(
            'INSERT IGNORE INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $columns),
            implode(', ', $placeholders)
        );

        $wpdb->query($wpdb->prepare($sql, ...array_values($row)));
        $created = (int) $wpdb->rows_affected === 1;

        $existing = $this->getByKey((string) $attrs['idempotency_key']);
        if ($existing === null) {
            throw new \RuntimeException('Ledger claim failed to persist or read back.');
        }

        return ['created' => $created, 'row' => $existing];
    }

    public function getByKey(string $idempotencyKey): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE idempotency_key = %s", $idempotencyKey));
        return $row ?: null;
    }

    public function getById(int $id): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
        return $row ?: null;
    }

    /** Atomic NOT_STARTED -> IN_PROGRESS claim (05_DATA_SCHEMA §8.1). */
    public function claimForWork(int $id, string $workerId, int $ttl = 300): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state = %s, lease_owner = %s, lease_expires_at = UTC_TIMESTAMP() + INTERVAL %d SECOND,
                    attempt_count = attempt_count + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = %d AND state = %s AND (lease_expires_at IS NULL OR lease_expires_at < UTC_TIMESTAMP())",
                ExternalOperation::IN_PROGRESS,
                $workerId,
                $ttl,
                $id,
                ExternalOperation::NOT_STARTED
            )
        );
        return (int) $affected === 1;
    }

    /**
     * Operator-triggered resume: clear the local backoff AND the stale worker lease so the next guarded
     * run() dispatches the SAME not-yet-started operation immediately (never a new identity, never a
     * re-dispatch of an UNCERTAIN/confirmed row).
     */
    public function clearRetryAt(int $id): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET next_retry_at = NULL, lease_owner = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = %d AND state = %s",
            $id,
            ExternalOperation::NOT_STARTED
        ));
        return $affected !== false;
    }

    /**
     * Guarded, monotonic transition. Throws on an illegal transition; returns false when a state guard
     * (or a concurrent writer) prevented the update. Same-state calls are a no-op success.
     */
    public function transition(int $id, string $to, array $extra = [], ?string $expectedFrom = null, string $profile = 'ledger'): bool
    {
        global $wpdb;
        $row = $this->getById($id);
        if ($row === null) {
            return false;
        }

        $from = (string) $row->state;
        if ($expectedFrom !== null && $from !== $expectedFrom) {
            return false;
        }
        if ($from === $to) {
            return $to === $from;
        }

        ExternalOperation::assertTransition($from, $to, $profile);

        $sets = ['state = %s', 'updated_at = UTC_TIMESTAMP()'];
        $params = [$to];
        foreach ($extra as $column => $value) {
            if (!in_array($column, self::MUTABLE, true)) {
                continue;
            }
            $sets[] = sprintf('`%s` = %s', $column, $this->formatFor($column, $value));
            $params[] = $value;
        }
        $params[] = $id;
        $params[] = $from;

        $table = $this->table();
        $sql = sprintf('UPDATE `%s` SET %s WHERE id = %%d AND state = %%s', $table, implode(', ', $sets));
        $affected = $wpdb->query($wpdb->prepare($sql, ...$params));

        return (int) $affected === 1;
    }

    /** @return array<string,int> */
    public function countByState(): array
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

    /** Unresolved UNCERTAIN operations (0 in W0). */
    public function countUnresolved(): int
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE state IN (%s, %s)",
                ExternalOperation::UNCERTAIN,
                ExternalOperation::AWAITING_RECONCILIATION
            )
        );
    }

    /** UNCERTAIN rows old enough to be swept into reconciliation (05_OPERATIONS §2.3). @return list<object> */
    public function findStaleUncertain(int $limit = 10, int $minAgeSeconds = 300): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE state = %s AND updated_at < UTC_TIMESTAMP() - INTERVAL %d SECOND ORDER BY id ASC LIMIT %d",
            ExternalOperation::UNCERTAIN,
            max(0, $minAgeSeconds),
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
    }

    /**
     * Rows an operator must see in the reconciliation queue: UNCERTAIN (read-back not yet attempted or
     * still open) and AWAITING_RECONCILIATION (read-back inconclusive). The sweeper moves stale UNCERTAIN
     * rows to AWAITING_RECONCILIATION, but the queue itself must never hide a fresh UNCERTAIN row.
     *
     * @return list<object>
     */
    public function findAwaitingReconciliation(int $limit = 50): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE state IN (%s, %s) ORDER BY id ASC LIMIT %d",
            ExternalOperation::UNCERTAIN,
            ExternalOperation::AWAITING_RECONCILIATION,
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
    }

    /** @return list<object> ledger operations attached to an order (admin read-only listing). */
    public function listForOrder(int $orderId, int $limit = 50): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY id DESC LIMIT %d",
            $orderId,
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
    }

    public function getByKeyOrFail(string $idempotencyKey): object
    {
        $row = $this->getByKey($idempotencyKey);
        if ($row === null) {
            throw new \RuntimeException('Ledger row not found: ' . $idempotencyKey);
        }
        return $row;
    }

    private function formatFor(string $column, mixed $value): string
    {
        if (in_array($column, self::INT_COLUMNS, true)) {
            return '%d';
        }
        return '%s';
    }
}
