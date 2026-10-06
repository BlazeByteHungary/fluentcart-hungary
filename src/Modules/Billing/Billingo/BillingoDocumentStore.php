<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Storage\ArtifactCrypto;
use FluentCartHungary\Core\Storage\PrivateStorage;

/**
 * Private, sealed on-disk store for Billingo document artifacts (PDF copies), same protection model as
 * the W1 Számlázz store: storage root `{uploads}/fch-private` (or FCH_PRIVATE_STORAGE_DIR), sealed with
 * the site credential key, directory guards, capability-checked downloads only. `pdf_ref` on the
 * document row is a storage-relative ref, never a public URL.
 */
final class BillingoDocumentStore
{
    /**
     * @return array{ref:string,sha256:string,bytes:int,path:string}
     * @throws \RuntimeException when the directory is unavailable or the write fails.
     */
    public function store(string $filename, string $contents, string $extension = 'pdf'): array
    {
        $filename = $this->safeName($filename, $extension);
        $base = PrivateStorage::root();
        $year = gmdate('Y');
        $dir = $base . '/' . $year;

        if (!PrivateStorage::ensureDir($dir)) {
            throw new \RuntimeException('Unable to create private document directory.');
        }
        PrivateStorage::guardDir($base);
        PrivateStorage::guardDir($dir);

        $path = $dir . '/' . $filename;
        $payload = ArtifactCrypto::seal($contents) ?? $contents;
        if (file_put_contents($path, $payload) === false) {
            throw new \RuntimeException('Unable to write private document.');
        }

        return [
            'ref' => $year . '/' . $filename,
            'sha256' => hash('sha256', $contents),
            'bytes' => strlen($contents),
            'path' => $path,
        ];
    }

    /** Read a stored artifact by its storage-relative ref (never returns sealed bytes). */
    public function read(string $ref): ?string
    {
        $ref = ltrim(str_replace('..', '', $ref), '/\\');
        if ($ref === '') {
            return null;
        }
        foreach ($this->candidatePaths($ref) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                return null;
            }
            if (ArtifactCrypto::isSealed($contents)) {
                return ArtifactCrypto::open($contents);
            }
            $sealed = ArtifactCrypto::seal($contents);
            if ($sealed !== null) {
                @file_put_contents($path, $sealed, LOCK_EX);
            }
            return $contents;
        }
        return null;
    }

    /** @return list<string> */
    private function candidatePaths(string $ref): array
    {
        $paths = [PrivateStorage::root() . '/' . $ref];
        try {
            $legacy = PrivateStorage::legacyRoot();
            if ($legacy !== PrivateStorage::root()) {
                $paths[] = $legacy . '/' . $ref;
            }
        } catch (\Throwable $e) {
            // uploads unavailable; keep the active root only
        }
        return $paths;
    }

    private function safeName(string $filename, string $extension): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'document';
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin';
        $filename = rtrim($filename, '.');
        if (!str_ends_with(strtolower($filename), '.' . strtolower($extension))) {
            $filename .= '.' . $extension;
        }
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        return substr($base, 0, 160) . '-' . bin2hex(random_bytes(8)) . '.' . strtolower($extension);
    }
}
