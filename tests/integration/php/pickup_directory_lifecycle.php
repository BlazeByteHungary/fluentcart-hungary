<?php
/**
 * T41 — pickup directory lifecycle + binding/search integration (checkout 2/3 goal).
 *
 * Proves, against the real WordPress option store and the module code:
 *  A. method→carrier/service binding incl. the write-through of the legacy pickup keys;
 *  B. the Foxpost CDN feed refresh through the provider contract (mocked HTTP, no network):
 *     success saves points + version, failure/empty success keep the last-good list and record the
 *     attempt + error, a later success clears the failure state;
 *  C. the common multi-carrier/kind search + pagination numbers equal the stored directory;
 *  D. per-point routable method resolution only offers points a bound method can route;
 *  E. the checkout snapshot meta (`_fch_carrier`, `_fch_service`) for home and pickup bindings.
 *
 * Mock-only: no network, no real provider account.
 */

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

// 3/3 gate #2: the bound methods must exist as REAL FluentCart rows (eligibility source).
$fchSeedMethods = [['8201','Foxpost csomagpont (teszt)','990.00'],['8202','GLS házhoz (teszt)','1490.00'],['8203','MPL csomagpont (teszt)','890.00'],['8204','MPL automata (teszt)','790.00']];
$zoneClass = 'FluentCart\\App\\Models\\ShippingZone';
$methodClass = 'FluentCart\\App\\Models\\ShippingMethod';
if (class_exists($zoneClass) && class_exists($methodClass)) {
    global $wpdb;
    $zoneId = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_shipping_zones ORDER BY id LIMIT 1");
    if ($zoneId <= 0) {
        $zone = $zoneClass::create(['name' => 'FCH tesz zóna', 'region' => 'HU', 'shipping_class_id' => 0, 'order' => 0]);
        $zoneId = (int) $zone->id;
    }
    foreach ($fchSeedMethods as $seed) {
        if ($methodClass::find((int) $seed[0]) === null) {
            $method = $methodClass::create([
                'zone_id' => $zoneId, 'title' => $seed[1], 'type' => 'flat_rate',
                'settings' => null, 'is_enabled' => 1, 'states' => [], 'amount' => $seed[2], 'order' => 0,
                'meta' => wp_json_encode(['description' => '']),
            ]);
            $actualId = (int) $method->id;
            if ($actualId !== (int) $seed[0]) {
                // The ORM ignores a forced primary key on create: re-point the row to the fixture id.
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_shipping_methods SET id = %d WHERE id = %d", (int) $seed[0], $actualId));
            }
        } else {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_shipping_methods SET zone_id = %d, is_enabled = 1, states = '[]' WHERE id = %d", $zoneId, (int) $seed[0]));
        }
    }
}

$out = ['ok' => true, 'checks' => []];

$settings = new ModuleSettings();
$settings->set('shipping', 'method_8201_carrier', 'foxpost');
$settings->set('shipping', 'method_8201_service', 'pickup');
$settings->set('shipping', 'method_8201_service_types', 'locker');
$settings->set('shipping', 'method_8202_carrier', 'gls');
$settings->set('shipping', 'method_8202_service', 'home');
$settings->set('shipping', 'method_8203_pickup_carrier', 'mpl'); // legacy-only pickup config
$settings->set('shipping', 'method_8203_pickup_types', 'CS');

// A. binding + legacy write-through.
$bindFoxpost = CarrierBinding::binding(8201);
$out['checks']['binding_foxpost_locker'] = $bindFoxpost['carrier'] === 'foxpost'
    && $bindFoxpost['service'] === 'pickup' && $bindFoxpost['kinds'] === ['locker'];
$out['checks']['binding_legacy_mpl'] = CarrierBinding::binding(8203)['service'] === 'pickup'
    && CarrierBinding::binding(8203)['kinds'] === ['locker'];
$methodsMap = PickupPointsModule::methodsMap();
$out['checks']['methods_map_services'] = ($methodsMap['8201']['service'] ?? '') === 'pickup'
    && ($methodsMap['8202']['service'] ?? '') === 'home'
    && ($methodsMap['8202']['required'] ?? true) === false;

$shipSettings = new ShippingMethodSettings();
$shipSettings->saveForMethod(8204, ['carrier' => 'mpl', 'service' => 'pickup', 'service_types' => 'locker,point']);
$out['checks']['write_through_legacy_keys'] = $shipSettings->pickupCarrier(8204) === 'mpl'
    && CarrierBinding::binding(8204)['kinds'] === ['locker', 'point'];

// B. Foxpost CDN refresh lifecycle (mocked).
$feed = [];
for ($i = 1; $i <= 45; $i++) {
    $feed[] = [
        'operator_id' => 'FX' . $i,
        'place_id' => 'PL' . $i,
        'name' => 'Foxpost Automata ' . $i,
        'address' => 'Teszt utca ' . $i . '.',
        'zip' => sprintf('%04d', 1000 + $i),
        'city' => $i % 2 === 0 ? 'Budapest' : 'Debrecen',
        'variant' => 'FOXPOST A-BOX', // documented variant (locker)
        'geolat' => 47.5 + ($i / 1000),
        'geolng' => 19.05 + ($i / 1000),
    ];
}
$feed[] = ['place_id' => 'FX-LEGACY-1', 'name' => 'Foxpost Régi', 'zip' => '1052', 'city' => 'Budapest', 'variant' => 'FOXPOST A-BOX'];

$mock = MockTransport::make()->install();
$mock->on('cdn.foxpost.hu', ['status' => 200, 'body' => (string) wp_json_encode($feed)]);

// Ensure a known starting state for the foxpost option.
delete_option(PickupDirectory::OPTION_PREFIX . 'foxpost');

$first = PickupPointsModule::refreshCarrier('foxpost');
$entry = (new PickupDirectory())->load('foxpost');
$out['checks']['feed_refresh_ok'] = ($first['ok'] ?? false) === true && ($first['count'] ?? 0) === 46;
$out['checks']['feed_version_saved'] = $entry['version'] !== '' && count($entry['points']) === 46
    && $entry['source'] === 'foxpost' && (int) $entry['attempts'] === 0;
$fxIds = array_column($entry['points'], 'point_id');
$out['checks']['feed_operator_id_priority'] = $fxIds[0] === 'FX1' && in_array('FX-LEGACY-1', $fxIds, true);

// Failure keeps last-good.
$mock->uninstall();
$mockFail = MockTransport::make()->install()->on('cdn.foxpost.hu', ['status' => 500, 'body' => 'boom']);
$failed = PickupPointsModule::refreshCarrier('foxpost');
$entryAfterFail = (new PickupDirectory())->load('foxpost');
$out['checks']['failure_keeps_last_good'] = ($failed['ok'] ?? true) === false
    && ($failed['kept'] ?? 0) === 46
    && count($entryAfterFail['points']) === 46
    && $entryAfterFail['fetched_at'] === $entry['fetched_at']
    && $entryAfterFail['version'] === $entry['version']
    && $entryAfterFail['last_error'] !== ''
    && (int) $entryAfterFail['attempts'] === 1;
$mockFail->uninstall();

// Empty success is treated as failure too (never a silent wipe).
$mockEmpty = MockTransport::make()->install()->on('cdn.foxpost.hu', ['status' => 200, 'body' => '[]']);
$empty = PickupPointsModule::refreshCarrier('foxpost');
$out['checks']['empty_feed_keeps_last_good'] = ($empty['ok'] ?? true) === false
    && ($empty['error'] ?? '') === 'foxpost_empty_feed'
    && ($empty['kept'] ?? 0) === 46
    && count((new PickupDirectory())->load('foxpost')['points']) === 46;
$mockEmpty->uninstall();

// Recovery clears the failure state.
$mockOk = MockTransport::make()->install()->on('cdn.foxpost.hu', ['status' => 200, 'body' => (string) wp_json_encode($feed)]);
$recovered = PickupPointsModule::refreshCarrier('foxpost');
$entryRecovered = (new PickupDirectory())->load('foxpost');
$out['checks']['recovery_clears_error'] = ($recovered['ok'] ?? false) === true
    && (int) $entryRecovered['attempts'] === 0 && $entryRecovered['last_error'] === '';
$mockOk->uninstall();

// A real MPL import (static, no network) adds a second carrier with both kinds.
$mplPoints = [
    ['point_id' => 'MPL-IT-1', 'carrier' => 'mpl', 'name' => 'MPL Pont', 'zip' => '1051', 'city' => 'Budapest', 'type' => 'PP'],
    ['point_id' => 'MPL-IT-2', 'carrier' => 'mpl', 'name' => 'MPL Automata', 'zip' => '1052', 'city' => 'Budapest', 'type' => 'CS'],
];
(new PickupDirectory())->importStatic($mplPoints, 'mpl');

// C. Common search cross-checked against the stored directories (no brittle absolute counts —
// earlier flows may already have cached their own mock points).
$directory = new PickupDirectory();
$carriers = ['foxpost', 'mpl'];
$expectedTotal = count($directory->points('foxpost')) + count($directory->points('mpl'));
$all = $directory->searchMulti($carriers, '', [], 1, 20);
$out['checks']['search_total_matches_directory'] = $all['total'] === $expectedTotal
    && $all['total'] > 40
    && $all['pages'] === (int) ceil($expectedTotal / 20)
    && array_sum($all['per_carrier']) === $expectedTotal;
$seen = [];
for ($page = 1; $page <= $all['pages']; $page++) {
    foreach ($directory->searchMulti($carriers, '', [], $page, 20)['points'] as $point) {
        $seen[] = $point['carrier'] . ':' . $point['point_id'];
    }
}
$out['checks']['search_all_pages_cover_directory'] = count($seen) === $expectedTotal
    && count(array_unique($seen)) === $expectedTotal
    && in_array('foxpost:FX1', $seen, true) && in_array('mpl:MPL-IT-2', $seen, true);

$expectedLockers = 0;
foreach ($carriers as $carrier) {
    foreach (PickupDirectory::matches($directory->points($carrier), '', ['locker']) as $ignored) {
        $expectedLockers++;
    }
}
$lockers = $directory->searchMulti($carriers, '', ['locker'], 1, 100);
$out['checks']['search_kind_filter'] = $lockers['total'] === $expectedLockers
    && $lockers['total'] >= 47 // 46 foxpost APM + the imported MPL CS
    && ($lockers['per_carrier']['foxpost'] ?? 0) === 46;

$expectedDebrecen = 0;
foreach ($carriers as $carrier) {
    $expectedDebrecen += count(PickupDirectory::matches($directory->points($carrier), 'Debrecen', []));
}
$deb = $directory->searchMulti($carriers, 'Debrecen', [], 1, 100);
$out['checks']['search_query_filter'] = $deb['total'] === $expectedDebrecen
    && $deb['total'] >= 23
    && ($deb['per_carrier']['mpl'] ?? -1) === 0; // the MPL import only has Budapest points

// D. Routable method resolution: only bound routes are offered.
$bindings = [];
foreach ($methodsMap as $methodId => $config) {
    if (CarrierBinding::isPickupService((string) $config['service'])) {
        $bindings[(int) $methodId] = ['carrier' => (string) $config['carrier'], 'service' => (string) $config['service'], 'kinds' => (array) $config['types'], 'legacy' => false];
    }
}
$expectedMplLocker = 0;
ksort($bindings); // methodForPoint() documents ascending method id as the tie-break
foreach ($bindings as $methodId => $binding) {
    if ($binding['carrier'] === 'mpl' && in_array('locker', $binding['kinds'], true)) {
        $expectedMplLocker = (int) $methodId;
        break;
    }
}
$out['checks']['route_foxpost_locker'] = CarrierBinding::methodForPoint($bindings, 'foxpost', 'locker') === 8201;
$out['checks']['route_mpl_locker'] = $expectedMplLocker > 0
    && CarrierBinding::methodForPoint($bindings, 'mpl', 'locker') === $expectedMplLocker;
$out['checks']['route_unbound_carrier'] = CarrierBinding::methodForPoint($bindings, 'dpd', 'locker') === 0;

// E. Snapshot meta for home and pickup bindings (order object stub with updateMeta).
$order = new class {
    /** @var array<string,mixed> */
    public array $meta = [];
    public function updateMeta(string $key, $value): void
    {
        $this->meta[$key] = $value;
    }
};
$handler = new PickupCheckoutHandler(new PickupDirectory());
$handler->prepareOtherData([
    'order' => $order,
    'request_data' => ['shipping_method_id' => 8202],
    'cart' => null,
]);
$out['checks']['home_snapshot_meta'] = ($order->meta['_fch_carrier'] ?? '') === 'gls'
    && ($order->meta['_fch_service'] ?? '') === 'home'
    && !isset($order->meta['_fch_pickup_point']);

$handler->prepareOtherData([
    'order' => $order,
    'request_data' => [
        'shipping_method_id' => 8201,
        '_fch_pickup_point' => wp_json_encode(['carrier' => 'foxpost', 'point_id' => 'FX1', 'delivery_mode' => 'APM']),
    ],
    'cart' => null,
]);
$out['checks']['pickup_snapshot_meta'] = ($order->meta['_fch_carrier'] ?? '') === 'foxpost'
    && ($order->meta['_fch_service'] ?? '') === 'pickup'
    && str_contains((string) ($order->meta['_fch_pickup_point'] ?? ''), 'FX1');

foreach ($out['checks'] as $name => $ok) {
    if (!$ok) {
        $out['ok'] = false;
        $out['failed'][] = $name;
    }
}
$out['mode'] = 'mock-only';
$out['note'] = 'directory lifecycle + binding/search/routing; no network, no provider account';

echo wp_json_encode($out), "\n";
