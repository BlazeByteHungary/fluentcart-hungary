<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;

/**
 * Admin shell: notices, REST mount and the workspace menu (05_ADMIN_AND_CHECKOUT_UX §2).
 * Capability: manage_options only (Core has no granular caps without Pro).
 */
final class AdminShell
{
    /** @deprecated legacy slug; the workspace lives at Menu::SLUG. */
    public const MENU_SLUG = Menu::SLUG;

    public function register(): void
    {
        (new Menu())->register();
        add_action('admin_notices', [$this, 'notices']);
        add_action('rest_api_init', [$this, 'registerRest']);
        add_action('admin_post_fch_run_selftest', [$this, 'handleSelfTest']);
        (new ReconciliationQueue())->register();
        (new OrdersScreen())->register();
        (new OrderWidget())->register();
        if (class_exists(\FluentCartHungary\Modules\Billing\BillingAdminScreen::class)) {
            (new \FluentCartHungary\Modules\Billing\BillingAdminScreen())->register();
        }
        if (class_exists(\FluentCartHungary\Modules\Billing\Billingo\BillingoAdminScreen::class)) {
            (new \FluentCartHungary\Modules\Billing\Billingo\BillingoAdminScreen())->register();
        }
        if (class_exists(\FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzAdminScreen::class)) {
            (new \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzAdminScreen())->register();
        }
    }

    public static function userCanAccess(): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    /** Warnings only on our workspace / FluentCart screens (no global admin noise). */
    public function notices(): void
    {
        if (!self::userCanAccess()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if (!str_contains($id, Menu::SLUG) && !str_contains($id, 'fluent-cart')) {
            return;
        }

        if (!FluentCartCompat::isFcActive()) {
            self::notice(
                'warning',
                __('A FluentCart nem aktív. A FluentCart Hungary biztonságos módban fut: a modulok blokkoltak, csak a beállítások és a diagnosztika érhető el.', 'fluentcart-hungary')
            );
        }

        if (!CredentialStore::isConfigured()) {
            self::notice(
                'warning',
                __('Az FCH_CREDENTIALS_KEY nincs beállítva. A hitelesítő tár fail-closed: a szolgáltatói titkok nem menthetők és nem olvashatók.', 'fluentcart-hungary')
            );
        }

        $registry = ModuleRegistry::instance();
        $degraded = [];
        foreach ($registry->states() as $id => $state) {
            if ($state === ModuleRegistry::STATE_DEGRADED) {
                $degraded[] = $id . ' (' . $registry->message($id) . ')';
            }
        }
        if ($degraded !== []) {
            self::notice(
                'error',
                sprintf(
                    /* translators: %s: comma-separated module list */
                    __('Figyelmet igénylő modulok: %s', 'fluentcart-hungary'),
                    implode(', ', $degraded)
                )
            );
        }
    }

    public function registerRest(): void
    {
        register_rest_route('fch/v1', '/diagnostics', [
            'methods' => 'GET',
            'permission_callback' => static fn() => self::userCanAccess(),
            'callback' => [Diagnostics::class, 'rest'],
        ]);

        register_rest_route('fch/v1', '/selftest', [
            'methods' => 'POST',
            'permission_callback' => static fn() => self::userCanAccess(),
            'callback' => [Diagnostics::class, 'restSelfTest'],
        ]);
    }

    public function handleSelfTest(): void
    {
        if (!self::userCanAccess()) {
            wp_die(esc_html__('Insufficient permissions.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer('fch_run_selftest');
        set_transient('fch_core_selftest', (new CoreConnectionTester())->run(), 300);
        wp_safe_redirect(Menu::url(['tab' => 'overview', 'fch_notice' => 'selftest_run']));
        exit;
    }

    private static function notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s fch-hu-admin-notice"><p><strong>%s</strong> %s</p></div>',
            esc_attr($type),
            esc_html__('FluentCart Hungary:', 'fluentcart-hungary'),
            esc_html($message)
        );
    }
}
