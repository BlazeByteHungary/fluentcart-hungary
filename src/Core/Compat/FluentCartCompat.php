<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Compat;

/**
 * The single place that reads FluentCart symbols (ADR-002/ADR-012). It probes by constant/class/hook
 * lookup only — never patches — and is only ever invoked after `plugins_loaded` (spike B1).
 */
final class FluentCartCompat
{
    private static bool $initialized = false;

    private static ?array $probe = null;

    public static function init(): void
    {
        self::$initialized = true;
        self::$probe = self::runProbe();

        if (self::hasHook('fluent_cart/after_migrate')) {
            add_action('fluent_cart/after_migrate', [self::class, 'reprobe']);
        }
    }

    public static function reprobe(): void
    {
        self::$probe = self::runProbe();
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    public static function isFcActive(): bool
    {
        return defined('FLUENTCART_VERSION') || class_exists('\FluentCart\App\App');
    }

    public static function fcVersion(): ?string
    {
        if (defined('FLUENTCART_VERSION')) {
            return (string) constant('FLUENTCART_VERSION');
        }
        return null;
    }

    public static function isProActive(): bool
    {
        return defined('FLUENTCART_PRO_PLUGIN_VERSION') || class_exists('\FluentCartPro\App\App');
    }

    public static function meetsMinimum(): bool
    {
        $version = self::fcVersion();
        if ($version === null) {
            return false;
        }
        return version_compare($version, FCH_MIN_FC_VERSION, '>=');
    }

    public static function hasHook(string $hook): bool
    {
        return function_exists('has_action') && has_action($hook) !== false;
    }

    public static function hasClass(string $class): bool
    {
        return class_exists($class);
    }

    public static function hasInterface(string $interface): bool
    {
        return interface_exists($interface);
    }

    public static function hasFunction(string $function): bool
    {
        return function_exists($function);
    }

    /** @return array<string,mixed> */
    public static function probe(): array
    {
        if (self::$probe === null) {
            self::$probe = self::runProbe();
        }
        return self::$probe;
    }

    /** @return array<string,mixed> */
    private static function runProbe(): array
    {
        $version = self::fcVersion();
        return [
            'active' => self::isFcActive(),
            'version' => $version,
            'pro' => self::isProActive(),
            'meets_minimum' => $version !== null ? version_compare($version, FCH_MIN_FC_VERSION, '>=') : false,
            'min_required' => FCH_MIN_FC_VERSION,
            'hooks' => [
                'register_payment_methods' => self::hasHook('fluent_cart/register_payment_methods'),
                'after_migrate' => self::hasHook('fluent_cart/after_migrate'),
                'order_paid_done' => self::hasHook('fluent_cart/order_paid_done'),
            ],
            'symbols' => [
                'App' => class_exists('\FluentCart\App\App'),
                'PaymentGatewayInterface' => interface_exists('\FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface'),
            ],
        ];
    }
}
