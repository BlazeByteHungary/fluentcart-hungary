<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Payments;

/**
 * Refund dispatcher registry (W4_EXECUTION_PLAN §4). Core must not depend on provider modules, so a
 * provider registers its refund dispatcher here: `fn(object $ledgerRow): ProviderCallResult`. The
 * dispatcher must be pure call-once — retries and reads are handled by the OperationRunner/reconciler
 * machinery, never by calling this closure again after an uncertain outcome.
 */
final class RefundDispatcherRegistry
{
    /** @var array<string,callable> */
    private static array $dispatchers = [];

    public static function register(string $provider, callable $dispatcher): void
    {
        self::$dispatchers[$provider] = $dispatcher;
    }

    public static function has(string $provider): bool
    {
        return isset(self::$dispatchers[$provider]);
    }

    public static function get(string $provider): ?callable
    {
        return self::$dispatchers[$provider] ?? null;
    }

    /** @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::$dispatchers);
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$dispatchers = [];
    }
}
