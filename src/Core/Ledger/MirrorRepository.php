<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

use FluentCartHungary\Core\Database\Schema;

/**
 * Base for per-entity mirrors (payment_attempts, invoice_documents, shipments). It shares the guarded
 * mirror transition profile (05_DATA_SCHEMA §7.2–§7.4) and row helpers.
 */
abstract class MirrorRepository
{
    abstract protected function baseTable(): string;

    public function table(): string
    {
        return Schema::table($this->baseTable());
    }

    public function getById(int $id): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
        return $row ?: null;
    }

    /**
     * INSERT IGNORE for unique-key dedup (05_DATA_SCHEMA §8.3). Returns true when this call created the row.
     * Values must be scalar or null; arrays/objects must be JSON-encoded by the caller.
     */
    protected function insertIgnore(array $row): bool
    {
        global $wpdb;
        $table = $this->table();
        $columns = [];
        $placeholders = [];
        foreach ($row as $column => $value) {
            if (is_array($value) || is_object($value)) {
                $value = self::encodeJson($value);
            }
            $columns[] = '`' . preg_replace('/[^a-z0-9_]/i', '', (string) $column) . '`';
            $placeholders[] = is_int($value) ? '%d' : '%s';
            $row[$column] = $value;
        }
        $sql = sprintf(
            'INSERT IGNORE INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $wpdb->query($wpdb->prepare($sql, ...array_values($row)));
        return (int) $wpdb->rows_affected === 1;
    }

    /** Read one row by an exact column match. Column name is strictly sanitized. */
    protected function rowBy(string $column, mixed $value): ?object
    {
        global $wpdb;
        $column = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($column === '' || $column === null) {
            return null;
        }
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = %s LIMIT 1", $value));
        return $row ?: null;
    }

    /** JSON columns never receive raw PHP arrays (wpdb::prepare rejects arrays; JSON CHECK would fail). */
    protected static function encodeJson(mixed $value): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return (string) $json;
    }

    /** Guarded mirror transition; mirrors may supersede a proved outcome (storno/corrected/cancel). */
    protected function transitionRow(int $id, string $to, array $extra = [], ?string $expectedFrom = null, array $allowedColumns = []): bool
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
            return true;
        }

        ExternalOperation::assertTransition($from, $to, 'mirror');

        $sets = ['state = %s'];
        $params = [$to];
        foreach ($extra as $column => $value) {
            $column = (string) preg_replace('/[^a-z0-9_]/i', '', (string) $column);
            if ($column === '' || $allowedColumns !== [] && !in_array($column, $allowedColumns, true)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = self::encodeJson($value);
            }
            $sets[] = sprintf('`%s` = %%s', $column);
            $params[] = $value;
        }
        $params[] = $id;
        $params[] = $from;

        $table = $this->table();
        $sql = sprintf('UPDATE `%s` SET %s WHERE id = %%d AND state = %%s', $table, implode(', ', $sets));
        $affected = $wpdb->query($wpdb->prepare($sql, ...$params));

        return (int) $affected === 1;
    }
}
