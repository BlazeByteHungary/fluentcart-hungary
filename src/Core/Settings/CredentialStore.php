<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Settings;

/**
 * Encrypted credential store (ADR-006; 05_OPERATIONS §5). Secrets are encrypted at rest with an AEAD
 * (libsodium secretbox when available, otherwise OpenSSL AES-256-GCM). The key is read only from a
 * wp-config constant / environment variable — never from the database — and is never logged.
 *
 * Key precedence: FCH_CREDENTIALS_KEY (task contract) -> FCH_CRED_KEY (ADR-006/05_OPERATIONS) -> env.
 */
final class CredentialStore
{
    public const OPTION = 'fch_credentials_v1';
    public const KEY_VERSION = 1;

    private ?string $lastError = null;

    /** @return string|null raw key material, or null when not configured (fail-closed). */
    public static function keyMaterial(): ?string
    {
        foreach (['FCH_CREDENTIALS_KEY', 'FCH_CRED_KEY'] as $constant) {
            if (defined($constant)) {
                $value = (string) constant($constant);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        foreach (['FCH_CREDENTIALS_KEY', 'FCH_CRED_KEY'] as $env) {
            $value = getenv($env);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    public static function deriveKey(string $raw): string
    {
        return hash('sha256', $raw, true);
    }

    public static function isConfigured(): bool
    {
        return self::keyMaterial() !== null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param bool $secret true for secret fields; false only for non-secret identifiers (04 CRED §3).
     * @return bool true when stored.
     */
    public function set(string $provider, string $mode, string $account, string $field, string $value, bool $secret = true): bool
    {
        $this->lastError = null;
        $record = ['v' => self::KEY_VERSION, 'secret' => $secret, 'set_at' => time(), 'data' => ''];

        if ($secret) {
            $raw = self::keyMaterial();
            if ($raw === null) {
                $this->lastError = 'credentials_key_missing';
                return false;
            }
            $record['data'] = self::encryptValue($value, self::deriveKey($raw));
        } else {
            $record['data'] = $value;
        }

        $all = self::all();
        $all[self::recordKey($provider, $mode, $account, $field)] = $record;
        update_option(self::OPTION, $all, false);
        return true;
    }

    public function get(string $provider, string $mode, string $account, string $field): ?string
    {
        $this->lastError = null;
        $record = $this->record($provider, $mode, $account, $field);
        if ($record === null) {
            return null;
        }
        if (empty($record['secret'])) {
            return (string) ($record['data'] ?? '');
        }

        $raw = self::keyMaterial();
        if ($raw === null) {
            $this->lastError = 'credentials_key_missing';
            return null;
        }

        $plain = self::decryptValue((string) ($record['data'] ?? ''), self::deriveKey($raw));
        if ($plain === null) {
            $this->lastError = 'credentials_decrypt_failed';
        }
        return $plain;
    }

    public function has(string $provider, string $mode, string $account, string $field): bool
    {
        return $this->record($provider, $mode, $account, $field) !== null;
    }

    public function delete(string $provider, string $mode, string $account, string $field): bool
    {
        $all = self::all();
        $key = self::recordKey($provider, $mode, $account, $field);
        if (!array_key_exists($key, $all)) {
            return false;
        }
        unset($all[$key]);
        update_option(self::OPTION, $all, false);
        return true;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @return array<string,mixed>|null */
    private function record(string $provider, string $mode, string $account, string $field): ?array
    {
        $all = self::all();
        $key = self::recordKey($provider, $mode, $account, $field);
        return isset($all[$key]) && is_array($all[$key]) ? $all[$key] : null;
    }

    public static function recordKey(string $provider, string $mode, string $account, string $field): string
    {
        return implode('|', [$provider, $mode, $account, $field]);
    }

    /**
     * Masked UI representation. Secret values are masked (last 4 chars at most); identifier values are
     * returned verbatim because they are not secrets. Never returns an unmasked secret.
     *
     * @return list<array{provider:string,mode:string,account:string,field:string,secret:bool,set:bool,preview:string}>
     */
    public function getMasked(): array
    {
        $out = [];
        foreach (self::all() as $key => $record) {
            $parts = explode('|', (string) $key);
            $secret = !empty($record['secret']);
            $data = (string) ($record['data'] ?? '');
            if (!$secret) {
                $preview = $data;
            } else {
                $raw = self::keyMaterial();
                $plain = $raw !== null ? self::decryptValue($data, self::deriveKey($raw)) : null;
                $preview = $plain !== null ? self::mask($plain) : '(unreadable)';
            }
            $out[] = [
                'provider' => $parts[0] ?? '',
                'mode' => $parts[1] ?? '',
                'account' => $parts[2] ?? '',
                'field' => $parts[3] ?? '',
                'secret' => $secret,
                'set' => true,
                'preview' => $preview,
            ];
        }
        return $out;
    }

    /** Show at most the last 4 characters; short secrets stay fully masked. */
    public static function mask(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('*', max(4, $length));
        }
        return '****' . substr($value, -4);
    }

    /**
     * Rotation helper (05_OPERATIONS §5.2): decrypt with current-or-previous, re-encrypt with the new key.
     *
     * @return array{rotated:int,failed:int}
     */
    public function rotate(string $newRawKey, ?string $oldRawKey = null): array
    {
        $newKey = self::deriveKey($newRawKey);
        $oldKeys = [];
        if ($oldRawKey !== null && $oldRawKey !== '') {
            $oldKeys[] = self::deriveKey($oldRawKey);
        }
        $current = self::keyMaterial();
        if ($current !== null) {
            $oldKeys[] = self::deriveKey($current);
        }

        $all = self::all();
        $rotated = 0;
        $failed = 0;
        foreach ($all as $key => $record) {
            if (empty($record['secret'])) {
                continue;
            }
            $plain = null;
            foreach (array_merge([$newKey], $oldKeys) as $candidate) {
                $plain = self::decryptValue((string) ($record['data'] ?? ''), $candidate);
                if ($plain !== null) {
                    break;
                }
            }
            if ($plain === null) {
                $failed++;
                continue;
            }
            $record['data'] = self::encryptValue($plain, $newKey);
            $record['v'] = self::KEY_VERSION;
            $record['set_at'] = time();
            $all[$key] = $record;
            $rotated++;
        }
        update_option(self::OPTION, $all, false);
        return ['rotated' => $rotated, 'failed' => $failed];
    }

    // ---- Pure crypto primitives (unit-testable without WordPress) ----

    public static function encryptValue(string $plain, string $key): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return 's1:' . base64_encode($nonce . $cipher);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('credential_encrypt_failed');
        }
        return 'o1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptValue(string $stored, string $key): ?string
    {
        if (str_starts_with($stored, 's1:')) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                return null;
            }
            $blob = base64_decode(substr($stored, 3), true);
            if ($blob === false || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }
            $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return $plain === false ? null : $plain;
        }

        if (str_starts_with($stored, 'o1:')) {
            $blob = base64_decode(substr($stored, 3), true);
            if ($blob === false || strlen($blob) <= 28) {
                return null;
            }
            $iv = substr($blob, 0, 12);
            $tag = substr($blob, 12, 16);
            $cipher = substr($blob, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? null : $plain;
        }

        return null;
    }
}
