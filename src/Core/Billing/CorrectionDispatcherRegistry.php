<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Billing;

/**
 * Invoice-correction dispatcher registry (W4 invoicing scope). Core must not depend on provider modules,
 * so each invoicing provider registers its correction dispatcher here:
 * `fn(object $ledgerRow): ProviderCallResult`.
 *
 * The dispatcher performs exactly ONE provider call per invocation (storno / corrective / modification)
 * and must resolve the provider binding (mode/account/credentials) from the ORIGINAL document row — the
 * correction always follows the original document, never the current merchant selection. Retries and
 * read-backs are owned by the OperationRunner/reconciler machinery; this closure is never re-called on an
 * UNCERTAIN outcome.
 */
final class CorrectionDispatcherRegistry
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
