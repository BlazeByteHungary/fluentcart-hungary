<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Admin\Menu;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;
use FluentCartHungary\Modules\Shipping\Mpl\MplClient;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\FoxpostFeedClient;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\MplPoints;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

/**
 * shipping/pickuppoints module (W1.4; checkout goal 2/3: home carrier selection, pickup filters, full
 * directory). The canonical selection snapshot still lives in `fct_carts.checkout_data._fch_pickup_point`
 * and order meta `_fch_pickup_point` (+ `_fch_carrier`, `_fch_delivery_mode`, `_fch_service`) — never a
 * Core table column.
 *
 * Per-method binding lives in ModuleSettings scope `shipping` and is resolved through
 * {@see CarrierBinding}: `method_{id}_carrier` + `method_{id}_service` (+ `method_{id}_service_types`);
 * the legacy W1/W2 keys `method_{id}_pickup_carrier` / `method_{id}_pickup_types` remain readable.
 * Integrators can override the resolved context with the `fch/pickup/method_context` filter.
 *
 * Directory freshness is maintained out of band: an hourly WP-Cron tick enqueues one deduplicated refresh
 * job per carrier (last-good is never overwritten on failure/empty feeds), plus WP-CLI and an admin
 * diagnostics panel. The checkout path itself never touches the network.
 */
final class PickupPointsModule implements ModuleInterface
{
    public const ID = 'shipping/pickuppoints';
    public const SCOPE = 'shipping';
    public const CRON_HOOK = 'fch_pickup_refresh_tick';
    public const REFRESH_ACTION = 'fch_pickup_refresh';
    public const ASSIGN_ACTION = 'fch_pickup_assign_carrier';
    public const ROUTING_ACTION = 'fch_pickup_routing_save';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Pickup points (átvételi pontok)', 'fluentcart-hungary');
    }

    public function requires(): array
    {
        return ['core'];
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    public function registerHooks(): void
    {
        // Feed providers are registered before the checkout handler so every carrier directory is
        // refreshable through the same path (the handler itself stays carrier-agnostic).
        PickupFeedRegistry::register(new MplPoints());
        JobRunner::instance()->registerHandler(new PickupRefreshJob());

        $handler = new PickupCheckoutHandler(new PickupDirectory(), new PickupPointValidator());

        add_action('fluent_cart/checkout_form_opening', [$handler, 'renderField'], 10, 1);
        add_filter('fluent_cart/checkout/before_patch_checkout_data', [$handler, 'beforePatchCheckoutData'], 20, 2);
        add_filter('fluent_cart/checkout/validate_data', [$handler, 'validateData'], 10, 2);
        add_filter('fluent_cart/checkout/validate_before_process', [$handler, 'validateBeforeProcess'], 10, 2);
        add_action('fluent_cart/checkout/prepare_other_data', [$handler, 'prepareOtherData'], 10, 1);
        add_filter('fluent_cart/checkout/after_patch_checkout_data_fragments', [$handler, 'afterPatchFragments'], 10, 2);
        add_filter('fluent_cart/checkout/localize_data', [$handler, 'localizeData'], 10, 2);

        add_action('wp_ajax_' . PickupCheckoutHandler::SEARCH_ACTION, [$handler, 'ajaxSearch']);
        add_action('wp_ajax_nopriv_' . PickupCheckoutHandler::SEARCH_ACTION, [$handler, 'ajaxSearch']);
        add_action('wp_ajax_' . PickupCheckoutHandler::SAVE_ACTION, [$handler, 'handleSaveAjax']);
        add_action('wp_ajax_nopriv_' . PickupCheckoutHandler::SAVE_ACTION, [$handler, 'handleSaveAjax']);
        add_action('wp_ajax_' . PickupCheckoutHandler::RESOLVE_ACTION, [$handler, 'ajaxResolve']);
        add_action('wp_ajax_nopriv_' . PickupCheckoutHandler::RESOLVE_ACTION, [$handler, 'ajaxResolve']);
        add_action('wp_enqueue_scripts', [$handler, 'enqueueAssets']);

        add_action('init', [self::class, 'ensureRefreshSchedule']);
        add_action(self::CRON_HOOK, [self::class, 'cronTick']);
        add_action('admin_post_' . self::REFRESH_ACTION, [self::class, 'handleRefreshAdmin']);
        add_action('admin_post_' . self::ASSIGN_ACTION, [self::class, 'handleAssignCarrier']);
        add_action('admin_post_' . self::ROUTING_ACTION, [self::class, 'handleRoutingSave']);
        add_action('fch/shipping/settings/after', [self::class, 'renderDirectoryDiagnostics']);
        // 3/3 routing gate: every server-side order creation path locks the carrier decision into the
        // order meta (checkout already snapshots via prepareOtherData; this covers admin-created and
        // any other programmatic orders, and marks an actionable unresolved state instead of guessing).
        add_action('fluent_cart/order_created', [self::class, 'onOrderCreated'], 20, 1);

        if (defined('WP_CLI') && WP_CLI) {
            add_action('cli_init', [self::class, 'registerCli']);
        }
    }

    public function probe(): array
    {
        $directory = new PickupDirectory();
        $carriers = self::carriers();
        $parts = [];
        $anyPoints = false;

        foreach ($carriers as $carrier) {
            $entry = $directory->load($carrier);
            $count = count($entry['points']);
            if ($count > 0) {
                $anyPoints = true;
            }
            $age = PickupDirectory::ageAt($entry, time());
            $parts[] = sprintf(
                '%s: %d points, %s',
                $carrier,
                $count,
                $age === null ? 'never fetched' : ($age . 's old' . (PickupDirectory::isFreshAt($entry, time()) ? '' : ' (stale)'))
            );
        }

        return [
            'ok' => $anyPoints,
            'message' => $parts === [] ? 'no pickup carriers configured' : implode('; ', $parts),
        ];
    }

    /**
     * Resolve the pickup context for a shipping method through the carrier binding.
     *
     * @return array{method_id:int,required:bool,carrier:string,service:string,allowed_types:list<string>}
     */
    public static function methodContext(int $methodId): array
    {
        $context = ['method_id' => $methodId, 'required' => false, 'carrier' => '', 'service' => '', 'allowed_types' => []];
        if ($methodId <= 0) {
            return $context;
        }

        $binding = CarrierBinding::binding($methodId);
        if ($binding['carrier'] === '' || !CarrierBinding::isPickupService($binding['service'])) {
            return $context;
        }

        $context = [
            'method_id' => $methodId,
            'required' => true,
            'carrier' => (string) $binding['carrier'],
            'service' => (string) $binding['service'],
            'allowed_types' => (array) $binding['kinds'],
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fch/pickup/method_context', $context, $methodId);
            if (is_array($filtered)) {
                $context = array_merge($context, $filtered);
            }
        }
        return $context;
    }

    /**
     * Map every bound shipping method to its context for the checkout script. Home bindings are included
     * too (service=home) so the client can label/group the native FluentCart methods.
     *
     * @return array<string,array{carrier:string,service:string,label:string,required:bool,types:list<string>,fallback_method:int}>
     */
    public static function methodsMap(): array
    {
        $out = [];
        try {
            $shippingSettings = (new ModuleSettings())->allFor(self::SCOPE);
        } catch (\Throwable $e) {
            return $out;
        }
        foreach (array_keys($shippingSettings) as $key) {
            if (preg_match('/^method_(\d+)_(carrier|pickup_carrier)$/', (string) $key, $m) !== 1) {
                continue;
            }
            $methodId = (int) $m[1];
            if (isset($out[(string) $methodId])) {
                continue;
            }
            $binding = CarrierBinding::binding($methodId);
            if ($binding['carrier'] === '' || $binding['service'] === '') {
                continue;
            }
            $out[(string) $methodId] = [
                'carrier' => (string) $binding['carrier'],
                'service' => (string) $binding['service'],
                'label' => self::carrierLabel((string) $binding['carrier']),
                'required' => CarrierBinding::isPickupService((string) $binding['service']),
                'types' => (array) $binding['kinds'],
                // 0 = no explicit home-delivery mapping: the fallback button navigates to the
                // shipping-method list instead of guessing a method (PA-DEF-05).
                'fallback_method' => max(0, (int) ($shippingSettings['method_' . $methodId . '_pickup_fallback_method'] ?? 0)),
            ];
        }
        return $out;
    }

    /** All pickup-capable bound carriers plus every registered feed provider (MPL always). */
    public static function carriers(): array
    {
        $carriers = array_values(array_unique(array_merge(
            [PickupDirectory::MPL_CARRIER],
            array_keys(PickupFeedRegistry::all())
        )));
        foreach (self::methodsMap() as $context) {
            if (!CarrierBinding::isPickupService((string) ($context['service'] ?? ''))) {
                continue;
            }
            $carrier = (string) ($context['carrier'] ?? '');
            if ($carrier !== '' && !in_array($carrier, $carriers, true)) {
                $carriers[] = $carrier;
            }
        }
        return $carriers;
    }

    public const DRIVER_LOCAL = 'local';
    public const DRIVER_FOXPOST_WIDGET = 'foxpost_widget';
    public const DRIVER_PACKETA_WIDGET = 'packeta_widget';
    public const DRIVER_GLS_WIDGET = 'gls_widget';

    /**
     * Selector-driver registry (checkout 3/3 gate #4). Every driver feeds the SAME server-side
     * resolution/offers/save path; a driver can never write a selection by itself. The official widget
     * URL/origin values are the documented ones (Foxpost apt-finder; Packeta Widget v6); Packeta needs a
     * merchant widget key (public key only — the secret API password never reaches the frontend), GLS
     * stays gated until a documented asset configuration exists (no global <base href> on the storefront).
     *
     * @return array{carrier:string,driver:string,enabled:bool,reason:string,iframe?:string,origin?:string,widget_url?:string,validate_url?:string,api_key?:string,asset?:string}
     */
    public static function driverFor(string $carrier): array
    {
        $carrier = strtolower(trim($carrier));
        $config = ['carrier' => $carrier, 'driver' => self::DRIVER_LOCAL, 'enabled' => true, 'reason' => 'list'];

        $allowed = [
            'foxpost' => [self::DRIVER_LOCAL, self::DRIVER_FOXPOST_WIDGET],
            'packeta' => [self::DRIVER_LOCAL, self::DRIVER_PACKETA_WIDGET],
            'gls' => [self::DRIVER_LOCAL, self::DRIVER_GLS_WIDGET],
        ];
        try {
            $settings = new ModuleSettings();
            $driver = strtolower(trim((string) $settings->get(self::SCOPE, 'pickup_driver_' . $carrier, self::DRIVER_LOCAL)));
            $driver = in_array($driver, $allowed[$carrier] ?? [self::DRIVER_LOCAL], true) ? $driver : self::DRIVER_LOCAL;

            if ($driver === self::DRIVER_FOXPOST_WIDGET) {
                $config = [
                    'carrier' => $carrier,
                    'driver' => $driver,
                    'enabled' => true,
                    'reason' => 'documented_widget',
                    'iframe' => 'https://cdn.foxpost.hu/apt-finder/v1/app/',
                    'origin' => 'https://cdn.foxpost.hu',
                ];
            } elseif ($driver === self::DRIVER_PACKETA_WIDGET) {
                $key = trim((string) $settings->get(self::SCOPE, 'pickup_widget_key_packeta', ''));
                $config = [
                    'carrier' => $carrier,
                    'driver' => $driver,
                    // No key = implementable adapter, merchant-scoped live selection BLOCKED (G1).
                    'enabled' => $key !== '',
                    'reason' => $key !== '' ? 'documented_widget' : 'no_widget_key',
                    'widget_url' => 'https://widget.packeta.com/v6/www/js/library.js',
                    'validate_url' => 'https://widget.packeta.com/v6/pps/api/widget/v1/validate',
                    'origin' => 'https://widget.packeta.com',
                    'api_key' => $key,
                ];
            } elseif ($driver === self::DRIVER_GLS_WIDGET) {
                $asset = trim((string) $settings->get(self::SCOPE, 'pickup_gls_widget_asset', ''));
                $enabled = str_starts_with($asset, 'https://');
                $origin = $enabled ? (string) preg_replace('#^(https://[^/]+).*$#', '$1/', $asset) : '';
                $config = [
                    'carrier' => $carrier,
                    'driver' => $driver,
                    // Documented widget (GLS ParcelShop finder with map, 2026-09-16): custom element
                    // `<gls-dpm country="hu">` + module script + `change` event with detail.id. Loaded
                    // inside our own isolated same-origin iframe whose document carries the <base href>,
                    // so the storefront document itself never needs a global <base>.
                    'enabled' => $enabled,
                    'reason' => $enabled ? 'documented_widget_isolated' : 'gated_no_documented_asset',
                    'asset' => $asset,
                    'base' => $origin,
                    'country' => 'hu',
                    'language' => 'hu',
                ];
            }
        } catch (\Throwable $e) {
            // Local list stays available; the widget never becomes silently active.
        }
        return $config;
    }

    /** @return array<string,array<string,mixed>> carrier => driver config */
    public static function drivers(): array
    {
        $out = [];
        foreach (self::carriers() as $carrier) {
            $out[$carrier] = self::driverFor($carrier);
        }
        return $out;
    }

    public static function carrierLabel(string $carrier): string
    {
        $meta = CarrierRegistry::get($carrier);
        if (is_array($meta) && trim((string) ($meta['label'] ?? '')) !== '') {
            return (string) $meta['label'];
        }
        return strtoupper($carrier);
    }

    // ---- refresh scheduling / execution ------------------------------------------------------------

    public static function refreshEnabled(): bool
    {
        try {
            $value = (new ModuleSettings())->get(self::SCOPE, 'pickup_refresh_enabled', '1');
        } catch (\Throwable $e) {
            return true;
        }
        return (string) $value !== '0';
    }

    /** Ensure exactly one hourly refresh tick exists when enabled (and none when disabled). */
    public static function ensureRefreshSchedule(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        $scheduled = wp_next_scheduled(self::CRON_HOOK);
        if (self::refreshEnabled()) {
            if ($scheduled === false) {
                wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
            }
            return;
        }
        if ($scheduled !== false && function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function cronTick(): void
    {
        foreach (self::carriers() as $carrier) {
            self::maybeEnqueueRefresh($carrier);
        }
    }

    /**
     * Enqueue a deduplicated refresh job for one carrier unless the directory is still fresh or the
     * carrier is inside its failure backoff (attempt 1: 30m, 3+: 6h). Never called from checkout.
     */
    public static function maybeEnqueueRefresh(string $carrier, bool $force = false): bool
    {
        $carrier = strtolower(trim($carrier));
        if ($carrier === '') {
            return false;
        }
        if (!$force) {
            $entry = (new PickupDirectory())->load($carrier);
            $now = time();
            $attempts = (int) ($entry['attempts'] ?? 0);
            if (PickupDirectory::isFreshAt($entry, $now) && $attempts === 0) {
                return false;
            }
            $lastAttempt = (int) ($entry['last_attempt_at'] ?? 0);
            $backoff = $attempts >= 3 ? 21600 : ($attempts >= 1 ? 1800 : 0);
            if ($lastAttempt > 0 && ($now - $lastAttempt) < $backoff) {
                return false;
            }
        }
        return JobRunner::instance()->enqueue(new JobSpec(
            'fch:pickup_refresh:' . $carrier,
            PickupRefreshJob::HOOK,
            ['carrier' => $carrier],
            20,
            3,
            self::ID
        ));
    }

    /**
     * Fetch + cache one carrier directory through its provider. The only place a pickup provider client is
     * constructed; checkout/search code never reaches this path.
     *
     * @return array{ok:bool,count:int,error:?string,kept:int,carrier:string}
     */
    public static function refreshCarrier(string $carrier): array
    {
        $carrier = strtolower(trim($carrier));
        $directory = new PickupDirectory();
        $client = null;
        try {
            if ($carrier === PickupDirectory::MPL_CARRIER && class_exists(MplClient::class)) {
                $client = new MplClient('test');
            } elseif ($carrier === FoxpostFeedClient::CARRIER && class_exists(FoxpostFeedClient::class)) {
                $client = new FoxpostFeedClient();
            }
        } catch (\Throwable $e) {
            $client = null;
        }

        $result = $directory->refresh($carrier, [], $client);
        return [
            'ok' => (bool) $result['ok'],
            'count' => (int) $result['count'],
            'error' => $result['error'],
            'kept' => (int) ($result['kept'] ?? 0),
            'carrier' => $carrier,
        ];
    }

    /** @return array<string,array{ok:bool,count:int,error:?string,kept:int,carrier:string}> */
    public static function refreshAll(): array
    {
        $out = [];
        foreach (self::carriers() as $carrier) {
            $out[$carrier] = self::refreshCarrier($carrier);
        }
        return $out;
    }

    // ---- admin diagnostics -------------------------------------------------------------------------

    /** "Csomagpont könyvtár" panel on the shipping tab (rendered after the per-method form). */
    public static function renderDirectoryDiagnostics(): void
    {
        if (class_exists(AdminShell::class) && !AdminShell::userCanAccess()) {
            return;
        }
        $directory = new PickupDirectory();
        $now = time();

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Csomagpont könyvtár', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('Fuvarozónkénti pontlista állapota. A sikertelen frissítés soha nem írja felül az utolsó működő listát; az ütemezett frissítés óránként fut, hibánál visszalépéssel.', 'fluentcart-hungary') . '</p>';
        echo '<table class="widefat striped fch-hu-table"><thead><tr><th>' . esc_html__('Fuvarozó', 'fluentcart-hungary') . '</th><th>' . esc_html__('Pontok', 'fluentcart-hungary') . '</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Verzió', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
        foreach (self::carriers() as $carrier) {
            $entry = $directory->load($carrier);
            $count = count($entry['points']);
            $fresh = PickupDirectory::isFreshAt($entry, $now);
            $age = PickupDirectory::ageAt($entry, $now);
            $status = $count === 0
                ? esc_html__('nincs lista', 'fluentcart-hungary')
                : ($fresh ? esc_html__('friss', 'fluentcart-hungary') : esc_html__('elavult', 'fluentcart-hungary'));
            $error = trim((string) ($entry['last_error'] ?? ''));
            echo '<tr>';
            echo '<td><strong>' . esc_html(self::carrierLabel((string) $carrier)) . '</strong><br /><code>' . esc_html((string) $carrier) . '</code></td>';
            echo '<td>' . (int) $count . '</td>';
            echo '<td>' . $status . ($age !== null ? ' · ' . esc_html(sprintf(__('%d mp', 'fluentcart-hungary'), $age)) : ' · ' . esc_html__('soha', 'fluentcart-hungary')) . ($error !== '' ? '<br /><span class="fch-hu-badge fch-hu-badge-error">' . esc_html($error) . '</span>' : '') . '</td>';
            echo '<td><code>' . esc_html((string) ($entry['version'] ?? '-')) . '</code></td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::REFRESH_ACTION, '_wpnonce');
            echo '<input type="hidden" name="action" value="' . esc_attr(self::REFRESH_ACTION) . '" />';
            echo '<input type="hidden" name="fch_carrier" value="' . esc_attr((string) $carrier) . '" />';
            echo '<button type="submit" class="button button-small">' . esc_html__('Frissítés most', 'fluentcart-hungary') . '</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:.5em">';
        wp_nonce_field(self::REFRESH_ACTION, '_wpnonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::REFRESH_ACTION) . '" />';
        echo '<input type="hidden" name="fch_carrier" value="all" />';
        echo '<button type="submit" class="button">' . esc_html__('Összes frissítése', 'fluentcart-hungary') . '</button>';
        echo '</form>';

        self::renderRoutingSection();
        echo '</section>';
    }

    // ---- carrier routing ops (3/3 gate) -----------------------------------------------------------

    /** Legacy-default policy + the currently unresolved orders (actionable admin state). */
    private static function renderRoutingSection(): void
    {
        $default = CarrierBinding::legacyDefaultCarrier();

        echo '<h3 style="margin-top:1.2em">' . esc_html__('Futár-routing (legacy rendelések)', 'fluentcart-hungary') . '</h3>';
        echo '<p class="description">' . esc_html__('Legacy rendeléshez, amelynek nincs rendeléskori futárkötése, kizárólag itt beállított, tartós alapértelmezett futár vagy kézi hozzárendelés választható. Az aktív modulok száma önmagában nem dönt; automatikus találgatás nincs.', 'fluentcart-hungary') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-actions" style="margin-bottom:1em">';
        wp_nonce_field(self::ROUTING_ACTION, '_wpnonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ROUTING_ACTION) . '" />';
        echo '<label>' . esc_html__('Legacy-default futár:', 'fluentcart-hungary') . ' <select name="fch_legacy_default_carrier">';
        printf('<option value="">%s</option>', esc_html__('Nincs (kézi hozzárendelés szükséges)', 'fluentcart-hungary'));
        foreach (CarrierRegistry::all() as $slug => $meta) {
            printf('<option value="%s"%s>%s</option>', esc_attr((string) $slug), selected($default, (string) $slug, false), esc_html((string) ($meta['label'] ?? strtoupper((string) $slug))));
        }
        echo '</select></label> ';
        submit_button(__('Policy mentése', 'fluentcart-hungary'), 'secondary', 'submit', false);
        echo '</form>';

        // Widget drivers (3/3 gate #4): each driver feeds the same server-side resolution/save path.
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1em">';
        wp_nonce_field(self::ROUTING_ACTION, '_wpnonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ROUTING_ACTION) . '" />';
        echo '<table class="widefat striped fch-hu-table" style="max-width:900px"><thead><tr><th>' . esc_html__('Fuvarozó', 'fluentcart-hungary') . '</th><th>' . esc_html__('Választó driver', 'fluentcart-hungary') . '</th><th>' . esc_html__('Megjegyzés', 'fluentcart-hungary') . '</th></tr></thead><tbody>';
        foreach (['foxpost', 'packeta', 'gls'] as $carrier) {
            $current = self::driverFor($carrier);
            echo '<tr><td><strong>' . esc_html(self::carrierLabel($carrier)) . '</strong></td><td><select name="fch_driver_' . esc_attr($carrier) . '">';
            foreach ([self::DRIVER_LOCAL => __('Beépített lista', 'fluentcart-hungary'), self::DRIVER_FOXPOST_WIDGET => __('Foxpost iframe widget', 'fluentcart-hungary'), self::DRIVER_PACKETA_WIDGET => __('Packeta v6 widget', 'fluentcart-hungary'), self::DRIVER_GLS_WIDGET => __('GLS widget (feltételes)', 'fluentcart-hungary')] as $driverId => $label) {
                if (!in_array($driverId, [$carrier === 'foxpost' ? self::DRIVER_FOXPOST_WIDGET : ($carrier === 'packeta' ? self::DRIVER_PACKETA_WIDGET : self::DRIVER_GLS_WIDGET), self::DRIVER_LOCAL], true)) {
                    continue;
                }
                printf('<option value="%s"%s>%s</option>', esc_attr($driverId), selected((string) $current['driver'], $driverId, false), esc_html((string) $label));
            }
            echo '</select></td><td><code>' . esc_html((string) $current['reason']) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><label>' . esc_html__('Packeta widget API Key (nyilvános widget kulcs; a titkos jelszó nem ide tartozik):', 'fluentcart-hungary') . ' <input type="text" name="fch_packeta_widget_key" value="' . esc_attr((string) (self::driverFor('packeta')['api_key'] ?? '')) . '" class="regular-text" /></label></p>';
        echo '<p><label>' . esc_html__('GLS widget asset URL (dokumentált, izolált betöltés; globális base href nélkül):', 'fluentcart-hungary') . ' <input type="text" name="fch_gls_widget_asset" value="' . esc_attr((string) (self::driverFor('gls')['asset'] ?? '')) . '" class="regular-text" /></label></p>';
        submit_button(__('Driverek mentése', 'fluentcart-hungary'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('A widgetkulcs önmagában nem indít szállításkészítést; a widget csak pontazonosítót ad, a mentés a szokásos szerveroldali validációval történik.', 'fluentcart-hungary') . '</p>';
        echo '</form>';

        $ids = self::unresolvedOrderIds();
        echo '<h4>' . esc_html__('Kötés nélküli (feloldatlan) rendelések', 'fluentcart-hungary') . '</h4>';
        if ($ids === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs feloldatlan rendelés.', 'fluentcart-hungary') . '</p>';
            return;
        }
        echo '<table class="widefat striped fch-hu-table"><thead><tr><th>' . esc_html__('Rendelés', 'fluentcart-hungary') . '</th><th>' . esc_html__('Művelet', 'fluentcart-hungary') . '</th></tr></thead><tbody>';
        foreach ($ids as $orderId) {
            echo '<tr><td><a href="' . esc_url(Menu::url(['tab' => 'orders', 'order_id' => (int) $orderId])) . '">#' . (int) $orderId . '</a></td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-actions">';
            wp_nonce_field(self::ASSIGN_ACTION, '_wpnonce');
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ASSIGN_ACTION) . '" />';
            echo '<input type="hidden" name="fch_order" value="' . esc_attr((string) $orderId) . '" />';
            echo '<select name="fch_carrier">';
            foreach (CarrierRegistry::all() as $slug => $meta) {
                printf('<option value="%s">%s</option>', esc_attr((string) $slug), esc_html((string) ($meta['label'] ?? strtoupper((string) $slug))));
            }
            echo '</select> <button type="submit" class="button button-small">' . esc_html__('Hozzárendelés', 'fluentcart-hungary') . '</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('A hozzárendelés rendelésszintű pillanatkép; a bekötött futár címke-jobja ezután indulhat. A döntést későbbi módszer-átkötés nem írja felül.', 'fluentcart-hungary') . '</p>';
    }

    /** @return list<int> */
    private static function unresolvedOrderIds(int $limit = 25): array
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return [];
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}fct_order_meta WHERE meta_key = %s AND meta_value = %s ORDER BY order_id DESC LIMIT %d",
            CarrierBinding::META_STATUS,
            CarrierBinding::STATUS_UNRESOLVED,
            max(1, $limit)
        ));
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** Lock the routing decision at order creation (all server-side paths; no guessing). */
    public static function onOrderCreated($payload): void
    {
        $order = null;
        if (is_array($payload)) {
            $order = $payload['order'] ?? null;
        } elseif (is_object($payload)) {
            $order = $payload->order ?? null;
        }
        if (!is_object($order)) {
            return;
        }
        $resolution = CarrierBinding::resolveForOrder($order, true);
        if (!$resolution['resolved'] && $resolution['applicable'] === true && (int) ($order->shipping_method_id ?? 0) > 0) {
            CarrierBinding::markUnresolved($order);
        }
    }

    public static function handleRoutingSave(): void
    {
        if (class_exists(AdminShell::class) && !AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::ROUTING_ACTION);
        $carrier = isset($_POST['fch_legacy_default_carrier']) ? sanitize_key(wp_unslash((string) $_POST['fch_legacy_default_carrier'])) : '';
        try {
            $settings = new ModuleSettings();
            if (array_key_exists('fch_legacy_default_carrier', $_POST)) {
                $settings->set(self::SCOPE, CarrierBinding::SETTING_LEGACY_DEFAULT, $carrier);
            }
            $allowedDrivers = [
                'foxpost' => [self::DRIVER_LOCAL, self::DRIVER_FOXPOST_WIDGET],
                'packeta' => [self::DRIVER_LOCAL, self::DRIVER_PACKETA_WIDGET],
                'gls' => [self::DRIVER_LOCAL, self::DRIVER_GLS_WIDGET],
            ];
            foreach ($allowedDrivers as $carrierKey => $allowed) {
                $field = 'fch_driver_' . $carrierKey;
                if (array_key_exists($field, $_POST)) {
                    $driver = sanitize_key(wp_unslash((string) $_POST[$field]));
                    $settings->set(self::SCOPE, 'pickup_driver_' . $carrierKey, in_array($driver, $allowed, true) ? $driver : self::DRIVER_LOCAL);
                }
            }
            if (array_key_exists('fch_packeta_widget_key', $_POST)) {
                // Public widget key only; the secret API password lives in the encrypted credential store.
                $settings->set(self::SCOPE, 'pickup_widget_key_packeta', sanitize_text_field(wp_unslash((string) $_POST['fch_packeta_widget_key'])));
            }
            if (array_key_exists('fch_gls_widget_asset', $_POST)) {
                $settings->set(self::SCOPE, 'pickup_gls_widget_asset', esc_url_raw(wp_unslash((string) $_POST['fch_gls_widget_asset'])));
            }
            $notice = 'routing_saved';
        } catch (\Throwable $e) {
            $notice = 'routing_invalid';
        }
        wp_safe_redirect(Menu::url(['tab' => 'shipping', 'fch_notice' => $notice]));
        exit;
    }

    public static function handleAssignCarrier(): void
    {
        if (class_exists(AdminShell::class) && !AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::ASSIGN_ACTION);

        $orderId = isset($_POST['fch_order']) ? (int) $_POST['fch_order'] : 0;
        $carrier = isset($_POST['fch_carrier']) ? sanitize_key(wp_unslash((string) $_POST['fch_carrier'])) : '';
        $service = isset($_POST['fch_service']) ? strtolower(trim((string) wp_unslash($_POST['fch_service']))) : '';

        $order = self::findOrder($orderId);
        $ok = $order !== null && $carrier !== '' && CarrierBinding::assignCarrier($order, $carrier, $service);
        wp_safe_redirect(Menu::url(['tab' => 'orders', 'order_id' => $orderId, 'fch_notice' => $ok ? 'carrier_assigned' : 'invalid']));
        exit;
    }

    private static function findOrder(int $orderId): ?object
    {
        $class = 'FluentCart\\App\\Models\\Order';
        if ($orderId <= 0 || !class_exists($class)) {
            return null;
        }
        $order = $class::find($orderId);
        return is_object($order) ? $order : null;
    }

    public static function handleRefreshAdmin(): void
    {
        if (class_exists(AdminShell::class) && !AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::REFRESH_ACTION);

        $carrier = isset($_POST['fch_carrier']) ? sanitize_key(wp_unslash((string) $_POST['fch_carrier'])) : '';
        $notice = 'pickup_refresh_failed';
        if ($carrier === 'all') {
            $results = self::refreshAll();
            $ok = array_filter($results, static fn(array $r): bool => $r['ok']);
            $notice = $ok !== [] ? 'pickup_refreshed' : 'pickup_refresh_failed';
        } elseif ($carrier !== '') {
            $result = self::refreshCarrier($carrier);
            $notice = $result['ok'] ? 'pickup_refreshed' : 'pickup_refresh_failed';
        }

        $args = ['tab' => 'shipping', 'fch_notice' => $notice];
        if (class_exists(Menu::class)) {
            wp_safe_redirect(Menu::url($args));
        } else {
            wp_safe_redirect(admin_url('admin.php?' . http_build_query($args)));
        }
        exit;
    }

    // ---- WP-CLI (registered only under WP-CLI) ----------------------------------------------------

    public static function registerCli(): void
    {
        if (!class_exists('\WP_CLI')) {
            return;
        }
        \WP_CLI::add_command('fch pickup-import', [self::class, 'cliImport']);
        \WP_CLI::add_command('fch pickup-refresh', [self::class, 'cliRefresh']);
        \WP_CLI::add_command('fch order-carrier', [self::class, 'cliOrderCarrier']);
    }

    /**
     * `wp fch order-carrier <order_id> <carrier> [--service=home|pickup|locker]` — explicit manual
     * carrier assignment for a legacy/unresolved order (never automatic, never a provider call).
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function cliOrderCarrier($args = [], $assoc = []): void
    {
        $args = array_pad((array) $args, 2, null);
        $orderId = (int) $args[0];
        $carrier = strtolower(trim((string) $args[1]));
        $order = self::findOrder($orderId);
        if ($order === null) {
            \WP_CLI::error(sprintf('Order %d not found.', $orderId));
            return;
        }
        $service = isset($assoc['service']) ? strtolower(trim((string) $assoc['service'])) : '';
        if (!CarrierBinding::assignCarrier($order, $carrier, $service)) {
            \WP_CLI::error('A valid carrier slug is required (e.g. mpl, gls, dpd, packeta, foxpost).');
            return;
        }
        $resolution = CarrierBinding::resolveForOrder($order, false);
        \WP_CLI::success(sprintf(
            'Order %d carrier locked to %s (%s).',
            $orderId,
            (string) $resolution['carrier'],
            (string) $resolution['service']
        ));
    }

    /**
     * `wp fch pickup-import <carrier> <file.json>` — import a static point list.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function cliImport($args = [], $assoc = []): void
    {
        $args = array_pad((array) $args, 2, null);
        $carrier = strtolower(trim((string) $args[0]));
        $file = (string) $args[1];

        if ($carrier === '' || $file === '' || !is_file($file)) {
            \WP_CLI::error('Usage: wp fch pickup-import <carrier> <file.json>');
            return;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            \WP_CLI::error('The file is not valid JSON.');
            return;
        }

        $count = (new PickupDirectory())->importStatic($decoded, $carrier);
        \WP_CLI::success(sprintf('Imported %d pickup points for carrier "%s".', $count, $carrier));
    }

    /**
     * `wp fch pickup-refresh <carrier|--all>` — fetch the live directory for one carrier (or all) and
     * cache it. Previously this always refreshed MPL regardless of the argument.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function cliRefresh($args = [], $assoc = []): void
    {
        $args = array_pad((array) $args, 1, null);
        $carrier = strtolower(trim((string) ($args[0] ?? '')));

        if ($carrier === '--all' || $carrier === 'all') {
            $results = self::refreshAll();
            $failed = 0;
            foreach ($results as $name => $result) {
                if ($result['ok']) {
                    \WP_CLI::log(sprintf('%s: %d points (kept %d on failure).', $name, $result['count'], $result['kept']));
                } else {
                    $failed++;
                    \WP_CLI::warning(sprintf('%s: %s (last-good kept: %d points).', $name, (string) $result['error'], $result['kept']));
                }
            }
            if ($failed > 0) {
                \WP_CLI::error(sprintf('Refresh finished with %d failure(s).', $failed));
            }
            \WP_CLI::success('All pickup directories refreshed.');
            return;
        }

        if ($carrier === '') {
            $carrier = PickupDirectory::MPL_CARRIER;
        }
        $result = self::refreshCarrier($carrier);
        if (!$result['ok']) {
            \WP_CLI::error(sprintf(
                'Refresh failed for "%s": %s (last-good kept: %d points).',
                $carrier,
                (string) $result['error'],
                $result['kept']
            ));
            return;
        }
        \WP_CLI::success(sprintf('Refreshed %d pickup points for "%s".', $result['count'], $carrier));
    }
}
