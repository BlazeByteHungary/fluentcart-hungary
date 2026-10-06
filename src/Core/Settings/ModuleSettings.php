<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Settings;

/**
 * Non-secret per-module settings + the global live/test mode (05_DATA_SCHEMA §2 mode/account rows;
 * 04 CRED §3 rule 7: only non-secret identifiers may be stored unencrypted). Secrets always go through
 * CredentialStore.
 */
final class ModuleSettings
{
    public const OPTION = 'fch_module_settings';
    public const MODE_OPTION = 'fch_mode';
    public const MODES = ['live', 'test'];

    public function mode(): string
    {
        $mode = (string) get_option(self::MODE_OPTION, 'test');
        return in_array($mode, self::MODES, true) ? $mode : 'test';
    }

    public function setMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode must be live or test.');
        }
        update_option(self::MODE_OPTION, $mode, false);
    }

    public function isLive(): bool
    {
        return $this->mode() === 'live';
    }

    public function get(string $module, string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$module][$key] ?? $default;
    }

    public function set(string $module, string $key, mixed $value): void
    {
        $all = self::all();
        if (!isset($all[$module]) || !is_array($all[$module])) {
            $all[$module] = [];
        }
        $all[$module][$key] = $value;
        update_option(self::OPTION, $all, false);
    }

    /** @return array<string,mixed> */
    public function allFor(string $module): array
    {
        $all = self::all();
        $moduleSettings = $all[$module] ?? [];
        return is_array($moduleSettings) ? $moduleSettings : [];
    }

    /** Non-secret account identifier for the current mode; never a secret (04 CRED §3). */
    public function accountId(string $provider): string
    {
        $account = trim((string) $this->get($provider, 'account_id', 'default'));
        return $account !== '' ? $account : 'default';
    }

    /** Read a provider secret for the current mode/account. */
    public function secret(string $provider, string $field): ?string
    {
        return (new CredentialStore())->get($provider, $this->mode(), $this->accountId($provider), $field);
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }
}
