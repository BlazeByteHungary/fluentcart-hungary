<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Storage\ArtifactCrypto;
use FluentCartHungary\Core\Storage\PrivateStorage;

/**
 * Private on-disk store for MPL label/manifest artifacts (PDF base64 or ZPL text from MPL-006/MPL-009/MPL-012).
 *
 * Storage root: `{private root}/mpl/{year}/` (04_MPL_CONTRACT.md §3.4 labels are returned inline on
 * create; §9 labels/manifests must be stored privately). The private root is `{uploads}/fch-private` by
 * default or the operator-configured `FCH_PRIVATE_STORAGE_DIR` outside the document root (PrivateStorage).
 * Artifacts are sealed at rest with ArtifactCrypto (labels carry PII), every directory additionally gets
 * `index.html`, `.htaccess` and `web.config` guards, and downloads must go through a capability-checked
 * controller (invariant §1.6). `label_ref` on the shipment row is the storage-relative ref returned here —
 * never a URL (05_DATA_SCHEMA §5.4). Legacy artifacts are still read from `{uploads}/fch-private/mpl`.
 */
final class MplLabelStore
{
    public const DIR = PrivateStorage::DIR . '/mpl';

    public const GUARD = PrivateStorage::GUARD_HTML;

    /**
     * @return array{ref:string,sha256:string,bytes:int,path:string}
     * @throws \RuntimeException when the uploads directory is unavailable or the write fails.
     */
    public function store(string $filename, string $contents, string $extension = 'pdf'): array
    {
        $filename = $this->safeName($filename, $extension);
        $base = $this->baseDir();
        $year = gmdate('Y');
        $dir = $base . '/' . $year;

        if (!PrivateStorage::ensureDir($dir)) {
            throw new \RuntimeException('Unable to create private MPL label directory.');
        }
        PrivateStorage::guardDir($this->rootDir());
        PrivateStorage::guardDir($base);
        PrivateStorage::guardDir($dir);

        $path = $dir . '/' . $filename;
        $payload = ArtifactCrypto::seal($contents) ?? $contents;
        if (function_exists('file_put_contents') && file_put_contents($path, $payload) === false) {
            throw new \RuntimeException('Unable to write private MPL label.');
        }

        return [
            'ref' => $year . '/' . $filename,
            'sha256' => hash('sha256', $contents),
            'bytes' => strlen($contents),
            'path' => $path,
        ];
    }

    /**
     * Read a previously stored artifact by its storage-relative ref (never creates; returns the plaintext
     * and re-seals legacy plaintext files on first read).
     */
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
            if ($sealed !== null && function_exists('file_put_contents')) {
                @file_put_contents($path, $sealed, LOCK_EX);
            }
            return $contents;
        }
        return null;
    }

    public function baseDir(): string
    {
        return $this->rootDir() . '/mpl';
    }

    private function rootDir(): string
    {
        return PrivateStorage::root();
    }

    /** @return list<string> active root first, then the legacy uploads root when relocated */
    private function candidatePaths(string $ref): array
    {
        $paths = [$this->baseDir() . '/' . $ref];
        try {
            $legacy = PrivateStorage::legacyRoot();
            if ($legacy !== PrivateStorage::root()) {
                $paths[] = $legacy . '/mpl/' . $ref;
            }
        } catch (\Throwable $e) {
            // uploads unavailable; keep the active root only
        }
        return $paths;
    }

    private function safeName(string $filename, string $extension): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'label';
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin';
        $filename = rtrim($filename, '.');
        if (!str_ends_with(strtolower($filename), '.' . strtolower($extension))) {
            $filename .= '.' . $extension;
        }
        // Non-guessable name: labels carry recipient addresses (PII); .htaccess denies direct access
        // where honoured and downloads go through a capability-checked controller.
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        $token = bin2hex(random_bytes(8));
        return substr($base, 0, 160) . '-' . $token . '.' . strtolower($extension);
    }
}
