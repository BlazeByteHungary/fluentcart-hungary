<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Storage;

/**
 * Resolves the private artifact storage root and keeps the directory guards in one place.
 *
 * Default (0.x behaviour): `{uploads}/fch-private`. Operators who can place data outside the document
 * root should define `FCH_PRIVATE_STORAGE_DIR` (absolute path) or filter `fch/private_storage_dir`:
 * then EVERY artifact (documents and MPL labels) is stored outside the web-served tree and direct HTTP
 * exposure is impossible by construction, independent of Apache/Nginx configuration. The legacy store
 * (`{uploads}/fch-private`) is still read for pre-migration artifacts.
 */
final class PrivateStorage
{
    public const DIR = 'fch-private';

    public const GUARD_HTML = '<!-- silence is golden -->';

    /** Absolute, configured private root outside the uploads tree, or null when not configured. */
    public static function configuredRoot(): ?string
    {
        $value = defined('FCH_PRIVATE_STORAGE_DIR') ? (string) FCH_PRIVATE_STORAGE_DIR : '';
        if (function_exists('apply_filters')) {
            $value = (string) apply_filters('fch/private_storage_dir', $value);
        }
        $value = trim($value);
        // Absolute paths only: a relative value would depend on the process CWD and could silently land
        // inside the document root.
        if ($value === '' || $value[0] !== '/') {
            return null;
        }
        return rtrim(str_replace('\\', '/', $value), '/');
    }

    /** @throws \RuntimeException when the uploads directory is unavailable. */
    public static function uploadsRoot(): string
    {
        if (!function_exists('wp_upload_dir')) {
            throw new \RuntimeException('wp_upload_dir() unavailable.');
        }
        $uploads = wp_upload_dir();
        $basedir = rtrim((string) ($uploads['basedir'] ?? ''), '/');
        if ($basedir === '') {
            throw new \RuntimeException('Uploads basedir unavailable.');
        }
        return $basedir;
    }

    /** Where pre-0.2.2 installs wrote every artifact (still web-served on most hosts). */
    public static function legacyRoot(): string
    {
        return self::uploadsRoot() . '/' . self::DIR;
    }

    /** The active store root: configured private dir, else the legacy uploads dir. */
    public static function root(): string
    {
        return self::configuredRoot() ?? self::legacyRoot();
    }

    /** True when the active root is outside the uploads tree (artifacts not directly fetchable). */
    public static function isRelocated(): bool
    {
        return self::configuredRoot() !== null && self::root() !== self::legacyRoot();
    }

    public static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        if (function_exists('wp_mkdir_p')) {
            return (bool) wp_mkdir_p($dir);
        }
        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    /** index.html + .htaccess + web.config deny guards (harmless where unsupported). */
    public static function guardDir(string $dir): void
    {
        if (!is_dir($dir) || !function_exists('file_put_contents')) {
            return;
        }
        $index = rtrim($dir, '/') . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, self::GUARD_HTML);
        }
        $htaccess = rtrim($dir, '/') . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $webconfig = rtrim($dir, '/') . '/web.config';
        if (!is_file($webconfig)) {
            @file_put_contents(
                $webconfig,
                '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<configuration><system.webServer><security><authorization>'
                . '<remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" />'
                . '</authorization></security></system.webServer></configuration>' . "\n"
            );
        }
    }
}
