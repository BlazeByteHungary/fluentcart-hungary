<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Storage;

/**
 * Authenticated encryption for private document artifacts at rest (W1 access-control closure).
 *
 * `.htaccess`/`web.config`/index guards only protect artifact directories on servers that honour them;
 * a PHP dev server or an unconfigured Nginx instance would serve `uploads/fch-private/*` directly. Sealing
 * every artifact with the site's `FCH_CREDENTIALS_KEY` (HKDF-derived, XSalsa20-Poly1305 via libsodium)
 * makes the stored bytes useless even when they are reachable over HTTP, so the capability-checked
 * download controller stays the only usable access path on ANY server.
 *
 * Legacy plaintext artifacts stay readable (open() passes them through and the stores re-seal them on
 * first read); when no key or no sodium is available the old behaviour is kept so the plugin never loses
 * the ability to write a document — the diagnostics surface reports the degraded state.
 */
final class ArtifactCrypto
{
    /** 8-byte magic; the sealed layout is MAGIC || nonce(24) || secretbox ciphertext. */
    public const MAGIC = "FCHENC1\x00";
    private const NONCE_BYTES = 24;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && self::key() !== null;
    }

    /** Derive the artifact key; `$secret` exists for tests (never from user input). */
    public static function key(?string $secret = null): ?string
    {
        if ($secret === null) {
            $secret = defined('FCH_CREDENTIALS_KEY') ? (string) FCH_CREDENTIALS_KEY : '';
        }
        if ($secret === '' || !function_exists('sodium_crypto_secretbox')) {
            return null;
        }
        return hash_hkdf('sha256', $secret, 32, 'fch-artifact-v1');
    }

    /** @return string|null sealed payload, or null when sealing is unavailable (store stays plaintext). */
    public static function seal(string $plain, ?string $secret = null): ?string
    {
        $key = self::key($secret);
        if ($key === null) {
            return null;
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        return self::MAGIC . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    }

    public static function isSealed(string $raw): bool
    {
        return strncmp($raw, self::MAGIC, strlen(self::MAGIC)) === 0;
    }

    /** Returns the plaintext; pass-through for legacy plaintext, null when sealed but undecryptable. */
    public static function open(string $raw, ?string $secret = null): ?string
    {
        if (!self::isSealed($raw)) {
            return $raw;
        }
        $key = self::key($secret);
        if ($key === null) {
            return null;
        }
        $nonce = substr($raw, strlen(self::MAGIC), self::NONCE_BYTES);
        $cipher = substr($raw, strlen(self::MAGIC) + self::NONCE_BYTES);
        if (strlen($nonce) !== self::NONCE_BYTES || $cipher === '') {
            return null;
        }
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? null : $plain;
    }
}
