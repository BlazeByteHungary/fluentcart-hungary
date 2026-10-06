<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

/**
 * Static registry of pickup-feed providers (ADR-001: the PickupPoints module never hard-references a
 * carrier module; carriers register their provider in `registerHooks()`).
 */
final class PickupFeedRegistry
{
    /** @var array<string,PickupFeedProviderInterface> */
    private static array $providers = [];

    public static function register(PickupFeedProviderInterface $provider): void
    {
        $carrier = function_exists('sanitize_key') ? sanitize_key($provider->carrier()) : (string) preg_replace('/[^a-z0-9_]/', '', strtolower($provider->carrier()));
        if ($carrier !== '') {
            self::$providers[$carrier] = $provider;
        }
    }

    public static function get(string $carrier): ?PickupFeedProviderInterface
    {
        return self::$providers[$carrier] ?? null;
    }

    /** @return array<string,PickupFeedProviderInterface> */
    public static function all(): array
    {
        return self::$providers;
    }

    public static function reset(): void
    {
        self::$providers = [];
    }
}
