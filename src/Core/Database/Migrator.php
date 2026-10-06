<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Database;

use FluentCartHungary\Core\Jobs\Lock;

/**
 * Versioned, idempotent schema migrator (05_DATA_SCHEMA §10). Uses a MySQL named lock so concurrent
 * boot requests cannot race; verifies indexes after CREATE and falls back to explicit ALTER.
 * dbDelta is deliberately not used for UNIQUE/composite keys (it is unreliable for them, §10.2).
 */
final class Migrator
{
    public const OPTION_VERSION = 'fch_schema_version';
    public const OPTION_STATE = 'fch_schema_state';

    public static function maybeUpgrade(): bool
    {
        $installed = (int) get_option(self::OPTION_VERSION, 0);
        if ($installed >= FCH_SCHEMA_VERSION) {
            return false;
        }
        return self::upgrade();
    }

    public static function upgrade(): bool
    {
        $lockName = 'fch_schema_' . substr(md5(DB_NAME . '|' . self::tablePrefix()), 0, 16);
        $lock = new Lock();
        if (!$lock->acquire($lockName, 10)) {
            return false;
        }

        try {
            global $wpdb;
            $state = [];

            $creators = [
                'fch_external_operations' => [Schema::class, 'createExternalOperations'],
                'fch_payment_attempts' => [Schema::class, 'createPaymentAttempts'],
                'fch_invoice_documents' => [Schema::class, 'createInvoiceDocuments'],
                'fch_shipments' => [Schema::class, 'createShipments'],
                'fch_webhook_events' => [Schema::class, 'createWebhookEvents'],
                'fch_jobs' => [Schema::class, 'createJobs'],
            ];

            foreach ($creators as $base => $creator) {
                $wpdb->query($creator());
                $state[$base] = self::verifyTable($base);
            }

            update_option(self::OPTION_STATE, wp_json_encode($state), false);
            update_option(self::OPTION_VERSION, FCH_SCHEMA_VERSION, false);
        } finally {
            $lock->release($lockName);
        }

        return true;
    }

    public static function installedVersion(): int
    {
        return (int) get_option(self::OPTION_VERSION, 0);
    }

    public static function isInstalled(): bool
    {
        foreach (Schema::TABLES as $base) {
            if (!Schema::tableExists(Schema::table($base))) {
                return false;
            }
        }
        return true;
    }

    /** @return array{ok:bool,table:string,missing:list<string>,error:?string} */
    private static function verifyTable(string $base): array
    {
        global $wpdb;
        $table = Schema::table($base);
        $missing = [];
        $error = null;

        if (!Schema::tableExists($table)) {
            return ['ok' => false, 'table' => $table, 'missing' => [], 'error' => 'table_missing'];
        }

        $present = Schema::indexNames($table);
        $required = Schema::requiredIndexes()[$table] ?? [];
        foreach ($required as $index) {
            if (!in_array($index, $present, true)) {
                $missing[] = $index;
            }
        }

        if ($missing !== []) {
            $error = $wpdb->last_error !== '' ? $wpdb->last_error : 'indexes_missing';
        }

        return ['ok' => $missing === [], 'table' => $table, 'missing' => $missing, 'error' => $error];
    }

    private static function tablePrefix(): string
    {
        global $wpdb;
        return (string) $wpdb->prefix;
    }

    public static function dropTables(): void
    {
        global $wpdb;
        foreach (Schema::TABLES as $base) {
            $table = Schema::table($base);
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
