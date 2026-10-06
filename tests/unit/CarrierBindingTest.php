<?php
declare(strict_types=1);

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;

/**
 * Checkout 2/3 goal — binding, directory lifecycle and common search (WP-free harness).
 * A tiny option store stub lets ModuleSettings/PickupDirectory run without WordPress.
 */
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__fch_test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS['__fch_test_options'][$name] = $value;
        return true;
    }
}
$GLOBALS['__fch_test_options'] = [];

fch_test('CB-KIND-01 kindFromNative maps the provider vocabularies', function (): void {
    assert_same('point', CarrierBinding::kindFromNative('mpl', 'PP'));
    assert_same('point', CarrierBinding::kindFromNative('mpl', 'PM'));
    assert_same('locker', CarrierBinding::kindFromNative('mpl', 'CS'));
    // Foxpost namespace per the official apt-finder docs: FOXPOST/Z-BOX = locker, Z-Pont = point,
    // unknown variant names are never invented into a kind.
    assert_same('locker', CarrierBinding::kindFromNative('foxpost', 'FOXPOST A-BOX'));
    assert_same('locker', CarrierBinding::kindFromNative('foxpost', 'FOXPOST Z-BOX'));
    assert_same('locker', CarrierBinding::kindFromNative('foxpost', 'FOXPOST'));
    assert_same('point', CarrierBinding::kindFromNative('foxpost', 'Packeta Z-Pont'));
    assert_same('', CarrierBinding::kindFromNative('foxpost', 'APM'));
    assert_same('', CarrierBinding::kindFromNative('foxpost', 'barmi'));
    assert_same('locker', CarrierBinding::kindFromNative('packeta', 'Z-BOX'));
    assert_same('point', CarrierBinding::kindFromNative('packeta', 'PICKUP_POINT'));
    assert_same('locker', CarrierBinding::kindFromNative('gls', 'LOCKER'));
    assert_same('point', CarrierBinding::kindFromNative('gls', 'PSD'));
    assert_same('', CarrierBinding::kindFromNative('mpl', 'XYZ'));
    assert_same('', CarrierBinding::kindFromNative('mpl', ''));
});

fch_test('CB-KINDS-02 parseKinds and kindsFromLegacy normalize mixed vocabularies', function (): void {
    assert_same(['point', 'locker'], CarrierBinding::parseKinds('point, LOCKER , x'));
    assert_same([], CarrierBinding::parseKinds(''));
    assert_same(['point', 'locker'], CarrierBinding::kindsFromLegacy('mpl', 'PM,CS,PP'));
    assert_same(['point', 'locker'], CarrierBinding::kindsFromLegacy('mpl', 'point,locker'));
    assert_same([], CarrierBinding::kindsFromLegacy('mpl', 'CSX'));
});

fch_test('CB-BIND-03 binding() reads explicit keys and keeps the legacy pickup keys working', function (): void {
    $settings = new ModuleSettings();
    $settings->set('shipping', 'method_11_carrier', 'gls');
    $settings->set('shipping', 'method_11_service', 'home');
    $binding = CarrierBinding::binding(11);
    assert_same('gls', $binding['carrier']);
    assert_same('home', $binding['service']);
    assert_same(false, $binding['legacy']);

    // Legacy-only pickup configuration (W1/W2) resolves to a pickup binding.
    $settings->set('shipping', 'method_12_pickup_carrier', 'mpl');
    $settings->set('shipping', 'method_12_pickup_types', 'PM,CS');
    $legacy = CarrierBinding::binding(12);
    assert_same('mpl', $legacy['carrier']);
    assert_same('pickup', $legacy['service']);
    assert_same(['point', 'locker'], $legacy['kinds']);
    assert_same(true, $legacy['legacy']);

    assert_same('', CarrierBinding::binding(13)['carrier']);
    assert_same('', CarrierBinding::binding(0)['carrier']);
});

fch_test('CB-ACCEPT-04 acceptsPoint respects carrier and kind filters', function (): void {
    $locker = ['carrier' => 'mpl', 'type' => 'CS'];
    $point = ['carrier' => 'mpl', 'type' => 'PP'];
    assert_true(CarrierBinding::acceptsPoint(['carrier' => 'mpl', 'kinds' => []], $locker));
    assert_true(CarrierBinding::acceptsPoint(['carrier' => 'mpl', 'kinds' => ['locker']], $locker));
    assert_true(!CarrierBinding::acceptsPoint(['carrier' => 'mpl', 'kinds' => ['locker']], $point));
    assert_true(!CarrierBinding::acceptsPoint(['carrier' => 'foxpost', 'kinds' => []], $locker));
});

fch_test('CB-ROUTE-05 methodForPoint prefers the typed method and falls back deterministically', function (): void {
    $bindings = [
        5 => ['carrier' => 'foxpost', 'service' => 'pickup', 'kinds' => [], 'legacy' => false],
        2 => ['carrier' => 'foxpost', 'service' => 'locker', 'kinds' => ['locker'], 'legacy' => false],
        9 => ['carrier' => 'gls', 'service' => 'home', 'kinds' => [], 'legacy' => false],
    ];
    assert_same(2, CarrierBinding::methodForPoint($bindings, 'foxpost', 'locker'));
    assert_same(5, CarrierBinding::methodForPoint($bindings, 'foxpost', 'point'));
    assert_same(0, CarrierBinding::methodForPoint($bindings, 'mpl', 'locker'));
    assert_same(0, CarrierBinding::methodForPoint($bindings, 'gls', 'locker')); // home binding never routes points
});

/** Order stub with meta storage for the routing tests (duck-typed, no FluentCart class). */
final class CarrierBindingTestOrder
{
    /** @var array<string,mixed> */
    public array $meta = [];

    /** @var array<string,mixed> */
    public array $config = [];

    public function __construct(public int $shipping_method_id = 0, public float $shipping_total = 0.0)
    {
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function updateMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }
}

fch_test('CB-GATE-06 routing gate: bound wins, legacy is unresolved (never guessed), default policy is explicit', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $settings = new ModuleSettings();
    $settings->set('shipping', 'method_21_carrier', 'gls');
    $settings->set('shipping', 'method_21_service', 'home');

    $bound = new CarrierBindingTestOrder(21);
    assert_true(CarrierBinding::isBoundTo($bound, 'gls'));
    assert_true(!CarrierBinding::isBoundTo($bound, 'mpl'));
    // The gate locks the decision on first resolve (later re-binding cannot change it).
    assert_same('gls', CarrierBinding::resolveForOrder($bound, true)['carrier']);
    assert_same('gls', $bound->meta['_fch_carrier']);
    assert_same('home', $bound->meta['_fch_service']);
    $settings->set('shipping', 'method_21_carrier', 'dpd'); // admin re-binds the method afterwards
    assert_same('gls', CarrierBinding::resolveForOrder($bound, true)['carrier'], 'locked snapshot wins over live config');

    $snapshotOnly = new CarrierBindingTestOrder(22);
    $snapshotOnly->meta['_fch_carrier'] = 'mpl';
    assert_true(CarrierBinding::isBoundTo($snapshotOnly, 'mpl'));
    assert_true(!CarrierBinding::isBoundTo($snapshotOnly, 'gls'));

    $unresolved = new CarrierBindingTestOrder(23, 1190.0);
    assert_true(!CarrierBinding::isBoundTo($unresolved, 'gls'));
    $guard = CarrierBinding::guardForCarrier($unresolved, 'gls');
    assert_same('carrier_unresolved', $guard['reason']);
    assert_true(!$guard['allow']);
    assert_same('unresolved', $unresolved->meta['_fch_carrier_status']);
    // A later method binding must NOT silently adopt a carrier after the unresolved marker.
    $settings->set('shipping', 'method_23_carrier', 'mpl');
    assert_same('marked_unresolved', CarrierBinding::resolveForOrder($unresolved, true)['source']);
    assert_same('', $unresolved->meta['_fch_carrier'] ?? '');

    // Explicit legacy-default policy is the only automatic fallback (and it locks the snapshot).
    $settings->set('shipping', CarrierBinding::SETTING_LEGACY_DEFAULT, 'mpl');
    $legacyDefault = new CarrierBindingTestOrder(24, 1490.0);
    $resolved = CarrierBinding::resolveForOrder($legacyDefault, true);
    assert_same('legacy_default_policy', $resolved['source']);
    assert_same('mpl', $resolved['carrier']);
    assert_same('home', $legacyDefault->meta['_fch_service']);
    $settings->set('shipping', CarrierBinding::SETTING_LEGACY_DEFAULT, '');

    // Digital / no-shipping orders are not_applicable (silent skip, no unresolved marker).
    $digital = new CarrierBindingTestOrder(0, 0.0);
    $digitalGuard = CarrierBinding::guardForCarrier($digital, 'gls');
    assert_same('not_applicable', $digitalGuard['reason']);
    assert_same('', $digital->meta['_fch_carrier_status'] ?? '');
});

fch_test('CB-CONFIG-12 method id is read from the FluentCart config JSON when the property is absent', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $settings = new ModuleSettings();
    $settings->set('shipping', 'method_41_carrier', 'foxpost');
    $settings->set('shipping', 'method_41_service', 'locker');

    $order = new CarrierBindingTestOrder(0, 990.0);
    $order->config = ['shipping_method_id' => 41, 'shipping_method_title' => 'Foxpost'];
    $resolved = CarrierBinding::resolveForOrder($order, true);
    assert_same('foxpost', $resolved['carrier']);
    assert_same('method_binding', $resolved['source']);
    assert_same('foxpost', $order->meta['_fch_carrier']);
});

fch_test('CB-ASSIGN-11 manual assignment clears the unresolved marker and derives the service', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $order = new CarrierBindingTestOrder(31, 1490.0);
    CarrierBinding::markUnresolved($order);
    assert_true(CarrierBinding::isUnresolved($order));
    assert_true(CarrierBinding::assignCarrier($order, 'gls'));
    assert_same('gls', $order->meta['_fch_carrier']);
    assert_same('', $order->meta['_fch_carrier_status']);
    assert_same('home', $order->meta['_fch_service']);

    $pickupOrder = new CarrierBindingTestOrder(32, 990.0);
    $pickupOrder->meta['_fch_pickup_point'] = (string) json_encode(['carrier' => 'mpl', 'point_id' => 'P1']);
    assert_true(CarrierBinding::assignCarrier($pickupOrder, 'mpl'));
    assert_same('pickup', $pickupOrder->meta['_fch_service']);
    assert_true(CarrierBinding::assignCarrier($pickupOrder, 'mpl', CarrierBinding::SERVICE_LOCKER));
    assert_same('locker', $pickupOrder->meta['_fch_service']);
    assert_true(!CarrierBinding::assignCarrier($pickupOrder, ''));
});

fch_test('PD-STATE-13 stateAt implements the documented fresh/stale/expired policy', function (): void {
    $now = 1_000_000;
    $entry = ['fetched_at' => $now - 100, 'ttl' => 3600, 'points' => [['point_id' => 'a']]];
    assert_same('fresh', PickupDirectory::stateAt($entry, $now));
    assert_same('stale', PickupDirectory::stateAt(['fetched_at' => $now - 4000, 'ttl' => 3600, 'points' => [['point_id' => 'a']]], $now));
    assert_same('expired', PickupDirectory::stateAt(['fetched_at' => $now - 8000, 'ttl' => 3600, 'points' => [['point_id' => 'a']]], $now));
    assert_same('expired', PickupDirectory::stateAt(['fetched_at' => 0, 'ttl' => 3600, 'points' => []], $now));
    assert_same('expired', PickupDirectory::stateAt(['fetched_at' => $now - 10, 'ttl' => 3600, 'points' => []], $now), 'no points = not selectable');
});

fch_test('PD-STATE-14 versionSet changes when any directory generation changes', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $directory = new PickupDirectory();
    $directory->save('mpl', [['point_id' => 'a', 'carrier' => 'mpl']], 'static');
    $directory->save('foxpost', [['point_id' => 'b', 'carrier' => 'foxpost']], 'static');
    $v1 = $directory->versionSet(['mpl', 'foxpost']);
    $v1b = $directory->versionSet(['foxpost', 'mpl']);
    assert_same($v1, $v1b, 'carrier order must not change the fingerprint');
    $directory->save('mpl', [['point_id' => 'a', 'carrier' => 'mpl'], ['point_id' => 'c', 'carrier' => 'mpl']], 'static');
    assert_true($directory->versionSet(['mpl', 'foxpost']) !== $v1);
});

fch_test('PD-VERSION-07 versionOf is content-stable and content-sensitive', function (): void {
    $a = [['point_id' => '1'], ['point_id' => '2']];
    $b = [['point_id' => '2'], ['point_id' => '1']];
    assert_same(PickupDirectory::versionOf($a), PickupDirectory::versionOf($a));
    assert_true(PickupDirectory::versionOf($a) !== PickupDirectory::versionOf($b));
    assert_same(12, strlen(PickupDirectory::versionOf($a)));
});

fch_test('PD-SEARCH-08 searchMulti filters by carrier/kind/query and paginates deterministically', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $directory = new PickupDirectory();
    $points = [];
    for ($i = 1; $i <= 30; $i++) {
        $points[] = ['point_id' => 'M' . $i, 'carrier' => 'mpl', 'name' => 'MPL Pont ' . $i, 'zip' => sprintf('%04d', 1000 + $i), 'city' => 'Budapest', 'type' => $i % 2 === 0 ? 'CS' : 'PP'];
    }
    $directory->save('mpl', $points, 'static');
    $directory->save('foxpost', [['point_id' => 'F1', 'carrier' => 'foxpost', 'name' => 'Foxpost Automata', 'zip' => '1052', 'city' => 'Budapest', 'type' => 'FOXPOST']], 'static');

    $all = $directory->searchMulti(['mpl', 'foxpost'], '', [], 1, 20);
    assert_same(31, $all['total']);
    assert_same(2, $all['pages']);
    assert_same(20, count($all['points']));
    assert_same(['mpl' => 30, 'foxpost' => 1], $all['per_carrier']);

    $pageTwo = $directory->searchMulti(['mpl', 'foxpost'], '', [], 2, 20);
    assert_same(2, $pageTwo['page']);
    assert_same(11, count($pageTwo['points']));
    $pageNine = $directory->searchMulti(['mpl'], '', [], 9, 20);
    assert_same(2, $pageNine['page'], 'page clamps to the last page');

    $lockers = $directory->searchMulti(['mpl', 'foxpost'], '', ['locker'], 1, 50);
    assert_same(16, $lockers['total']); // 15 MPL CS + 1 Foxpost APM
    assert_same(['mpl' => 15, 'foxpost' => 1], $lockers['per_carrier']);

    $query = $directory->searchMulti(['mpl'], '1003', [], 1, 50);
    assert_same(1, $query['total']);
    assert_same('M3', $query['points'][0]['point_id']);

    // Deterministic order: zip ascending, regardless of the carrier argument order.
    $ordered = $directory->searchMulti(['foxpost', 'mpl'], '', [], 1, 50);
    $zips = array_map(static fn(array $p): string => (string) $p['zip'], $ordered['points']);
    $sorted = $zips;
    sort($sorted, SORT_STRING);
    assert_same($sorted, $zips);
});

fch_test('PD-FAIL-09 recordFailure keeps the last-good list; a repeated save keeps the version', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $directory = new PickupDirectory();
    $points = [['point_id' => 'A', 'carrier' => 'mpl', 'name' => 'A', 'zip' => '1000', 'city' => 'Bp', 'type' => 'PP']];
    $directory->save('mpl', $points, 'static');
    $before = $directory->load('mpl');
    assert_true($before['version'] !== '');

    $directory->recordFailure('mpl', 'http_500');
    $after = $directory->load('mpl');
    assert_same($before['points'], $after['points']);
    assert_same($before['fetched_at'], $after['fetched_at']);
    assert_same($before['version'], $after['version']);
    assert_same('http_500', $after['last_error']);
    assert_same(1, $after['attempts']);

    // A no-op successful save (same points) keeps the version but clears the failure state.
    $directory->save('mpl', $points, 'mpl');
    $afterSave = $directory->load('mpl');
    assert_same($before['version'], $afterSave['version']);
    assert_same('', $afterSave['last_error']);
    assert_same(0, $afterSave['attempts']);
});

fch_test('PD-VALIDATOR-10 abstract kinds satisfy explicit allowed lists', function (): void {
    assert_true(PickupPointValidator::typeAccepted('mpl', 'CS', '', ['locker']));
    assert_true(PickupPointValidator::typeAccepted('mpl', 'PP', '', ['point']));
    assert_true(PickupPointValidator::typeAccepted('mpl', 'PP', '', ['PP']));
    assert_true(PickupPointValidator::typeAccepted('mpl', 'PP', '', ['PM,CS,PP'] === [] ? [] : ['PM', 'CS', 'PP']));
    assert_true(!PickupPointValidator::typeAccepted('mpl', 'PP', '', ['locker']));
    assert_true(PickupPointValidator::typeAccepted('mpl', '', '', []));
    assert_true(!PickupPointValidator::typeAccepted('mpl', '', '', ['locker']), 'unclassifiable point never passes an explicit filter');
});

fch_test('PD-FIND-15 findByWidgetId maps documented widget ids to directory points', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $directory = new PickupDirectory();
    $directory->save('foxpost', [
        ['point_id' => 'hu53', 'carrier' => 'foxpost', 'name' => 'A', 'zip' => '1011', 'city' => 'Bp', 'type' => 'FOXPOST A-BOX', 'raw' => ['operator_id' => 'hu53', 'place_id' => '680']],
        ['point_id' => 'hu77', 'carrier' => 'foxpost', 'name' => 'B', 'zip' => '4024', 'city' => 'Debrecen', 'type' => 'Packeta Z-Pont', 'raw' => ['operator_id' => 'hu77', 'place_id' => '1250198']],
    ], 'static');

    assert_same('hu53', $directory->findByWidgetId('foxpost', 'hu53')['point_id'] ?? null);
    assert_same('hu53', $directory->findByWidgetId('foxpost', 'HU53')['point_id'] ?? null, 'operator_id is case-insensitive');
    assert_same('hu77', $directory->findByWidgetId('foxpost', '1250198')['point_id'] ?? null, 'numeric place_id fallback');
    assert_same('hu77', $directory->findByWidgetId('foxpost', '1250198.0')['point_id'] ?? null, 'numeric tolerance');
    assert_same(null, $directory->findByWidgetId('foxpost', 'nope'));
    assert_same(null, $directory->findByWidgetId('gls', 'hu53'), 'carrier scoping');
    assert_same(null, $directory->findByWidgetId('foxpost', ''));
});

fch_test('DRIVER-16 driverFor gates each widget by documented prerequisites', function (): void {
    $GLOBALS['__fch_test_options'] = [];
    $settings = new ModuleSettings();

    // Default: every carrier serves the local list.
    assert_same('local', PickupPointsModule::driverFor('foxpost')['driver']);
    assert_true(PickupPointsModule::driverFor('foxpost')['enabled']);

    // Foxpost iframe: documented URL/origin once selected.
    $settings->set('shipping', 'pickup_driver_foxpost', 'foxpost_widget');
    $foxpost = PickupPointsModule::driverFor('foxpost');
    assert_same('https://cdn.foxpost.hu', $foxpost['origin']);
    assert_true($foxpost['enabled']);

    // Packeta without a merchant widget key: implementable but disabled (BLOCKED live selection).
    $settings->set('shipping', 'pickup_driver_packeta', 'packeta_widget');
    $packeta = PickupPointsModule::driverFor('packeta');
    assert_same('no_widget_key', $packeta['reason']);
    assert_true(!$packeta['enabled']);
    $settings->set('shipping', 'pickup_widget_key_packeta', 'PUBLIC-KEY');
    assert_true(PickupPointsModule::driverFor('packeta')['enabled']);

    // GLS: gated until a documented https asset configuration exists (no global base href).
    $settings->set('shipping', 'pickup_driver_gls', 'gls_widget');
    assert_same('gated_no_documented_asset', PickupPointsModule::driverFor('gls')['reason']);
    assert_true(!PickupPointsModule::driverFor('gls')['enabled']);
    $settings->set('shipping', 'pickup_gls_widget_asset', 'https://map.gls-hungary.com/widget/gls-dpm.js');
    assert_true(PickupPointsModule::driverFor('gls')['enabled']);
});
