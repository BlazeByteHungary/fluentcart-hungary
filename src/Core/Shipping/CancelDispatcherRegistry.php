<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Shipping;

/**
 * Carrier cancellation dispatcher registry (W4 logistics lifecycle). Core never references carrier
 * modules (ADR-001); each carrier with a DOCUMENTED external cancellation route registers here:
 * `fn(object $ledgerRow): ProviderCallResult`.
 *
 * A registered dispatcher performs exactly ONE provider cancellation call per invocation and returns the
 * classified outcome. The dispatch callable resolves its credentials from the ORIGINAL shipment row's
 * binding (mode/account), reads its own read-back where the contract documents one, and never re-dispatches
 * after an UNCERTAIN outcome. Carriers with only label-file deletion (e.g. GLS `DeleteLabels`, PRINTED
 * status only) do NOT register a dispatcher: deleting a label file is not withdrawing a consignment.
 */
final class CancelDispatcherRegistry
{
    /** @var array<string,callable> */
    private static array $dispatchers = [];

    public static function register(string $carrier, callable $dispatcher): void
    {
        self::$dispatchers[$carrier] = $dispatcher;
    }

    public static function has(string $carrier): bool
    {
        return isset(self::$dispatchers[$carrier]);
    }

    public static function get(string $carrier): ?callable
    {
        return self::$dispatchers[$carrier] ?? null;
    }

    /** @return list<string> */
    public static function carriers(): array
    {
        return array_keys(self::$dispatchers);
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$dispatchers = [];
    }
}
