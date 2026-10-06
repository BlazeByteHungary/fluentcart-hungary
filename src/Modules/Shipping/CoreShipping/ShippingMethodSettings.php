<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\CoreShipping;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CarrierBinding;

/**
 * Per-shipping-method settings for the core shipping mechanics (W1.1 legacy `HS-006/007/008`).
 *
 * FluentCart has NO per-method settings object and no shipping-rate-extension filter in 1.7.0
 * (03_VERIFIED_EXTENSION_POINTS §2; 05_IMPLEMENTATION_WAVES W1). We therefore keep our own keys in the
 * non-secret ModuleSettings option `fch_module_settings`, scope `shipping`:
 *
 *   method_{id}_cod_fee_type         fixed|percent
 *   method_{id}_cod_fee_amount       fixed: FluentCart cents; percent: integer percentage
 *   method_{id}_cod_fee_threshold    FluentCart cents; free above this subtotal
 *   method_{id}_disabled_payments    comma-separated payment routes (e.g. offline_payment,barion)
 *   method_{id}_max_weight_g         integer grams; 0 = no limit
 *   method_{id}_carrier              carrier slug for the method→carrier binding (all services)
 *   method_{id}_service              home|pickup|locker
 *   method_{id}_service_types        abstract point kinds (point|locker)
 *
 * (The pickup-point module reads `method_{id}_pickup_carrier` / `method_{id}_pickup_types` from the same
 * scope; the writer keeps those legacy keys in sync so older readers keep working.)
 *
 * Admin: no plugin-friendly render seam exists on SettingsPage (Core/Settings/SettingsPage.php), so we register
 * our own `admin_post_fch_shipping_settings` handler plus a render callable, exposed as our own submenu page.
 */
final class ShippingMethodSettings
{
    public const SCOPE = 'shipping';
    public const PAGE_SLUG = 'fch-shipping';
    public const NONCE_ACTION = 'fch_shipping_settings';

    private ModuleSettings $settings;

    public function __construct(?ModuleSettings $settings = null)
    {
        $this->settings = $settings ?? new ModuleSettings();
    }

    // ---- per-method readers ------------------------------------------------------------------------

    public function codFeeType(int $methodId): string
    {
        $value = strtolower(trim((string) $this->get($methodId, 'cod_fee_type', '')));
        return in_array($value, [CodFeeCalculator::TYPE_FIXED, CodFeeCalculator::TYPE_PERCENT], true) ? $value : '';
    }

    public function codFeeAmount(int $methodId): int
    {
        return max(0, (int) $this->get($methodId, 'cod_fee_amount', 0));
    }

    public function codFeeThreshold(int $methodId): int
    {
        return max(0, (int) $this->get($methodId, 'cod_fee_threshold', 0));
    }

    /** @return array{type:string,amount:int,threshold:int} */
    public function codFeeConfig(int $methodId): array
    {
        return [
            'type' => $this->codFeeType($methodId),
            'amount' => $this->codFeeAmount($methodId),
            'threshold' => $this->codFeeThreshold($methodId),
        ];
    }

    /** @return list<string> */
    public function disabledPayments(int $methodId): array
    {
        $raw = $this->get($methodId, 'disabled_payments', '');
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,]+/', (string) $raw) ?: [];
        }
        return array_values(array_filter(array_map(static fn($value): string => self::sanitizeKey((string) $value), $parts)));
    }

    public function maxWeightG(int $methodId): int
    {
        return max(0, (int) $this->get($methodId, 'max_weight_g', 0));
    }

    /** Generic carrier binding for this method (empty = unbound). */
    public function carrier(int $methodId): string
    {
        return self::sanitizeKey((string) $this->get($methodId, 'carrier', ''));
    }

    /** Service binding: home|pickup|locker (empty = unbound). */
    public function service(int $methodId): string
    {
        $service = strtolower(trim((string) $this->get($methodId, 'service', '')));
        return in_array($service, CarrierBinding::SERVICES, true) ? $service : '';
    }

    /** @return list<string> abstract point kinds (point|locker); empty = any */
    public function serviceKinds(int $methodId): array
    {
        return CarrierBinding::parseKinds((string) $this->get($methodId, 'service_types', ''));
    }

    /** @return array{carrier:string,service:string,kinds:list<string>,legacy:bool} */
    public function deliveryBinding(int $methodId): array
    {
        return CarrierBinding::binding($methodId);
    }

    public function pickupCarrier(int $methodId): string
    {
        return strtolower(trim((string) $this->get($methodId, 'pickup_carrier', '')));
    }

    /** Configured home-delivery method for the pickup fallback button (0 = navigate to the method list). */
    public function pickupFallbackMethod(int $methodId): int
    {
        return max(0, (int) $this->get($methodId, 'pickup_fallback_method', 0));
    }

    /** @return list<string> */
    public function pickupTypes(int $methodId): array
    {
        $raw = (string) $this->get($methodId, 'pickup_types', '');
        return array_values(array_filter(array_map(
            static fn($type): string => strtoupper(trim((string) $type)),
            preg_split('/[\s,]+/', $raw) ?: []
        ), static fn(string $type): bool => in_array($type, ['PM', 'CS', 'PP'], true)));
    }

    // ---- writer ------------------------------------------------------------------------------------

    /**
     * Persist a subset of the per-method settings. Unknown keys are ignored.
     *
     * @param array<string,mixed> $values
     */
    public function saveForMethod(int $methodId, array $values): void
    {
        if ($methodId <= 0) {
            return;
        }

        if (array_key_exists('cod_fee_type', $values)) {
            $type = strtolower(trim((string) $values['cod_fee_type']));
            $this->set($methodId, 'cod_fee_type', in_array($type, [CodFeeCalculator::TYPE_FIXED, CodFeeCalculator::TYPE_PERCENT], true) ? $type : '');
        }
        if (array_key_exists('cod_fee_amount', $values)) {
            $this->set($methodId, 'cod_fee_amount', max(0, (int) $values['cod_fee_amount']));
        }
        if (array_key_exists('cod_fee_threshold', $values)) {
            $this->set($methodId, 'cod_fee_threshold', max(0, (int) $values['cod_fee_threshold']));
        }
        if (array_key_exists('disabled_payments', $values)) {
            $raw = is_array($values['disabled_payments'])
                ? $values['disabled_payments']
                : (preg_split('/[\s,]+/', (string) $values['disabled_payments']) ?: []);
            $routes = array_values(array_filter(array_map(static fn($value): string => self::sanitizeKey((string) $value), $raw)));
            $this->set($methodId, 'disabled_payments', implode(',', $routes));
        }
        if (array_key_exists('max_weight_g', $values)) {
            $this->set($methodId, 'max_weight_g', max(0, (int) $values['max_weight_g']));
        }
        if (array_key_exists('carrier', $values) || array_key_exists('service', $values) || array_key_exists('service_types', $values)) {
            $carrier = array_key_exists('carrier', $values) ? self::sanitizeKey((string) $values['carrier']) : $this->carrier($methodId);
            $service = array_key_exists('service', $values) ? strtolower(trim((string) $values['service'])) : $this->service($methodId);
            if (!in_array($service, CarrierBinding::SERVICES, true)) {
                $service = '';
            }
            if ($carrier === '' || $service === '') {
                // An unbound/partial binding is stored as "no binding" — never a half-configured route.
                $carrier = '';
                $service = '';
            }
            $this->set($methodId, 'carrier', $carrier);
            $this->set($methodId, 'service', $service);

            $rawKinds = array_key_exists('service_types', $values) ? $values['service_types'] : $this->serviceKinds($methodId);
            $kinds = is_array($rawKinds) ? CarrierBinding::parseKinds(implode(',', array_map('strval', $rawKinds))) : CarrierBinding::parseKinds((string) $rawKinds);
            $this->set($methodId, 'service_types', implode(',', $kinds));

            // Write-through to the legacy pickup keys so every older reader stays consistent.
            if (CarrierBinding::isPickupService($service)) {
                $this->set($methodId, 'pickup_carrier', $carrier);
                $this->set($methodId, 'pickup_types', implode(',', $kinds));
            } else {
                $this->set($methodId, 'pickup_carrier', '');
            }
        }
        if (array_key_exists('pickup_carrier', $values)) {
            $this->set($methodId, 'pickup_carrier', self::sanitizeKey((string) $values['pickup_carrier']));
        }
        if (array_key_exists('pickup_types', $values)) {
            $raw = is_array($values['pickup_types'])
                ? $values['pickup_types']
                : (preg_split('/[\s,]+/', (string) $values['pickup_types']) ?: []);
            $types = array_values(array_filter(array_map(
                static fn($type): string => strtoupper(trim((string) $type)),
                $raw
            ), static fn(string $type): bool => in_array($type, ['PM', 'CS', 'PP'], true)));
            $this->set($methodId, 'pickup_types', implode(',', $types));
        }
        if (array_key_exists('pickup_fallback_method', $values)) {
            $this->set($methodId, 'pickup_fallback_method', max(0, (int) $values['pickup_fallback_method']));
        }
    }

    // ---- admin -------------------------------------------------------------------------------------

    public function registerAdmin(): void
    {
        add_action('admin_post_' . self::NONCE_ACTION, [$this, 'handleSave']);
    }

    /** Legacy wrapper. */
    public function renderPage(): void
    {
        echo '<div class="wrap fch-admin">';
        $this->renderContent();
        echo '</div>';
    }

    /** Render callable for the workspace "shipping" tab: a minimal per-method editor. */
    public function renderContent(): void
    {
        if (class_exists(AdminShell::class) && !AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $methodId = isset($_GET['method_id']) ? absint($_GET['method_id']) : 0;
        $config = $methodId > 0 ? $this->codFeeConfig($methodId) : ['type' => '', 'amount' => 0, 'threshold' => 0];

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Szállítási mód beállításai', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('Utánvét díj, fizetési tiltás, súlykorlát és csomagpont-konfiguráció szállítási módonként. A mód kiválasztása a szállítási módszer azonosítójával történik.', 'fluentcart-hungary') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::NONCE_ACTION) . '" />';
        echo '<table class="form-table"><tbody>';
        $this->field('method_id', __('Shipping method id', 'fluentcart-hungary'), 'number', (string) $methodId);
        $this->field('cod_fee_type', __('COD fee type (fixed|percent)', 'fluentcart-hungary'), 'text', (string) $config['type']);
        $this->field('cod_fee_amount', __('COD fee amount (cents; percent = integer %)', 'fluentcart-hungary'), 'number', (string) $config['amount']);
        $this->field('cod_fee_threshold', __('COD fee free threshold (cents)', 'fluentcart-hungary'), 'number', (string) $config['threshold']);
        $this->field('disabled_payments', __('Disabled payment routes (comma separated)', 'fluentcart-hungary'), 'text', $methodId > 0 ? implode(',', $this->disabledPayments($methodId)) : '');
        $this->field('max_weight_g', __('Max cart weight (grams, 0 = none)', 'fluentcart-hungary'), 'number', $methodId > 0 ? (string) $this->maxWeightG($methodId) : '0');

        $binding = $methodId > 0 ? $this->deliveryBinding($methodId) : ['carrier' => '', 'service' => '', 'kinds' => [], 'legacy' => false];
        $this->field('carrier', __('Carrier (mpl|gls|dpd|packeta|foxpost; empty = unbound)', 'fluentcart-hungary'), 'text', (string) $binding['carrier']);
        echo '<tr><th><label for="fch_shipping_service">' . esc_html__('Service (home|pickup|locker)', 'fluentcart-hungary') . '</label></th><td><select id="fch_shipping_service" name="service">';
        foreach (['' => __('Nincs hozzárendelve', 'fluentcart-hungary'), CarrierBinding::SERVICE_HOME => __('Házhozszállítás', 'fluentcart-hungary'), CarrierBinding::SERVICE_PICKUP => __('Csomagpont', 'fluentcart-hungary'), CarrierBinding::SERVICE_LOCKER => __('Automata', 'fluentcart-hungary')] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr((string) $value), selected((string) $binding['service'], (string) $value, false), esc_html((string) $label));
        }
        echo '</select></td></tr>';
        $this->field('service_types', __('Pickup point kinds (point,locker; empty = any)', 'fluentcart-hungary'), 'text', implode(',', (array) $binding['kinds']));
        $this->field('pickup_fallback_method', __('Fallback home-delivery method id (0 = show the method list)', 'fluentcart-hungary'), 'number', $methodId > 0 ? (string) $this->pickupFallbackMethod($methodId) : '0');
        echo '</tbody></table>';
        submit_button(__('Szállítási beállítások mentése', 'fluentcart-hungary'));
        echo '</form>';
        if (function_exists('do_action')) {
            do_action('fch/shipping/settings/after', $methodId);
        }
        echo '</section>';
    }

    public function handleSave(): void
    {
        if (class_exists(AdminShell::class) && !AdminShell::userCanAccess()) {
            wp_die(esc_html__('Insufficient permissions.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        $methodId = isset($_POST['method_id']) ? absint(wp_unslash($_POST['method_id'])) : 0;
        $this->saveForMethod($methodId, [
            'cod_fee_type' => wp_unslash((string) ($_POST['cod_fee_type'] ?? '')),
            'cod_fee_amount' => wp_unslash((string) ($_POST['cod_fee_amount'] ?? '0')),
            'cod_fee_threshold' => wp_unslash((string) ($_POST['cod_fee_threshold'] ?? '0')),
            'disabled_payments' => wp_unslash((string) ($_POST['disabled_payments'] ?? '')),
            'max_weight_g' => wp_unslash((string) ($_POST['max_weight_g'] ?? '0')),
            'carrier' => wp_unslash((string) ($_POST['carrier'] ?? '')),
            'service' => wp_unslash((string) ($_POST['service'] ?? '')),
            'service_types' => wp_unslash((string) ($_POST['service_types'] ?? '')),
            'pickup_fallback_method' => wp_unslash((string) ($_POST['pickup_fallback_method'] ?? '0')),
        ]);

        $args = ['tab' => 'shipping', 'fch_notice' => 'saved'];
        if ($methodId > 0) {
            $args['method_id'] = $methodId;
        }
        wp_safe_redirect(\FluentCartHungary\Core\Admin\Menu::url($args));
        exit;
    }

    private function field(string $name, string $label, string $type, string $value): void
    {
        printf(
            '<tr><th><label for="fch_shipping_%1$s">%2$s</label></th><td><input type="%3$s" id="fch_shipping_%1$s" name="%1$s" value="%4$s" class="regular-text" autocomplete="off" /></td></tr>',
            esc_attr($name),
            esc_html($label),
            esc_attr($type),
            esc_attr($value)
        );
    }

    // ---- settings access ---------------------------------------------------------------------------

    private function get(int $methodId, string $key, mixed $default = null): mixed
    {
        if ($methodId <= 0) {
            return $default;
        }
        return $this->settings->get(self::SCOPE, 'method_' . $methodId . '_' . $key, $default);
    }

    private function set(int $methodId, string $key, mixed $value): void
    {
        $this->settings->set(self::SCOPE, 'method_' . $methodId . '_' . $key, $value);
    }

    /** WordPress-free sanitize_key equivalent for readers used outside WP (unit tests). */
    private static function sanitizeKey(string $value): string
    {
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key($value);
        }
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($value))) ?? '';
    }
}
