<?php
/**
 * T45 — selector drivers: widget-point resolution, locked refresh, Packeta validate (checkout 3/3 gate #4).
 *
 * Mock-only. Proves:
 *  - a widget id resolves to the cached directory via the documented identity (operator_id, place_id
 *    fallback, case-insensitive);
 *  - a point missing from the cache triggers at most ONE locked official directory refresh per carrier
 *    and minute (no per-selection full feed download), and the refreshed point becomes selectable;
 *  - the widget payload alone is never saved: the same offers/validation path is used;
 *  - the documented Packeta widget validate endpoint confirms/refuses a point id and is never live
 *    without a merchant key (the driver stays disabled).
 */

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings;
use FluentCartHungary\Modules\Shipping\PickupPoints\PacketaWidgetClient;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => true, 'checks' => [], 'note' => 'widget resolve + locked refresh + validate; mock-only'];

$settings = new ModuleSettings();
$settings->set('shipping', 'pickup_driver_foxpost', 'foxpost_widget');
$settings->set('shipping', 'pickup_driver_packeta', 'packeta_widget');
$settings->set('shipping', 'pickup_widget_key_packeta', '');
$shipSettings = new ShippingMethodSettings();
$shipSettings->saveForMethod(93301, ['carrier' => 'foxpost', 'service' => 'pickup', 'service_types' => 'locker']);
$shipSettings->saveForMethod(93302, ['carrier' => 'packeta', 'service' => 'pickup', 'service_types' => 'point,locker']);

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_shipping_methods WHERE title LIKE 'WD %'");
$methodClass = 'FluentCart\\App\\Models\\ShippingMethod';
$zoneClass = 'FluentCart\\App\\Models\\ShippingZone';
$zoneId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_shipping_zones ORDER BY id LIMIT 1");
if ($zoneId <= 0) {
    $zone = $zoneClass::create(['name' => 'FCH tesz zóna', 'region' => 'HU', 'shipping_class_id' => 0, 'order' => 0]);
    $zoneId = (int) $zone->id;
}
foreach ([[93301, 'WD Foxpost pont', '990.00'], [93302, 'WD Packeta pont', '890.00']] as $seed) {
    $existing = $methodClass::find((int) $seed[0]);
    if ($existing === null) {
        $method = $methodClass::create([
            'zone_id' => $zoneId, 'title' => $seed[1], 'type' => 'flat_rate', 'settings' => null,
            'is_enabled' => 1, 'states' => [], 'amount' => $seed[2], 'order' => 0,
            'meta' => wp_json_encode(['description' => '']),
        ]);
        $actual = (int) $method->id;
        if ($actual !== (int) $seed[0]) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_shipping_methods SET id = %d WHERE id = %d", (int) $seed[0], $actual));
        }
    } else {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_shipping_methods SET zone_id = %d, is_enabled = 1, states = '[]' WHERE id = %d", $zoneId, (int) $seed[0]));
    }
}

// Initial foxpost directory: one point; the widget will deliver a NEW id that needs the locked refresh.
$feedV1 = [['place_id' => 680, 'operator_id' => 'hu53', 'name' => 'Foxpost A-BOX', 'address' => 'Batthyány tér', 'zip' => '1011', 'city' => 'Budapest', 'variant' => 'FOXPOST A-BOX']];
$feedV2 = array_merge($feedV1, [['place_id' => 1250198, 'operator_id' => 'hu5516', 'name' => 'Foxpost Új Pont', 'address' => 'Új utca 1.', 'zip' => '9081', 'city' => 'Győrújbarát', 'variant' => 'FOXPOST Z-BOX']]);

$feedCalls = 0;
$mock = MockTransport::make()
    ->on('cdn.foxpost.hu', function () use (&$feedCalls, $feedV1, $feedV2) {
        $feedCalls++;
        return MockTransport::json(200, $feedCalls === 1 ? $feedV1 : $feedV2);
    })
    ->on('widget.packeta.com', function ($url, $args) {
        $body = is_array($args['body'] ?? null) ? (string) wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
        if (str_contains($body, 'PACK-KEY') && str_contains($body, '999')) {
            return MockTransport::json(200, ['isValid' => false, 'point' => null, 'errors' => ['not_found']]);
        }
        return MockTransport::json(200, ['isValid' => true, 'point' => ['id' => '111'], 'errors' => []]);
    })
    ->install();

delete_option('fch_pickup_dir_foxpost');
delete_option('fch_pickup_widget_refresh_at_foxpost');
$directory = new PickupDirectory();
PickupPointsModule::refreshCarrier('foxpost'); // initial locked official refresh (feed V1)
$out['checks']['initial_directory'] = count($directory->load('foxpost')['points']) === 1;

$handler = new PickupCheckoutHandler(new PickupDirectory(), new PickupPointValidator());

// --- A: cached id resolves through the documented identity -------------------------------------------
$resolvedCached = $handler->resolveWidgetPoint('foxpost', 'HU53');
$out['checks']['cached_widget_point_resolves'] = ($resolvedCached['found'] ?? false) === true
    && ($resolvedCached['point']['point_id'] ?? '') === 'hu53'
    && !empty($resolvedCached['point']['offers'])
    && $feedCalls === 1;

// --- B: unknown id → exactly one locked refresh, then found -------------------------------------------
$callsBefore = $feedCalls;
$resolvedNew = $handler->resolveWidgetPoint('foxpost', 'hu5516');
$out['checks']['unknown_point_triggers_locked_refresh'] = ($resolvedNew['found'] ?? false) === true
    && ($resolvedNew['refreshed'] ?? false) === true
    && ($resolvedNew['point']['point_id'] ?? '') === 'hu5516'
    && $feedCalls === $callsBefore + 1;

// --- C: a second unknown id inside the lock window does NOT download the feed again -------------------
$callsBefore = $feedCalls;
$resolvedLocked = $handler->resolveWidgetPoint('foxpost', 'nope-12345');
$out['checks']['locked_window_blocks_second_refresh'] = ($resolvedLocked['found'] ?? true) === false
    && ($resolvedLocked['refreshed'] ?? true) === false
    && ($resolvedLocked['code'] ?? '') === 'widget_point_not_found'
    && $feedCalls === $callsBefore;

// --- D: after the lock window a new attempt may refresh once more -------------------------------------
update_option('fch_pickup_widget_refresh_at_foxpost', time() - 120, false);
$callsBefore = $feedCalls;
$resolvedAgain = $handler->resolveWidgetPoint('foxpost', 'still-missing');
$out['checks']['lock_expires_after_window'] = ($resolvedAgain['refreshed'] ?? false) === true && $feedCalls === $callsBefore + 1;

// --- E: Packeta driver gating + documented validate ---------------------------------------------------
$disabled = $handler->resolveWidgetPoint('packeta', '111');
$out['checks']['packeta_without_key_disabled'] = ($disabled['ok'] ?? true) === false
    && ($disabled['code'] ?? '') === 'driver_unavailable';
$settings->set('shipping', 'pickup_widget_key_packeta', 'PACK-KEY');
$client = new PacketaWidgetClient();
$validOk = $client->validate('PACK-KEY', '111');
$validNo = $client->validate('PACK-KEY', '999');
$out['checks']['packeta_validate_ok'] = ($validOk['ok'] ?? false) === true && ($validOk['is_valid'] ?? false) === true;
$out['checks']['packeta_validate_rejects_unknown'] = ($validNo['ok'] ?? false) === true && ($validNo['is_valid'] ?? false) === false;
// With a key but no directory data the point cannot be resolved → never saved blindly.
$packetaResolve = $handler->resolveWidgetPoint('packeta', '111');
$out['checks']['packeta_resolve_requires_directory'] = ($packetaResolve['found'] ?? true) === false
    && in_array((string) ($packetaResolve['code'] ?? ''), ['widget_point_not_found', 'widget_point_not_selectable'], true);

$mock->uninstall();

foreach ($out['checks'] as $name => $ok) {
    if (!$ok) {
        $out['ok'] = false;
        $out['failed'][] = $name;
    }
}
$out['feed_calls'] = $feedCalls;

echo wp_json_encode($out) . "\n";
