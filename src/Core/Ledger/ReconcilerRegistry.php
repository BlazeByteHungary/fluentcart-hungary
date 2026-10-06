<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Provider reconciler registry (05_DATA_SCHEMA §7.1, §7.4). A reconciler proves the outcome of an
 * UNCERTAIN operation through a provider read API — it must never re-dispatch the create call.
 * Callable signature: fn(object $ledgerRow): ProviderCallResult (SUCCESS / REJECTED / UNCERTAIN).
 */
final class ReconcilerRegistry
{
    /** @var array<string,callable> */
    private static array $reconcilers = [];

    public static function register(string $provider, callable $reconciler): void
    {
        self::$reconcilers[$provider] = $reconciler;
    }

    public static function has(string $provider): bool
    {
        return isset(self::$reconcilers[$provider]);
    }

    public static function get(string $provider): ?callable
    {
        return self::$reconcilers[$provider] ?? null;
    }

    /** @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::$reconcilers);
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$reconcilers = [];
    }
}
