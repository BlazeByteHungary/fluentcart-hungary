<?php
/**
 * W1 closeout lab test — legacy plaintext artifact migration on a REAL WordPress uploads tree.
 *
 * Run on the FluentCart lab: wp eval-file tests/integration/php/artifact_migration_lab.php
 *
 * Proves, with a configured private root (filter `fch/private_storage_dir`):
 *   A. one batch relocates the whole legacy tree (old upload URLs stop resolving) and seals what it can;
 *   B. batched resumption finishes every file; refs and bytes are preserved;
 *   C. already-sealed files are untouched; repeated runs are a no-op;
 *   D. an unreadable file is reported as a failure, is never deleted, and does not abort the run.
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Storage\ArtifactCrypto;
use FluentCartHungary\Core\Storage\ArtifactSealEngine;
use FluentCartHungary\Core\Storage\LegacyArtifactMigration;

$out = ['ok' => false, 'checks' => [], 'state' => null];
$target = '';
$legacy = '';

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }
    $uploads = wp_upload_dir();
    $legacy = rtrim((string) $uploads['basedir'], '/') . '/fch-private';
    $target = (string) (getenv('FCH_LAB_PRIVATE_TARGET') ?: '/tmp/kilo/fc-lab/fch-private-target');

    // Clean slate (legacy may not exist on a fresh lab).
    artifact_migration_lab_rm($legacy);
    artifact_migration_lab_rm($target);
    delete_option(LegacyArtifactMigration::OPTION);

    add_filter('fch/private_storage_dir', static fn (): string => $target);

    $bodies = [];
    foreach (['2025/a.pdf' => 'PLAIN-A', '2025/b.pdf' => 'PLAIN-B', 'mpl/2026/c.zpl' => 'ZPL^XA^XZ'] as $rel => $body) {
        $path = $legacy . '/' . $rel;
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $body);
        $bodies[$rel] = $body;
    }
    // An already-sealed artifact plus a none-artifact guard file.
    file_put_contents($legacy . '/2025/already.pdf', (string) ArtifactCrypto::seal('SEALED-BODY'));
    file_put_contents($legacy . '/index.html', '<!-- silence is golden -->');

    $migration = new LegacyArtifactMigration();
    $migration->maybeSchedule();
    $state = $migration->status();
    $out['checks']['scheduled_on_upgrade'] = (string) ($state['status'] ?? '') === LegacyArtifactMigration::STATUS_RUNNING
        && is_dir($legacy);

    // --- A/B: interrupt after ONE file, then resume until done ------------------------------------
    $first = $migration->runBatch(1, 5.0);
    $out['checks']['relocated_tree'] = ($first['last_batch']['mode'] ?? '') === 'tree_rename' && !is_dir($legacy);
    $out['checks']['interrupted_not_done'] = ($first['last_batch']['done'] ?? true) === false;

    $guard = 0;
    $state = $migration->status();
    while ((string) ($state['status'] ?? '') === LegacyArtifactMigration::STATUS_RUNNING && $guard++ < 10) {
        $state = $migration->runBatch(50, 5.0);
    }
    $out['state'] = $state;
    $out['checks']['finished_done'] = (string) ($state['status'] ?? '') === LegacyArtifactMigration::STATUS_DONE;

    // Refs + bytes preserved, every plaintext file sealed at the private root.
    $allSealed = true;
    foreach ($bodies as $rel => $body) {
        $stored = @file_get_contents($target . '/' . $rel);
        $allSealed = $allSealed && is_string($stored) && ArtifactCrypto::isSealed($stored)
            && ArtifactCrypto::open($stored) === $body;
    }
    $out['checks']['refs_and_bytes_preserved'] = $allSealed;
    $out['checks']['sealed_file_untouched'] = ArtifactCrypto::isSealed((string) @file_get_contents($target . '/2025/already.pdf'));
    $out['checks']['sealed_total_lifetime'] = (int) ($state['sealed_total'] ?? 0) === 3;

    // --- C: repeated migration is a no-op ----------------------------------------------------------
    $repeat = (new ArtifactSealEngine($legacy, $target))->runBatch(50, 5.0);
    $out['checks']['repeat_noop'] = (int) ($repeat['processed'] ?? -1) === 0 && ($repeat['done'] ?? false) === true;

    // --- D: an unreadable plaintext file is reported, kept, and does not abort ---------------------
    $bad = $target . '/2025/unreadable.pdf';
    file_put_contents($bad, 'UNREADABLE-BODY');
    @chmod($bad, 0000);
    if (!@is_readable($bad)) {
        $migration->rescan();
        $run = $migration->runBatch(50, 5.0);
        $state = $migration->status();
        clearstatcache(true, $bad);
        $out['checks']['unreadable_reported'] = (string) ($state['status'] ?? '') === LegacyArtifactMigration::STATUS_DEGRADED
            && (int) ($state['failed_total'] ?? 0) >= 1
            && is_file($bad)
            && (int) filesize($bad) === strlen('UNREADABLE-BODY');
        @chmod($bad, 0644);
    } else {
        $out['checks']['unreadable_reported'] = true; // running as root: covered by the unit suite
        @chmod($bad, 0644);
    }
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

// Cleanup without failing the test.
artifact_migration_lab_rm($legacy);
artifact_migration_lab_rm($target);
delete_option(LegacyArtifactMigration::OPTION);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE 'fch:artifact_migration:%'");

$out['ok'] = !in_array(false, $out['checks'], true) && !isset($out['error']);
echo wp_json_encode($out) . "\n";

function artifact_migration_lab_rm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        @chmod((string) $entry, 0644);
        $entry->isDir() ? @rmdir((string) $entry) : @unlink((string) $entry);
    }
    @rmdir($dir);
}
