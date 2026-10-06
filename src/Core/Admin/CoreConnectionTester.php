<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Database\Schema;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Settings\CredentialStore;

/**
 * W0 core self-test: verifies DB tables, queue driver and credential crypto. No provider HTTP.
 */
final class CoreConnectionTester implements ConnectionTester
{
    public function id(): string
    {
        return 'core';
    }

    public function label(): string
    {
        return 'Core (tables, queue, crypto)';
    }

    public function run(): array
    {
        $checks = [];

        $tablesOk = true;
        foreach (Schema::TABLES as $base) {
            $table = Schema::table($base);
            $exists = Schema::tableExists($table);
            $tablesOk = $tablesOk && $exists;
            $checks[] = [
                'id' => 'table:' . $base,
                'ok' => $exists,
                'message' => $exists ? 'present' : 'missing',
            ];
        }

        $runner = JobRunner::instance();
        $driver = $runner->driverName();
        $jobsTable = Schema::table('fch_jobs');
        $jobsExists = Schema::tableExists($jobsTable);
        $checks[] = [
            'id' => 'queue',
            'ok' => $jobsExists,
            'message' => 'driver=' . $driver . ', jobs_table=' . ($jobsExists ? 'present' : 'missing'),
        ];

        $checks[] = $this->cryptoCheck();

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }

        return ['ok' => $ok, 'label' => $this->label(), 'checks' => $checks];
    }

    /** @return array{id:string,ok:bool,message:string} */
    private function cryptoCheck(): array
    {
        if (!CredentialStore::isConfigured()) {
            return ['id' => 'crypto', 'ok' => false, 'message' => 'credentials_key_missing (fail-closed)'];
        }

        try {
            $key = CredentialStore::deriveKey((string) CredentialStore::keyMaterial());
            $probe = 'fch-selftest-' . bin2hex(random_bytes(8));
            $cipher = CredentialStore::encryptValue($probe, $key);
            $plain = CredentialStore::decryptValue($cipher, $key);
            $roundTrip = ($plain === $probe);
            $opaque = !str_contains($cipher, $probe);
            return [
                'id' => 'crypto',
                'ok' => $roundTrip && $opaque,
                'message' => $roundTrip && $opaque ? 'aead round-trip ok' : 'aead round-trip failed',
            ];
        } catch (\Throwable $e) {
            return ['id' => 'crypto', 'ok' => false, 'message' => 'crypto_error:' . $e->getMessage()];
        }
    }
}
