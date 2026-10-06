<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Storage;

/**
 * Filesystem engine that protects legacy plaintext artifacts written before sealing existed (W1 closeout).
 *
 * Two moves, both resumable and idempotent:
 *  1. Relocation: when a private root outside the uploads tree is configured, the whole legacy tree is
 *     moved there in one atomic directory rename (instant and complete regardless of file count) or, when
 *     the target already exists, merged file by file. After relocation the old URLs 404 on ANY server.
 *  2. Sealing: every remaining plaintext artifact is sealed in place with ArtifactCrypto (atomic
 *     tmp+rename, read-back verification, restore on verification failure). No file is ever deleted on
 *     unproven success; failures are reported per file and never abort the whole batch.
 *
 * The engine is pure PHP (no WordPress) so the whole failure matrix is unit-testable on real files.
 */
final class ArtifactSealEngine
{
    public const GUARD_FILES = ['index.html', '.htaccess', 'web.config'];
    private const MAX_DEPTH = 4;
    private const MAX_FILE_BYTES = 67108864; // 64 MiB: above this a batch would hold the whole file in RAM

    /** Records merge-moved files that still await sealing (crash recovery without scanning foreign files). */
    private const JOURNAL_FILE = '.fch-migration-journal';

    /** Marks a target root created by an atomic tree rename: the whole tree is ours, safe to scan fully. */
    private const MARKER_FILE = '.fch-migration-relocated';

    public function __construct(
        private readonly string $legacyRoot,
        private readonly string $targetRoot,
        private readonly ?string $secret = null
    ) {
    }

    public function legacyRoot(): string
    {
        return $this->legacyRoot;
    }

    public function targetRoot(): string
    {
        return $this->targetRoot;
    }

    public function relocating(): bool
    {
        return $this->normalize($this->legacyRoot) !== $this->normalize($this->targetRoot);
    }

    /**
     * Move the legacy tree into the configured private root (once). Idempotent.
     *
     * @return array{mode:string,error:?string}
     */
    public function relocate(): array
    {
        if (!$this->relocating()) {
            return ['mode' => 'in_place', 'error' => null];
        }
        if (!is_dir($this->legacyRoot)) {
            // Legacy already gone: a previous run may have tree-renamed it (marker) and crashed before
            // sealing; the marker tells us the whole target tree is ours to scan.
            return ['mode' => $this->hasMarker() ? 'tree_rename' : 'no_source', 'error' => null];
        }

        $targetExists = is_dir($this->targetRoot);
        if (!$targetExists || $this->directoryEmpty($this->targetRoot)) {
            self::ensureDir(dirname($this->targetRoot));
            if (!$targetExists) {
                if (@rename($this->legacyRoot, $this->targetRoot)) {
                    $this->writeMarker();
                    return ['mode' => 'tree_rename', 'error' => null];
                }
            }
            // Existing-but-empty target: drop it so the tree rename can take its place.
            if ($targetExists && $this->directoryEmpty($this->targetRoot) && @rmdir($this->targetRoot)
                && @rename($this->legacyRoot, $this->targetRoot)) {
                $this->writeMarker();
                return ['mode' => 'tree_rename', 'error' => null];
            }
        }

        // Merge fallback (target occupied or cross-device): per-file move with verified copy fallback.
        // Every moved file is journaled until it is sealed, so a crash can be resumed without ever
        // scanning (and sealing) unrelated files that legitimately live in the configured root.
        $error = null;
        foreach ($this->walk($this->legacyRoot) as $rel) {
            $source = $this->legacyRoot . '/' . $rel;
            $dest = $this->targetRoot . '/' . $rel;
            if (is_file($dest)) {
                continue; // destination already holds this ref; the source copy is handled by the seal pass
            }
            self::ensureDir(dirname($dest));
            if (@rename($source, $dest)) {
                $this->appendJournal($rel);
                continue;
            }
            $bytes = @file_get_contents($source);
            if ($bytes === false) {
                $error = 'merge_read_failed:' . $rel;
                continue;
            }
            if (@file_put_contents($dest, $bytes) === false || !hash_equals(hash('sha256', $bytes), (string) @hash_file('sha256', $dest))) {
                $error = 'merge_copy_failed:' . $rel;
                continue;
            }
            $this->appendJournal($rel);
            if (!@unlink($source)) {
                $error = 'merge_unlink_failed:' . $rel;
            }
        }
        return ['mode' => 'merge', 'error' => $error];
    }

    /**
     * Relative paths of every plaintext artifact to seal.
     *
     * Roots scanned depend on how the target was reached:
     *  - in_place: only the legacy (=target) tree;
     *  - tree_rename: the target tree (the whole tree came from the legacy root);
     *  - merge: the legacy tree plus only the journaled refs in the target — an occupied target root may
     *    hold unrelated operator files that must never be touched.
     * Guard files, dotfiles and our temp files are never candidates.
     *
     * @param list<string> $journal
     * @return list<array{rel:string,root:string}>
     */
    public function scan(string $mode = 'in_place', array $journal = []): array
    {
        $found = [];
        $candidates = [];

        if ($mode === 'tree_rename') {
            if (is_dir($this->targetRoot)) {
                foreach ($this->walk($this->targetRoot) as $rel) {
                    $candidates[$rel] = $this->targetRoot;
                }
            }
        } else {
            if (is_dir($this->legacyRoot)) {
                foreach ($this->walk($this->legacyRoot) as $rel) {
                    $candidates[$rel] = $this->legacyRoot;
                }
            }
            foreach ($journal as $rel) {
                $path = $this->targetRoot . '/' . $rel;
                if (!isset($candidates[$rel]) && is_file($path)) {
                    $candidates[$rel] = $this->targetRoot;
                }
            }
        }

        foreach ($candidates as $rel => $root) {
            $first = $this->firstBytes($root . '/' . $rel, strlen(ArtifactCrypto::MAGIC));
            // Sealed files are done; every OTHER file (including unreadable ones, which sealFile
            // reports as failures) is a candidate.
            if ($first !== ArtifactCrypto::MAGIC) {
                $found[] = ['rel' => $rel, 'root' => $root];
            }
        }
        return $found;
    }

    /**
     * Seal one artifact in place. Never throws; returns a machine-readable outcome.
     *
     * @return string sealed|already|missing|unreadable|too_large|no_key|write_failed|verify_failed
     */
    public function sealFile(string $root, string $rel): string
    {
        $path = $root . '/' . $rel;
        if (!is_file($path)) {
            return 'missing';
        }

        $first = $this->firstBytes($path, strlen(ArtifactCrypto::MAGIC));
        if ($first === null) {
            return 'unreadable';
        }
        if ($first === ArtifactCrypto::MAGIC) {
            return 'already';
        }

        $size = (int) @filesize($path);
        if ($size > self::MAX_FILE_BYTES) {
            return 'too_large';
        }
        if (ArtifactCrypto::key($this->secret) === null) {
            return 'no_key';
        }

        $plain = @file_get_contents($path);
        if ($plain === false) {
            return 'unreadable';
        }
        $sealed = ArtifactCrypto::seal($plain, $this->secret);
        if ($sealed === null) {
            return 'no_key';
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $sealed, LOCK_EX) === false) {
            @unlink($tmp);
            return 'write_failed';
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return 'write_failed';
        }

        $readBack = @file_get_contents($path);
        if ($readBack === false || !ArtifactCrypto::isSealed($readBack)) {
            $this->restore($path, $plain);
            return 'verify_failed';
        }
        $opened = ArtifactCrypto::open($readBack, $this->secret);
        if ($opened === null || !hash_equals(hash('sha256', $plain), hash('sha256', $opened))) {
            $this->restore($path, $plain);
            return 'verify_failed';
        }
        return 'sealed';
    }

    /**
     * Seal up to $maxFiles artifacts within $maxSeconds. Resumable: call again until done.
     *
     * @return array{mode:string,processed:int,sealed:int,already:int,failed:list<array{ref:string,code:string}>,remaining:int,done:bool,error:?string}
     */
    public function runBatch(int $maxFiles = 50, float $maxSeconds = 2.0): array
    {
        $maxFiles = max(1, $maxFiles);
        $relocation = $this->relocate();
        $journal = $this->readJournal();
        $candidates = $this->scan((string) $relocation['mode'], $journal);
        $started = microtime(true);

        $out = [
            'mode' => (string) $relocation['mode'],
            'processed' => 0,
            'sealed' => 0,
            'already' => 0,
            'failed' => [],
            'remaining' => 0,
            'done' => false,
            'error' => $relocation['error'],
        ];

        foreach ($candidates as $candidate) {
            if ($out['processed'] >= $maxFiles || (microtime(true) - $started) >= $maxSeconds) {
                $out['remaining']++;
                continue;
            }
            $code = $this->sealFile($candidate['root'], $candidate['rel']);
            $out['processed']++;
            if ($code === 'sealed') {
                $out['sealed']++;
                $this->removeJournal($candidate['rel']);
            } elseif ($code === 'already' || $code === 'missing') {
                $out['already']++;
                $this->removeJournal($candidate['rel']);
            } else {
                $out['failed'][] = ['ref' => $candidate['rel'], 'code' => $code];
            }
        }

        $out['done'] = $out['remaining'] === 0;
        if ($out['done']) {
            $this->clearJournal();
        }
        return $out;
    }

    // ---- internals -----------------------------------------------------------------------------------

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $real = @realpath($path);
        return rtrim($real !== false ? $real : $path, '/');
    }

    private function directoryEmpty(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> relative file paths (files only, depth limited, no symlink following) */
    private function walk(string $root, string $prefix = '', int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        $out = [];
        $entries = @scandir($root);
        if ($entries === false) {
            return [];
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $path = $root . '/' . $entry;
            $rel = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $out = array_merge($out, $this->walk($path, $rel, $depth + 1));
                continue;
            }
            if (!is_file($path) || in_array($entry, self::GUARD_FILES, true)) {
                continue;
            }
            if (str_contains($entry, '.tmp-')) {
                continue;
            }
            $out[] = $rel;
        }
        return $out;
    }

    private function firstBytes(string $path, int $length): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $bytes = fread($handle, max(1, $length));
        fclose($handle);
        return $bytes === false ? null : $bytes;
    }

    /** @return list<string> */
    private function readJournal(): array
    {
        $path = $this->targetRoot . '/' . self::JOURNAL_FILE;
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '' && !str_contains($line, '..')) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    private function writeJournal(array $entries): void
    {
        $path = $this->targetRoot . '/' . self::JOURNAL_FILE;
        if ($entries === []) {
            @unlink($path);
            return;
        }
        self::ensureDir($this->targetRoot);
        @file_put_contents($path, implode("\n", $entries) . "\n", LOCK_EX);
    }

    private function appendJournal(string $rel): void
    {
        $entries = $this->readJournal();
        if (!in_array($rel, $entries, true)) {
            $entries[] = $rel;
            $this->writeJournal($entries);
        }
    }

    private function removeJournal(string $rel): void
    {
        $entries = $this->readJournal();
        if ($entries === []) {
            return;
        }
        $kept = array_values(array_filter($entries, static fn (string $entry): bool => $entry !== $rel));
        if ($kept !== $entries) {
            $this->writeJournal($kept);
        }
    }

    private function clearJournal(): void
    {
        @unlink($this->targetRoot . '/' . self::JOURNAL_FILE);
    }

    private function hasMarker(): bool
    {
        return is_file($this->targetRoot . '/' . self::MARKER_FILE);
    }

    private function writeMarker(): void
    {
        self::ensureDir($this->targetRoot);
        @file_put_contents(
            $this->targetRoot . '/' . self::MARKER_FILE,
            gmdate('c') . ' legacy tree relocated by fluentcart-hungary' . "\n",
            LOCK_EX
        );
    }

    /** Best-effort restore of the original bytes after a failed verification (never silent data loss). */
    private function restore(string $path, string $plain): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $plain, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
        @unlink($tmp);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
