<?php
/**
 * W4-audit follow-up (AUD-PICKUP-01) — pickup save endpoint + error-shape integration flow.
 * AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab with: wp eval-file tests/integration/php/pickup_checkout_ux_flow.php
 *
 * Proves (no network, no provider call, no order creation):
 *   A. `saveSelection()` persists a point-only change with a compare-and-set revision, preserving
 *      every other `checkout_data` key (PA-DEF-02 contract);
 *   B. a stale/late write conflicts instead of resurrecting an invalidated point;
 *   C. invalid / carrier-mismatched / type-mismatched points are rejected without a write;
 *   D. switching to a non-pickup method clears the draft server-side;
 *   E. `validate_before_process` no longer short-circuits (returns the input untouched — the per-field
 *      error is produced by `validate_data`, the FC-native visible error path, PA-DEF-01);
 *   F. `validate_data` emits the `{field: {code: message}}` shape FluentCart renders;
 *   G. the checkout block renders the FluentCart error-bridge attributes + the fallback mapping is
 *      exposed through `PickupPointsModule::methodsMap()`.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;

if (!class_exists('FchUxFakeCart', false)) {
    class FchUxFakeCart
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

// 3/3 gate #2: the bound methods must exist as REAL FluentCart rows (eligibility source).
$fchSeedMethods = [['7101','MPL csomagpont (teszt)','990.00'],['7102','Házhozszállítás (teszt)','1190.00']];
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

$out = ['ok' => false, 'checks' => [], 'note' => 'save-endpoint + validation contract; no network, no order'];
$methodPickup = 7101;
$methodHome = 7102;

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    (new ModuleSettings())->setMode('test');
    $settings = new ModuleSettings();
    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_carrier', 'mpl');
    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_types', 'PM,CS,PP');
    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_fallback_method', $methodHome);
    $settings->set('shipping', 'method_' . $methodHome . '_pickup_carrier', '');

    $points = [
        ['point_id' => 'MPL-UX-1', 'carrier' => 'mpl', 'name' => 'MPL UX Pont Egy', 'address' => 'Első utca 1.', 'zip' => '1051', 'city' => 'Budapest', 'type' => 'PP', 'lat' => 47.5, 'long' => 19.05],
        ['point_id' => 'MPL-UX-2', 'carrier' => 'mpl', 'name' => 'MPL UX Pont Kettő', 'address' => 'Második utca 2.', 'zip' => '4024', 'city' => 'Debrecen', 'type' => 'CS', 'lat' => 47.53, 'long' => 21.62],
    ];
    update_option('fch_pickup_dir_mpl', [
        'fetched_at' => time(),
        'source' => 'static',
        'ttl' => 86400,
        'points' => array_map(static function (array $p): array {
            $p['raw'] = ['id' => $p['point_id'], 'deliveryplace' => $p['name']];
            return $p;
        }, $points),
    ], false);

    $directory = new PickupDirectory();
    $handler = new PickupCheckoutHandler($directory, new PickupPointValidator());

    $select = static fn(string $id, string $carrier = 'mpl', string $mode = 'PP'): string => (string) json_encode([
        'carrier' => $carrier,
        'point_id' => $id,
        'delivery_mode' => $mode,
    ]);

    // --- A: point-only save with revision + key preservation ----------------------------------------
    $cart = new FchUxFakeCart();
    $cart->checkout_data = [
        'shipping_data' => ['shipping_method_id' => $methodPickup],
        'form_data' => ['order_notes' => 'keep-me'],
    ];

    $r1 = $handler->saveSelection($cart, ['point' => $select('MPL-UX-1'), 'base_rev' => 0, 'method_id' => $methodPickup]);
    $out['checks']['save_a_ok'] = ($r1['ok'] ?? false) === true
        && (int) ($r1['rev'] ?? 0) === 1
        && (string) ($r1['point']['point_id'] ?? '') === 'MPL-UX-1'
        && (string) ($r1['point']['name'] ?? '') === 'MPL UX Pont Egy';
    $out['checks']['save_a_persisted'] = ($cart->checkout_data['_fch_pickup_point']['point_id'] ?? '') === 'MPL-UX-1'
        && (int) ($cart->checkout_data['_fch_pickup_rev'] ?? 0) === 1
        && (int) ($cart->checkout_data['_fch_pickup_saved_at'] ?? 0) > 0
        && ($cart->checkout_data['form_data']['order_notes'] ?? '') === 'keep-me'
        && ($cart->checkout_data['shipping_data']['shipping_method_id'] ?? 0) === $methodPickup
        && $cart->saves === 1; // pure point-only change saved without touching shipping/payment/address

    // --- B: point change A -> B on the same method --------------------------------------------------
    $r2 = $handler->saveSelection($cart, ['point' => $select('MPL-UX-2', 'mpl', 'CS'), 'base_rev' => 1, 'method_id' => $methodPickup]);
    $out['checks']['save_b_ok'] = ($r2['ok'] ?? false) === true
        && (int) ($r2['rev'] ?? 0) === 2
        && ($cart->checkout_data['_fch_pickup_point']['point_id'] ?? '') === 'MPL-UX-2';

    // --- C: late/stale write conflicts, never resurrects ---------------------------------------------
    $before = $cart->checkout_data;
    $savesBefore = $cart->saves;
    $r3 = $handler->saveSelection($cart, ['point' => $select('MPL-UX-1'), 'base_rev' => 1, 'method_id' => $methodPickup]);
    $out['checks']['stale_conflict'] = ($r3['ok'] ?? true) === false
        && ($r3['conflict'] ?? false) === true
        && (int) ($r3['rev'] ?? 0) === 2
        && ($r3['point']['point_id'] ?? '') === 'MPL-UX-2'
        && $cart->checkout_data === $before
        && $cart->saves === $savesBefore;

    // --- D: invalid / mismatch rejection without a write ---------------------------------------------
    $r4 = $handler->saveSelection($cart, ['point' => $select('MPL-NOPE'), 'base_rev' => 2, 'method_id' => $methodPickup]);
    $out['checks']['reject_unknown'] = ($r4['ok'] ?? true) === false
        && ($r4['code'] ?? '') === PickupPointValidator::ERROR_UNKNOWN
        && ($cart->checkout_data['_fch_pickup_point']['point_id'] ?? '') === 'MPL-UX-2'
        && (int) ($cart->checkout_data['_fch_pickup_rev'] ?? 0) === 2;

    $r5 = $handler->saveSelection($cart, ['point' => $select('MPL-UX-1', 'foxpost'), 'base_rev' => 2, 'method_id' => $methodPickup]);
    $out['checks']['reject_carrier'] = ($r5['ok'] ?? true) === false
        && ($r5['code'] ?? '') === PickupPointValidator::ERROR_CARRIER_MISMATCH;

    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_types', 'PM');
    $r6 = $handler->saveSelection($cart, ['point' => $select('MPL-UX-2', 'mpl', 'CS'), 'base_rev' => 2, 'method_id' => $methodPickup]);
    $out['checks']['reject_type'] = ($r6['ok'] ?? true) === false
        && ($r6['code'] ?? '') === PickupPointValidator::ERROR_TYPE_MISMATCH;
    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_types', 'PM,CS,PP');

    // --- E: non-pickup method clears the server-side draft -------------------------------------------
    $cart->checkout_data['shipping_data']['shipping_method_id'] = $methodHome;
    $r7 = $handler->saveSelection($cart, ['point' => '', 'base_rev' => 2, 'method_id' => $methodHome]);
    $out['checks']['clear_on_home'] = ($r7['ok'] ?? false) === true
        && array_key_exists('point', $r7) && $r7['point'] === null
        && !array_key_exists('_fch_pickup_point', $cart->checkout_data)
        && (int) ($cart->checkout_data['_fch_pickup_rev'] ?? 0) === 3
        && ($cart->checkout_data['form_data']['order_notes'] ?? '') === 'keep-me';
    $cart->checkout_data['shipping_data']['shipping_method_id'] = $methodPickup;

    // --- F: context guards ---------------------------------------------------------------------------
    $r8 = $handler->saveSelection($cart, ['point' => $select('MPL-UX-1'), 'base_rev' => 3, 'method_id' => $methodHome]);
    $out['checks']['context_mismatch'] = ($r8['ok'] ?? true) === false && ($r8['stale'] ?? false) === true;

    $cartNoMethod = new FchUxFakeCart();
    $r9 = $handler->saveSelection($cartNoMethod, ['point' => $select('MPL-UX-1'), 'base_rev' => 0, 'method_id' => 0]);
    $out['checks']['context_unavailable'] = ($r9['ok'] ?? true) === false && ($r9['code'] ?? '') === 'context_unavailable';

    // --- G: validation contract (PA-DEF-01) ----------------------------------------------------------
    $passthrough = $handler->validateBeforeProcess(true, ['shipping_method_id' => $methodPickup, '_fch_pickup_point' => '']);
    $out['checks']['before_process_passthrough'] = $passthrough === true;

    $ctxCart = new FchUxFakeCart();
    $ctxCart->checkout_data = ['shipping_data' => ['shipping_method_id' => $methodPickup]];
    $errors = $handler->validateData([], [
        'data' => ['shipping_method_id' => $methodPickup, '_fch_pickup_point' => ''],
        'cart' => $ctxCart,
    ]);
    $errorsBad = $handler->validateData([], [
        'data' => ['shipping_method_id' => $methodPickup, '_fch_pickup_point' => $select('MPL-UX-1', 'foxpost')],
        'cart' => $ctxCart,
    ]);
    $out['checks']['error_shape_required'] = isset($errors['_fch_pickup_point']['required'])
        && is_string($errors['_fch_pickup_point']['required'])
        && $errors['_fch_pickup_point']['required'] !== '';
    $out['checks']['error_shape_invalid'] = isset($errorsBad['_fch_pickup_point']['invalid'])
        && is_string($errorsBad['_fch_pickup_point']['invalid']);

    // --- H: render bridge + fallback mapping ---------------------------------------------------------
    ob_start();
    $handler->renderField(['cart' => $ctxCart]);
    $html = (string) ob_get_clean();
    $out['checks']['render_error_bridge'] = str_contains($html, 'id="_fch_pickup_point"')
        && str_contains($html, 'name="_fch_pickup_point"')
        && str_contains($html, 'data-fluent-cart-checkout-page-form-error')
        && str_contains($html, 'for="_fch_pickup_point"')
        && str_contains($html, 'role="alert"')
        && str_contains($html, 'aria-describedby="fch-pickup-error"')
        && str_contains($html, 'data-fluent-cart-checkout-page-form-input-wrapper')
        && str_contains($html, 'data-fch-pickup-fallback');

    $map = PickupPointsModule::methodsMap();
    $out['checks']['fallback_exposed'] = (int) ($map[(string) $methodPickup]['fallback_method'] ?? 0) === $methodHome;

    $okAll = true;
    foreach ($out['checks'] as $value) {
        if ($value !== true) {
            $okAll = false;
        }
    }
    $out['ok'] = $okAll;
} catch (\Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
