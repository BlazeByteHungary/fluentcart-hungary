<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Webhooks;

use FluentCartHungary\Core\Database\Schema;

/**
 * Webhook ingress store (05_DATA_SCHEMA §5.5, §7.5). Order-independent: ingest only records; a job
 * resolves the target by business keys later. Ingestion is deduplicated by the UNIQUE
 * (provider,mode,account_id,dedup_key) key; a duplicate insert is answered with `duplicate=true` and the
 * original row is returned (HTTP 200 either way — invariant §1.3).
 */
final class WebhookEventRepository
{
    public const RECEIVED = 'RECEIVED';
    public const PROCESSED = 'PROCESSED';
    public const DUPLICATE = 'DUPLICATE';
    public const IGNORED = 'IGNORED';
    public const FAILED = 'FAILED';

    public function table(): string
    {
        return Schema::table('fch_webhook_events');
    }

    /**
     * @return array{created:bool,duplicate:bool,row:object}
     */
    public function ingest(array $attrs): array
    {
        foreach (['provider', 'mode', 'account_id', 'dedup_key'] as $key) {
            if (!isset($attrs[$key]) || $attrs[$key] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required webhook field "%s".', $key));
            }
        }

        $rawBody = (string) ($attrs['raw_body'] ?? '');
        $headers = $attrs['headers_json'] ?? null;
        if (is_array($headers)) {
            $headers = (string) wp_json_encode($headers);
        }

        $row = [
            'provider' => (string) $attrs['provider'],
            'mode' => (string) $attrs['mode'],
            'account_id' => (string) $attrs['account_id'],
            'dedup_key' => (string) $attrs['dedup_key'],
            'payload_hash' => hash('sha256', $rawBody),
            'raw_body' => $rawBody,
        ];
        if ($headers !== null) {
            $row['headers_json'] = $headers;
        }
        if (array_key_exists('signature_valid', $attrs) && $attrs['signature_valid'] !== null) {
            $row['signature_valid'] = $attrs['signature_valid'] ? 1 : 0;
        }
        foreach (['event_type', 'external_ref', 'order_id'] as $optional) {
            if (array_key_exists($optional, $attrs) && $attrs[$optional] !== null) {
                $row[$optional] = $attrs[$optional];
            }
        }

        $created = $this->insertIgnore($row);
        $existing = $this->getByDedup(
            (string) $attrs['provider'],
            (string) $attrs['mode'],
            (string) $attrs['account_id'],
            (string) $attrs['dedup_key']
        );
        if ($existing === null) {
            throw new \RuntimeException('Webhook ingest failed to persist or read back.');
        }

        return ['created' => $created, 'duplicate' => !$created, 'row' => $existing];
    }

    public function getById(int $id): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
        return $row ?: null;
    }

    public function getByDedup(string $provider, string $mode, string $accountId, string $dedupKey): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE provider = %s AND mode = %s AND account_id = %s AND dedup_key = %s LIMIT 1",
            $provider,
            $mode,
            $accountId,
            $dedupKey
        ));
        return $row ?: null;
    }

    public function markProcessed(int $id): bool
    {
        return $this->setState($id, self::PROCESSED);
    }

    public function markIgnored(int $id): bool
    {
        return $this->setState($id, self::IGNORED);
    }

    /** Transient handler failure: stay RECEIVED with an incremented attempt counter. */
    public function touchRetry(int $id, string $error): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET process_attempts = process_attempts + 1, last_error = %s WHERE id = %d AND state = %s",
            $error,
            $id,
            self::RECEIVED
        ));
        return (int) $affected === 1;
    }

    public function markFailed(int $id, string $error): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET state = %s, process_attempts = process_attempts + 1, last_error = %s, processed_at = UTC_TIMESTAMP() WHERE id = %d AND state = %s",
            self::FAILED,
            $error,
            $id,
            self::RECEIVED
        ));
        return (int) $affected === 1;
    }

    /** @return list<object> */
    public function pending(int $limit = 20): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE state = %s ORDER BY id ASC LIMIT %d",
            self::RECEIVED,
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
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

    /** Raw-body retention prune (05_DATA_SCHEMA §10.4; default 90 days is applied by the caller). */
    public function pruneRawBodies(int $olderThanDays = 90): int
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET raw_body = NULL, headers_json = NULL WHERE received_at < UTC_TIMESTAMP() - INTERVAL %d DAY AND raw_body IS NOT NULL",
            max(1, $olderThanDays)
        ));
        return (int) $affected;
    }

    private function setState(int $id, string $state): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET state = %s, processed_at = UTC_TIMESTAMP() WHERE id = %d AND state = %s",
            $state,
            $id,
            self::RECEIVED
        ));
        return (int) $affected === 1;
    }

    /** INSERT IGNORE dedup by the UNIQUE (provider,mode,account,dedup_key) key. */
    private function insertIgnore(array $row): bool
    {
        global $wpdb;
        $table = $this->table();
        $columns = [];
        $placeholders = [];
        foreach ($row as $column => $value) {
            $columns[] = '`' . preg_replace('/[^a-z0-9_]/i', '', (string) $column) . '`';
            $placeholders[] = is_int($value) ? '%d' : '%s';
        }
        $sql = sprintf('INSERT IGNORE INTO `%s` (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $placeholders));
        $wpdb->query($wpdb->prepare($sql, ...array_values($row)));
        return (int) $wpdb->rows_affected === 1;
    }
}
