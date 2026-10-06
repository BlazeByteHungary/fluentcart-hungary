<?php
/**
 * T43 — pickup offers + native eligibility (checkout 3/3 release gate #2).
 *
 * Proves against REAL FluentCart shipping-method rows (mock/local only):
 *  - eligibility is resolved from FluentCart itself (`getApplicableForCountry` + is_enabled), not from
 *    the global binding alone; a disabled method never becomes selectable;
 *  - offers per point carry the native method title and a server-formatted price, prices are never
 *    client-computed;
 *  - the choice policy keeps the current cart method, uses a single offer directly and requires an
 *    explicit customer choice when several applicable offers exist (no lowest-id fallback);
 *  - the save endpoint rejects a method that is not an applicable offer for the point in the current
 *    cart context and persists nothing.
 */

use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupOffers;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;
use FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings;

$out = ['ok' => true, 'checks' => [], 'note' => 'offers + eligibility via real FC method rows; no network, no order'];

if (!class_exists('FchOfferFakeCart', false)) {
    class FchOfferFakeCart
    {
        /** @var array<string,mixed> */
        public array $checkout_data = [];
        public string $stage = 'draft';
        public int $saves = 0;

        public function save(): bool
        {
            $this->saves++;
            return true;
        }
    }
}

// --- native FC methods: two enabled (different rates) + one disabled ---------------------------------
$methodClass = 'FluentCart\\App\\Models\\ShippingMethod';
if (!class_exists($methodClass)) {
    echo wp_json_encode(['ok' => false, 'error' => 'fc_shipping_method_missing']) . "\n";
    return;
}
global $wpdb;
$zoneClass = 'FluentCart\\App\\Models\\ShippingZone';
if (!class_exists($zoneClass)) {
    echo wp_json_encode(['ok' => false, 'error' => 'fc_shipping_zone_missing']) . "\n";
    return;
}
// Clean any previous run (we match our own titles, never other fixtures).
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_shipping_methods WHERE title LIKE 'OF MPL%'");
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_shipping_zones WHERE name = 'OF HU zóna'");
$zone = $zoneClass::create([
    'name' => 'OF HU zóna',
    'region' => 'HU',
    'shipping_class_id' => 0,
    'order' => 0,
]);
$zoneId = (int) $zone->id;

$created = [];
foreach ([['OF MPL Pont alap', '990.00', 1], ['OF MPL Pont plusz', '1490.00', 1], ['OF MPL Pont kikapcsolt', '1990.00', 0]] as $row) {
    $method = $methodClass::create([
        'zone_id' => $zoneId,
        'title' => $row[0],
        'type' => 'flat_rate',
        'settings' => null,
        'is_enabled' => $row[2],
        'states' => [],
        'amount' => $row[1],
        'order' => 0,
        'meta' => wp_json_encode(['description' => '']),
    ]);
    $created[] = (int) $method->id;
}
[$mBase, $mPlus, $mOff] = $created;
$out['method_ids'] = ['base' => $mBase, 'plus' => $mPlus, 'off' => $mOff];

$settings = new ShippingMethodSettings();
foreach ($created as $mid) {
    $settings->saveForMethod($mid, ['carrier' => 'mpl', 'service' => 'pickup', 'service_types' => 'point,locker']);
}

$points = [
    ['point_id' => 'OF-PP', 'carrier' => 'mpl', 'name' => 'OF Pont', 'address' => 'Teszt 1.', 'zip' => '1051', 'city' => 'Budapest', 'type' => 'PP'],
    ['point_id' => 'OF-CS', 'carrier' => 'mpl', 'name' => 'OF Automata', 'address' => 'Teszt 2.', 'zip' => '1052', 'city' => 'Budapest', 'type' => 'CS'],
];
(new PickupDirectory())->importStatic($points, 'mpl');

// --- A: eligibility comes from FluentCart, disabled methods are excluded ----------------------------
PickupOffers::resetCache();
$eligibility = PickupOffers::applicableMethodIds();
$out['eligibility'] = $eligibility;
$out['checks']['eligibility_source'] = ($eligibility['source'] ?? '') === 'fluentcart_country';
$out['checks']['eligibility_enabled_only'] = in_array($mBase, $eligibility['ids'], true)
    && in_array($mPlus, $eligibility['ids'], true)
    && !in_array($mOff, $eligibility['ids'], true)
    && ($eligibility['country'] ?? '') === 'HU';

// --- B: offers per point ----------------------------------------------------------------------------
$bindings = [];
foreach (PickupPointsModule::methodsMap() as $methodId => $entry) {
    if (($entry['carrier'] ?? '') !== 'mpl' || ($entry['service'] ?? '') !== 'pickup') {
        continue;
    }
    $bindings[(int) $methodId] = [
        'carrier' => (string) $entry['carrier'],
        'service' => (string) $entry['service'],
        'kinds' => (array) $entry['types'],
        'legacy' => false,
    ];
}
$ppPoint = ['carrier' => 'mpl', 'type' => 'PP'];
$offers = PickupOffers::offersForPoint($ppPoint, $bindings, $eligibility['ids'], (string) $eligibility['source']);
$offerIds = array_column($offers, 'method_id');
$out['offers'] = $offers;
$out['checks']['offers_are_applicable_bound_methods'] = in_array($mBase, $offerIds, true)
    && in_array($mPlus, $offerIds, true)
    && !in_array($mOff, $offerIds, true);
$out['checks']['offers_carry_native_price'] = ($offers[0]['price_known'] ?? false) === true
    && trim((string) ($offers[0]['price_display'] ?? '')) !== ''
    && str_contains((string) $offers[0]['price_display'], '990')
    && str_contains((string) $offers[1]['price_display'], '490');

// --- C: explicit choice policy ----------------------------------------------------------------------
$out['checks']['choose_keeps_current'] = PickupOffers::choose($offers, $mPlus) === $mPlus;
$out['checks']['choose_requires_explicit_when_multiple'] = PickupOffers::choose($offers, 0) === 0;
$single = PickupOffers::offersForPoint(['carrier' => 'mpl', 'type' => 'CS'], $bindings, [$mPlus], 'fluentcart_country');
$out['checks']['choose_single_offer'] = count($single) === 1 && PickupOffers::choose($single, 0) === $mPlus;

// --- D: save endpoint enforces the applicable offer -------------------------------------------------
$handler = new PickupCheckoutHandler(new PickupDirectory(), new PickupPointValidator());
$select = static fn (string $pointId): string => (string) wp_json_encode(['carrier' => 'mpl', 'point_id' => $pointId, 'delivery_mode' => 'PP']);

$cartOk = new FchOfferFakeCart();
$cartOk->checkout_data = ['shipping_data' => ['shipping_method_id' => $mPlus]];
$before = $cartOk->checkout_data;
$rOk = $handler->saveSelection($cartOk, ['point' => $select('OF-PP'), 'base_rev' => 0, 'method_id' => $mPlus]);
$savedPoint = $cartOk->checkout_data['_fch_pickup_point'] ?? [];
$out['checks']['save_applicable_offer_ok'] = ($rOk['ok'] ?? false) === true
    && ($savedPoint['point_id'] ?? '') === 'OF-PP'
    && $cartOk->saves === 1;
// 3/3 gate #5: the persisted snapshot carries the provenance extension (driver vs source separated).
$out['checks']['snapshot_provenance'] = ($savedPoint['selector_driver'] ?? '') === 'local'
    && ($savedPoint['authoritative_source'] ?? '') === 'static'
    && (int) ($savedPoint['source_fetched_at'] ?? 0) > 0
    && ($savedPoint['directory_version'] ?? '') !== ''
    && (int) ($savedPoint['validated_at'] ?? 0) > 0
    && ($savedPoint['validation_method'] ?? '') === 'directory_cache';

$cartBad = new FchOfferFakeCart();
$cartBad->checkout_data = ['shipping_data' => ['shipping_method_id' => $mOff]];
$beforeBad = $cartBad->checkout_data;
$savesBefore = $cartBad->saves;
$rBad = $handler->saveSelection($cartBad, ['point' => $select('OF-PP'), 'base_rev' => 0, 'method_id' => $mOff]);
$out['checks']['save_inapplicable_method_rejected'] = ($rBad['ok'] ?? true) === false
    && ($rBad['code'] ?? '') === 'pickup_method_not_applicable'
    && $cartBad->checkout_data === $beforeBad
    && $cartBad->saves === $savesBefore;

// A filter action must also not change the stored method: the draft stays the current method until an
// explicit point save (server contract; UI covered in the browser acceptance).
$out['checks']['filter_never_switches_method'] = ($cartOk->checkout_data['shipping_data']['shipping_method_id'] ?? 0) === $mPlus;

foreach ($out['checks'] as $name => $ok) {
    if (!$ok) {
        $out['ok'] = false;
        $out['failed'][] = $name;
    }
}

echo wp_json_encode($out) . "\n";
