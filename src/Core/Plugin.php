<?php
declare(strict_types=1);

namespace FluentCartHungary\Core;

use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\Database\Migrator;
use FluentCartHungary\Modules\Core\CoreModule;

/**
 * Plugin composition root. All FluentCart symbol use is deferred to boot() (invoked on plugins_loaded, B1).
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private ModuleRegistry $registry;

    public function __construct()
    {
        $this->registry = ModuleRegistry::instance();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    public function boot(): void
    {
        Migrator::maybeUpgrade();

        add_action('admin_init', static function (): void {
            Migrator::maybeUpgrade();
        });

        FluentCartCompat::init();

        $this->registry->register(new CoreModule());

        $builtins = [
            \FluentCartHungary\Modules\Payments\Barion\BarionModule::class,
            \FluentCartHungary\Modules\Billing\Billingo\BillingoModule::class,
            \FluentCartHungary\Modules\Payments\Simplepay\SimplepayModule::class,
            \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzModule::class,
            \FluentCartHungary\Modules\Shipping\Mpl\MplModule::class,
            // W2 carrier breadth (files land in the W2 cycle; class_exists guards registration until then).
            \FluentCartHungary\Modules\Shipping\Gls\GlsModule::class,
            \FluentCartHungary\Modules\Shipping\Dpd\DpdModule::class,
            \FluentCartHungary\Modules\Shipping\Packeta\PacketaModule::class,
            \FluentCartHungary\Modules\Shipping\Foxpost\FoxpostModule::class,
            \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingCoreModule::class,
            \FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule::class,
        ];
        foreach ($builtins as $class) {
            if (class_exists($class)) {
                $this->registry->register(new $class());
            }
        }

        /** @var array<int,ModuleInterface> $extra */
        $extra = apply_filters('fch/register_modules', []);
        foreach ((array) $extra as $module) {
            if ($module instanceof ModuleInterface) {
                $this->registry->register($module);
            }
        }

        $this->registry->boot();
    }
}
