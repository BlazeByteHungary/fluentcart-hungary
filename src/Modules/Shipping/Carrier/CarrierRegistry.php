<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Carrier;

/**
 * Static carrier registry for cross-cutting surfaces (ADR-001: modules never hard-reference each other;
 * they register their public capabilities here).
 *
 * Each W2 carrier module registers in `registerHooks()`:
 *  - carrier id (same slug as the `fch_shipments.carrier` column),
 *  - admin button/label texts,
 *  - the create-label job hook and the optional tracking-sync job hook,
 *  - the settings scope used for credentials.
 * The admin order screen and pickup surfaces consume this registry instead of naming modules directly.
 */
final class CarrierRegistry
{
    /** @var array<string,array{carrier:string,label:string,button:string,create_hook:string,tracking_hook:?string,settings_scope:string,module_id:string}> */
    private static array $carriers = [];

    public static function register(
        string $carrier,
        string $label,
        string $button,
        string $createHook,
        ?string $trackingHook,
        string $settingsScope,
        string $moduleId
    ): void {
        $carrier = function_exists('sanitize_key') ? sanitize_key($carrier) : (string) preg_replace('/[^a-z0-9_]/', '', strtolower($carrier));
        if ($carrier === '') {
            return;
        }
        self::$carriers[$carrier] = [
            'carrier' => $carrier,
            'label' => $label,
            'button' => $button,
            'create_hook' => $createHook,
            'tracking_hook' => $trackingHook,
            'settings_scope' => $settingsScope,
            'module_id' => $moduleId,
        ];
    }

    /** @return array<string,array{carrier:string,label:string,button:string,create_hook:string,tracking_hook:?string,settings_scope:string,module_id:string}> */
    public static function all(): array
    {
        return self::$carriers;
    }

    /** @return array{carrier:string,label:string,button:string,create_hook:string,tracking_hook:?string,settings_scope:string,module_id:string}|null */
    public static function get(string $carrier): ?array
    {
        return self::$carriers[$carrier] ?? null;
    }

    public static function reset(): void
    {
        self::$carriers = [];
    }
}
