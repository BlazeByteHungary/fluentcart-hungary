<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Carrier;

use FluentCartHungary\Core\Storage\ArtifactCrypto;
use FluentCartHungary\Core\Storage\PrivateStorage;

/**
 * Private artifact store shared by the W2 carrier modules (GLS/DPD/Packeta/Foxpost).
 *
 * Layout: `{private root}/{carrier}/{year}/{non-guessable-name}.{ext}` — same PrivateStorage +
 * ArtifactCrypto (sealed at rest, lazy re-seal on legacy reads, guard files) contract as the W1
 * MPL/Számlázz stores. `label_ref` returned here is storage-relative and is the ONLY reference persisted
 * on the shipment row; downloads must go through a capability-checked controller.
 */
final class CarrierLabelStore
{
    public function __construct(private readonly string $carrier)
    {
    }

    public function carrier(): string
    {
        return $this->carrier;
    }

    /**
     * @return array{ref:string,sha256:string,bytes:int,path:string}
     * @throws \RuntimeException on an unavailable uploads dir or failed write.
     */
    public function store(string $filename, string $contents, string $extension = 'pdf'): array
    {
        $filename = $this->safeName($filename, $extension);
        $base = $this->baseDir();
        $dir = $base . '/' . gmdate('Y');

        if (!PrivateStorage::ensureDir($dir)) {
            throw new \RuntimeException('Unable to create private carrier label directory.');
        }
        PrivateStorage::guardDir($base);
        PrivateStorage::guardDir($dir);

        $path = $dir . '/' . $filename;
        $payload = ArtifactCrypto::seal($contents) ?? $contents;
        if (@file_put_contents($path, $payload) === false) {
            throw new \RuntimeException('Unable to write private carrier label.');
        }

        return [
            'ref' => gmdate('Y') . '/' . $filename,
            'sha256' => hash('sha256', $contents),
            'bytes' => strlen($contents),
            'path' => $path,
        ];
    }

    /** Read a stored artifact; sealed bytes are opened, plaintext is re-sealed on first read. */
    public function read(string $ref): ?string
    {
        $ref = ltrim(str_replace('..', '', $ref), '/\\');
        if ($ref === '') {
            return null;
        }
        $path = $this->baseDir() . '/' . $ref;
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
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

    public function baseDir(): string
    {
        return PrivateStorage::root() . '/' . $this->carrier;
    }

    private function safeName(string $filename, string $extension): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'label';
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin';
        $filename = rtrim($filename, '.');
        if (!str_ends_with(strtolower($filename), '.' . strtolower($extension))) {
            $filename .= '.' . $extension;
        }
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        return substr($base, 0, 160) . '-' . bin2hex(random_bytes(8)) . '.' . strtolower($extension);
    }
}
