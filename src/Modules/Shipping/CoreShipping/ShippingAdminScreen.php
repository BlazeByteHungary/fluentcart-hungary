<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\CoreShipping;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Admin\Menu;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;

/**
 * "Szállítás" workspace tab. FluentCart Core ships its shipping-method configuration in the SPA and exposes
 * no stable third-party form-field seam (see ADMIN_INTEGRATION_REPORT.md), so the per-method Hungarian
 * settings (COD fee, payment restrictions, weight limit, pickup config) live here, selectable by the FC
 * shipping method id. The native FluentCart shipping method list remains the authoritative list.
 */
final class ShippingAdminScreen
{
    private const MODULES = ['shipping', 'shipping/mpl', 'shipping/gls', 'shipping/dpd', 'shipping/packeta', 'shipping/foxpost', 'shipping/pickuppoints'];

    public const CARRIER_ACTION = 'fch_save_carrier_settings';

    public static function register(): void
    {
        add_action('admin_post_' . self::CARRIER_ACTION, [self::class, 'handleCarrierSave']);
    }

    public static function renderContent(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $registry = ModuleRegistry::instance();

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Szállítási modulok', 'fluentcart-hungary') . '</h2>';
        echo '<table class="widefat striped fch-hu-table"><thead><tr><th>' . esc_html__('Modul', 'fluentcart-hungary') . '</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Részletek', 'fluentcart-hungary') . '</th></tr></thead><tbody>';
        foreach (self::MODULES as $id) {
            $module = $registry->get($id);
            if ($module === null) {
                continue;
            }
            $enabled = $registry->isEnabled($id);
            $state = $registry->state($id);
            printf(
                '<tr><td><strong>%s</strong><br /><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                esc_html($module->label()),
                esc_html($id),
                '<span class="fch-hu-badge fch-hu-badge-' . esc_attr(!$enabled ? 'muted' : self::stateKind($state)) . '">' . esc_html($enabled ? $state : 'modul letiltva') . '</span>',
                esc_html($registry->message($id))
            );
        }
        echo '</tbody></table>';
        echo '</section>';

        $methods = self::shippingMethods();
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('FluentCart szállítási módok', 'fluentcart-hungary') . '</h2>';
        if ($methods === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nem érhető el FluentCart szállítási mód (vagy a FluentCart nem aktív). A módokat előbb a FluentCart adminban kell létrehozni.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>ID</th><th>' . esc_html__('Név', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
            foreach ($methods as $method) {
                printf(
                    '<tr><td>%d</td><td>%s</td><td><a class="button button-small" href="%s">%s</a></td></tr>',
                    (int) $method->id,
                    esc_html((string) ($method->title ?? '')),
                    esc_url(Menu::url(['tab' => 'shipping', 'method_id' => (int) $method->id])),
                    esc_html__('Magyar beállítások', 'fluentcart-hungary')
                );
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('A lista a FluentCart natív szállítási módjait tükrözi; a magyar kiegészítések (utánvét díj, tiltások, súlykorlát, csomagpont) módonként itt szerkeszthetők.', 'fluentcart-hungary') . '</p>';
        }
        echo '</section>';

        $methodId = isset($_GET['method_id']) ? absint($_GET['method_id']) : 0;
        if ($methodId > 0 && class_exists(ShippingMethodSettings::class)) {
            (new ShippingMethodSettings())->renderContent();
        }

        self::renderCarrierSettings();
    }

    /** Per-carrier operations settings for every registered W2 carrier (create_on + COD limits). */
    private static function renderCarrierSettings(): void
    {
        $carriers = CarrierRegistry::all();
        if ($carriers === []) {
            return;
        }
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Fuvarozói műveletek', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('Címkekészítés triggerelése és az utánvét összeghatárai fuvarozónként. Az utánvét értéket domain-egységben (HUF: egész forint) kell megadni; üres mező = a szerződés szerinti alapérték.', 'fluentcart-hungary') . '</p>';
        $settings = new ModuleSettings();
        foreach ($carriers as $carrier => $meta) {
            $scope = (string) $meta['settings_scope'];
            $createOn = (string) $settings->get($scope, 'create_on', 'paid_or_cod');
            $min = (string) $settings->get($scope, 'cod_min_minor', '');
            $max = (string) $settings->get($scope, 'cod_max_minor', '');
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-carrier-settings">';
            wp_nonce_field(self::CARRIER_ACTION, '_wpnonce');
            echo '<input type="hidden" name="action" value="' . esc_attr(self::CARRIER_ACTION) . '" />';
            echo '<input type="hidden" name="fch_carrier" value="' . esc_attr($carrier) . '" />';
            echo '<h3>' . esc_html((string) $meta['label']) . '</h3>';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label>' . esc_html__('Címke készítése', 'fluentcart-hungary') . '</label></th><td><select name="fch_create_on">';
            foreach (['paid_or_cod' => __('Fizetve vagy utánvét', 'fluentcart-hungary'), 'paid' => __('Csak fizetve', 'fluentcart-hungary'), 'cod' => __('Csak utánvét', 'fluentcart-hungary')] as $value => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($createOn, $value, false), esc_html($label));
            }
            echo '</select></td></tr>';
            printf('<tr><th><label>%s</label></th><td><input type="number" min="0" step="1" name="fch_cod_min" value="%s" class="small-text" /> <span class="description">%s</span></td></tr>', esc_html__('Utánvét minimum', 'fluentcart-hungary'), esc_attr($min), esc_html__('domain-egység (HUF: forint)', 'fluentcart-hungary'));
            printf('<tr><th><label>%s</label></th><td><input type="number" min="0" step="1" name="fch_cod_max" value="%s" class="small-text" /> <span class="description">%s</span></td></tr>', esc_html__('Utánvét maximum', 'fluentcart-hungary'), esc_attr($max), esc_html__('0 vagy üres = szerződés szerinti alapérték', 'fluentcart-hungary'));
            echo '</tbody></table>';
            submit_button(__('Fuvarozó beállításainak mentése', 'fluentcart-hungary'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
    }

    public static function handleCarrierSave(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::CARRIER_ACTION);

        $carrier = isset($_POST['fch_carrier']) ? sanitize_key(wp_unslash((string) $_POST['fch_carrier'])) : '';
        $meta = CarrierRegistry::get($carrier);
        if ($meta === null) {
            wp_safe_redirect(Menu::url(['tab' => 'shipping', 'fch_notice' => 'invalid']));
            exit;
        }
        $settings = new ModuleSettings();
        $createOn = isset($_POST['fch_create_on']) ? sanitize_key(wp_unslash((string) $_POST['fch_create_on'])) : 'paid_or_cod';
        $settings->set((string) $meta['settings_scope'], 'create_on', in_array($createOn, ['paid_or_cod', 'paid', 'cod'], true) ? $createOn : 'paid_or_cod');
        $settings->set((string) $meta['settings_scope'], 'cod_min_minor', (string) max(0, (int) ($_POST['fch_cod_min'] ?? 0)));
        $settings->set((string) $meta['settings_scope'], 'cod_max_minor', (string) max(0, (int) ($_POST['fch_cod_max'] ?? 0)));

        wp_safe_redirect(Menu::url(['tab' => 'shipping', 'fch_notice' => 'saved']));
        exit;
    }

    /** @return list<object> */
    private static function shippingMethods(): array
    {
        $class = 'FluentCart\\App\\Models\\ShippingMethod';
        if (!class_exists($class)) {
            return [];
        }
        try {
            $rows = $class::query()->orderBy('id', 'asc')->get();
            return is_iterable($rows) ? array_values(iterator_to_array($rows)) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function stateKind(string $state): string
    {
        return match ($state) {
            ModuleRegistry::STATE_ACTIVE => 'ok',
            ModuleRegistry::STATE_DEGRADED => 'warn',
            ModuleRegistry::STATE_BLOCKED => 'error',
            default => 'muted',
        };
    }
}
