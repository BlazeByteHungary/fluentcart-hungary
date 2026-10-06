<?php
declare(strict_types=1);

use FluentCartHungary\Core\Storage\ArtifactCrypto;
use FluentCartHungary\Core\Storage\ArtifactSealEngine;

/**
 * Legacy plaintext artifact migration engine (W1 closeout item 1): relocation, sealing, batching,
 * resumability, corruption and missing-key handling — all on real files, no WordPress.
 */

function fch_lm_dir(): string
{
    $dir = sys_get_temp_dir() . '/fch-lm-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    return $dir;
}

function fch_lm_rm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir((string) $entry) : @unlink((string) $entry);
    }
    @rmdir($dir);
}

function fch_lm_put(string $root, string $rel, string $bytes): string
{
    $path = $root . '/' . $rel;
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $bytes);
    return $path;
}

fch_test('UT-LM-01 plaintext sealed in place, refs and bytes preserved', function (): void {
    $root = fch_lm_dir();
    try {
        $plain = "%PDF-1.4\nlegacy invoice 4240 Ft\n%%EOF\n";
        $path = fch_lm_put($root, '2026/invoice-abc.pdf', $plain);
        $engine = new ArtifactSealEngine($root, $root, 'migration-secret');
        $result = $engine->runBatch(50, 5.0);

        assert_same('in_place', $result['mode']);
        assert_same(1, $result['sealed']);
        assert_true($result['done'], 'single file completes in one batch');
        $stored = (string) file_get_contents($path);
        assert_true(ArtifactCrypto::isSealed($stored), 'stored bytes are sealed');
        assert_same($plain, ArtifactCrypto::open($stored, 'migration-secret'));
    } finally {
        fch_lm_rm($root);
    }
});

fch_test('UT-LM-02 relocation moves the whole legacy tree, old URL path is gone', function (): void {
    $legacy = fch_lm_dir();
    $target = fch_lm_dir() . '/private';
    try {
        $plain = "%PDF-1.4\nlabel\n%%EOF\n";
        fch_lm_put($legacy, '2025/a.pdf', $plain);
        fch_lm_put($legacy, 'mpl/2025/b.pdf', 'ZPL^XA^XZ');
        $engine = new ArtifactSealEngine($legacy, $target, 'migration-secret');
        $result = $engine->runBatch(50, 5.0);

        assert_same('tree_rename', $result['mode']);
        assert_true(!file_exists($legacy . '/2025/a.pdf'), 'legacy URL path no longer exists');
        assert_true(is_file($target . '/2025/a.pdf'), 'relative ref preserved under the private root');
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($target . '/2025/a.pdf')));
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($target . '/mpl/2025/b.pdf')));
        assert_same(2, $result['sealed']);
    } finally {
        fch_lm_rm($legacy);
        fch_lm_rm(dirname($target));
    }
});

fch_test('UT-LM-03 repeated migration is a no-op (idempotent)', function (): void {
    $root = fch_lm_dir();
    try {
        fch_lm_put($root, '2026/a.pdf', 'legacy');
        $engine = new ArtifactSealEngine($root, $root, 'migration-secret');
        $engine->runBatch(50, 5.0);

        $again = $engine->runBatch(50, 5.0);
        assert_same(0, $again['processed']);
        assert_same(0, $again['sealed']);
        assert_true($again['done']);
        assert_true($again['failed'] === [], 'no failures on the second pass');
    } finally {
        fch_lm_rm($root);
    }
});

fch_test('UT-LM-04 interrupted migration resumes and finishes without loss', function (): void {
    $root = fch_lm_dir();
    try {
        $bodies = [];
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $bodies[$name] = 'payload-' . $name;
            fch_lm_put($root, '2026/' . $name . '.pdf', $bodies[$name]);
        }
        $engine = new ArtifactSealEngine($root, $root, 'migration-secret');

        $first = $engine->runBatch(1, 5.0);
        assert_true(!$first['done'], 'first batch stops after one file');
        assert_same(1, $first['processed']);
        assert_same(3, $first['remaining']);

        $guard = 0;
        while (!$first['done'] && $guard++ < 10) {
            $first = $engine->runBatch(1, 5.0);
        }
        assert_true($first['done'], 'migration completes across batches');
        foreach ($bodies as $name => $body) {
            $stored = (string) file_get_contents($root . '/2026/' . $name . '.pdf');
            assert_true(ArtifactCrypto::isSealed($stored), $name . ' sealed');
            assert_same($body, ArtifactCrypto::open($stored, 'migration-secret'), $name . ' bytes preserved');
        }
    } finally {
        fch_lm_rm($root);
    }
});

fch_test('UT-LM-05 missing key leaves every byte untouched and is reported per file', function (): void {
    $root = fch_lm_dir();
    try {
        $plain = 'no-key-plaintext';
        $path = fch_lm_put($root, '2026/a.pdf', $plain);
        $engine = new ArtifactSealEngine($root, $root, null);
        $result = $engine->runBatch(50, 5.0);

        assert_same('no_key', $result['failed'][0]['code'] ?? null);
        assert_same($plain, (string) file_get_contents($path), 'file must not be modified without a key');
        assert_true($result['done'], 'all candidates were attempted');
    } finally {
        fch_lm_rm($root);
    }
});

fch_test('UT-LM-06 an unreadable file is reported, never deleted, and does not abort the batch', function (): void {
    $root = fch_lm_dir();
    try {
        $good = fch_lm_put($root, '2026/good.pdf', 'good-bytes');
        $bad = fch_lm_put($root, '2026/bad.pdf', 'bad-bytes');
        @chmod($bad, 0000);
        if (@is_readable($bad)) {
            // running as root: chmod cannot simulate a read failure
            chmod($bad, 0644);
            fch_lm_put($root, '2026/bad.pdf', 'good-bytes');
            return;
        }
        $engine = new ArtifactSealEngine($root, $root, 'migration-secret');
        $result = $engine->runBatch(50, 5.0);

        assert_true(in_array($result['failed'][0]['code'] ?? '', ['unreadable', 'write_failed'], true), 'failure recorded');
        assert_true(is_file($bad), 'failed file is never deleted');
        clearstatcache(true, $bad);
        assert_same(9, (int) @filesize($bad), 'failed file bytes unchanged');
        @chmod($bad, 0644);
        assert_same('bad-bytes', (string) file_get_contents($bad));
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($good)), 'other files still sealed');
    } finally {
        @chmod($root . '/2026/bad.pdf', 0644);
        fch_lm_rm($root);
    }
});

fch_test('UT-LM-08 crash after tree rename: next run finds and seals the relocated plaintext', function (): void {
    $legacy = fch_lm_dir();
    $target = fch_lm_dir() . '/private';
    try {
        fch_lm_put($legacy, '2026/a.pdf', 'plain-a');
        fch_lm_put($legacy, 'mpl/2026/b.pdf', 'plain-b');

        // Run 1 without a key: relocation succeeds, sealing cannot (simulates a crash right after the move).
        $first = (new ArtifactSealEngine($legacy, $target, null))->runBatch(50, 5.0);
        assert_same('tree_rename', $first['mode']);
        assert_true(!file_exists($legacy), 'legacy tree gone after the atomic move');

        // Run 2 with the key: the marker makes the target tree fully scannable, files get sealed.
        $second = (new ArtifactSealEngine($legacy, $target, 'migration-secret'))->runBatch(50, 5.0);
        assert_same('tree_rename', $second['mode'], 'marker keeps the relocated tree in scope');
        assert_same(2, $second['sealed']);
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($target . '/2026/a.pdf')));
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($target . '/mpl/2026/b.pdf')));
    } finally {
        fch_lm_rm($legacy);
        fch_lm_rm(dirname($target));
    }
});

fch_test('UT-LM-09 crash mid-merge: the journal drives resumption without touching foreign files', function (): void {
    $legacy = fch_lm_dir();
    $target = fch_lm_dir();
    try {
        // Simulate: previous run moved the artifact (journal written) then died before sealing.
        fch_lm_put($target, 'foreign.txt', 'do-not-touch');
        fch_lm_put($target, '2026/moved.pdf', 'moved-plain');
        file_put_contents($target . '/.fch-migration-journal', "2026/moved.pdf\n");

        $engine = new ArtifactSealEngine($legacy, $target, 'migration-secret');
        $result = $engine->runBatch(50, 5.0);

        assert_same(1, $result['sealed']);
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($target . '/2026/moved.pdf')));
        assert_same('do-not-touch', (string) file_get_contents($target . '/foreign.txt'), 'foreign file untouched');
        assert_true(!file_exists($target . '/.fch-migration-journal'), 'journal cleared once sealed');
    } finally {
        fch_lm_rm($legacy);
        fch_lm_rm($target);
    }
});

fch_test('UT-LM-07 merge into an occupied target preserves both trees and skips temp files', function (): void {
    $legacy = fch_lm_dir();
    $target = fch_lm_dir();
    try {
        fch_lm_put($target, 'keep.txt', 'unrelated');
        fch_lm_put($target, 'index.html', '<!-- guard -->');
        fch_lm_put($legacy, '2026/new.pdf', 'new-bytes');
        fch_lm_put($legacy, '2026/crashed.pdf.tmp-deadbeef', 'partial-write');
        $engine = new ArtifactSealEngine($legacy, $target, 'migration-secret');
        $result = $engine->runBatch(50, 5.0);

        assert_same('merge', $result['mode']);
        assert_same('unrelated', (string) file_get_contents($target . '/keep.txt'), 'existing target content untouched');
        assert_same('<!-- guard -->', (string) file_get_contents($target . '/index.html'));
        assert_true(!file_exists($legacy . '/2026/new.pdf'), 'legacy file moved');
        assert_true(ArtifactCrypto::isSealed((string) file_get_contents($target . '/2026/new.pdf')));
        assert_same(1, $result['sealed']);
        assert_true(!is_file($target . '/2026/crashed.pdf.tmp-deadbeef'), 'temp files are neither moved nor sealed');
    } finally {
        fch_lm_rm($legacy);
        fch_lm_rm($target);
    }
});
