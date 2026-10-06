<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Compat\FluentCartCompat;

/**
 * Admin navigation (goal: native merchant UX). The workspace lives under the FluentCart menu as
 * "Magyar integrációk" (FC sidebar pattern used by Core, Reviews and Pro: `fluent_cart/admin_menu_title`
 * + `$submenu['fluent-cart']`), with a fallback top-level menu when FluentCart is not active so
 * settings/diagnostics stay reachable (safe mode).
 *
 * Only our own WP pages are registered — no FC SPA route/component or DOM patching is used.
 */
final class Menu
{
    public const SLUG = 'fch-hu';
    public const FC_PARENT = 'fluent-cart';
    public const CAP = 'manage_options';

    /** @deprecated legacy slug kept for bookmarked URLs; resolves to the workspace. */
    public const LEGACY_SLUG = 'fluentcart-hungary';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        if (!AdminShell::userCanAccess()) {
            return;
        }

        $title = __('Magyar integrációk', 'fluentcart-hungary');

        if (self::hasFcMenu()) {
            add_submenu_page(
                self::FC_PARENT,
                $title,
                $title,
                self::CAP,
                self::SLUG,
                [Workspace::class, 'render']
            );
            return;
        }

        // Fallback (FluentCart absent): keep the plugin operable in safe mode.
        add_menu_page(
            $title,
            __('Magyar integrációk', 'fluentcart-hungary'),
            self::CAP,
            self::SLUG,
            [Workspace::class, 'render'],
            'dashicons-cart',
            58
        );
        add_submenu_page(
            self::SLUG,
            __('Áttekintés', 'fluentcart-hungary'),
            __('Áttekintés', 'fluentcart-hungary'),
            self::CAP,
            self::SLUG,
            [Workspace::class, 'render']
        );
    }

    /** Assets only on our screens; scoped styles. */
    public function enqueueAssets(string $hook = ''): void
    {
        if (!str_contains($hook, self::SLUG) && !str_contains($hook, self::LEGACY_SLUG)) {
            return;
        }
        wp_enqueue_style('fch-admin', FCH_URL . 'assets/admin.css', [], FCH_VERSION);
        wp_enqueue_script('fch-admin', FCH_URL . 'assets/admin.js', [], FCH_VERSION, true);
        wp_localize_script('fch-admin', 'FchAdmin', [
            'confirmDelete' => __('Biztosan törli a tárolt értéket? Ez nem visszavonható.', 'fluentcart-hungary'),
            'confirmReissue' => __('Új dokumentum kiállítása: a művelet új bizonylatot hoz létre a szolgáltatónál. Folytatja?', 'fluentcart-hungary'),
            'confirmReconcile' => __('A kijelölt műveletekre a választott döntés fut le (naplózva). Folytatja?', 'fluentcart-hungary'),
            'working' => __('Folyamatban…', 'fluentcart-hungary'),
        ]);
    }

    /** Direct workspace URL (order-context links, notices, redirect targets). */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public static function isFcActive(): bool
    {
        return FluentCartCompat::isFcActive();
    }

    /**
     * True when FluentCart is active AND its admin menu is registered (admin_page_hooks is populated by
     * FluentCart's MenuHandler at admin_menu priority 10; ours runs at 20). This is the condition for
     * placing the workspace under the FluentCart menu with add_submenu_page().
     */
    private static function hasFcMenu(): bool
    {
        return self::isFcActive() && isset($GLOBALS['admin_page_hooks'][self::FC_PARENT]);
    }
}
