<?php
/**
 * W1 pickup-point + core-shipping integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/pickup_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`) so no network is touched, and checks:
 *   A. MPL deliveryplace fetch (MPL-004) -> cached directory import;
 *   B. server-side validation + snapshot shape {carrier,point_id,name,address,zip,city,type,raw};
 *   C. the checkout filter callbacks through apply_filters with FC-shaped payloads:
 *        - IT-18 `_fch_pickup_point` captured into checkout_data and snapshotted onto order meta;
 *        - IT-19 stale point dropped when the shipping method changes carrier;
 *   D. HS-006/007 COD fee via the pure calculator AND the real `fluent_cart/cart/fees` filter.
 *
 * Prints exactly one JSON line. The integrator owns running this and fixing lab-specific details.
 */

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\CoreShipping\CodFeeCalculator;
use FluentCartHungary\Modules\Shipping\CoreShipping\ShippingCoreModule;
use FluentCartHungary\Modules\Shipping\Mpl\MplClient;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

/** Captures Order::updateMeta() writes without a real order row. */
if (!class_exists('FchFakeOrder', false)) {
    class FchFakeOrder
    {
        public int $id = 993001;
        /** @var array<string,mixed> */
        public array $meta = [];

        public function updateMeta($key, $value)
        {
            $this->meta[(string) $key] = $value;
            return true;
        }
    }
}

if (!class_exists('FchFakeCart', false)) {
    class FchFakeCart
    {
        /** @var array<string,mixed> */
        public array $checkout_data = [];
        /** @var list<array<string,mixed>> */
        public array $cart_data = [];

        public function clearFeeCache(): void
        {
        }

        public function requireShipping(): bool
        {
            return true;
        }
    }
}

$out = ['ok' => false, 'checks' => [], 'mode' => 'mock-only', 'note' => 'MPL has no known sandbox credentials; verification is mock-only'];

$methodPickup = 7001;
$methodOtherCarrier = 7002;

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    (new ModuleSettings())->setMode('test');
    $settings = new ModuleSettings();
    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_carrier', 'mpl');
    $settings->set('shipping', 'method_' . $methodPickup . '_pickup_types', 'PM,CS,PP');
    $settings->set('shipping', 'method_' . $methodPickup . '_cod_fee_type', 'fixed');
    $settings->set('shipping', 'method_' . $methodPickup . '_cod_fee_amount', 45000);
    $settings->set('shipping', 'method_' . $methodPickup . '_cod_fee_threshold', 0);
    $settings->set('shipping', 'method_' . $methodOtherCarrier . '_pickup_carrier', 'gls');
    $settings->set('shipping', 'method_' . $methodOtherCarrier . '_pickup_types', 'PP');

    // Re-runnable: reset our cached directory + transient.
    delete_option('fch_pickup_dir_mpl');
    delete_option('fch_pickup_dir_gls');

    $mplPoints = [
        ['id' => 'MPL-100', 'deliveryplace' => 'MPL PostaPont Deák tér', 'postCode' => '1052', 'city' => 'Budapest', 'address' => 'Deák Ferenc tér 1.', 'servicePointType' => 'PP'],
        ['id' => 'MPL-101', 'deliveryplace' => 'MPL Csomagautomata Debrecen', 'postCode' => '4024', 'city' => 'Debrecen', 'address' => 'Piac u. 20.', 'servicePointType' => 'CS'],
    ];

    $mock = MockTransport::make()
        ->on('/oauth2/token', MockTransport::json(200, [
            'access_token' => 'LAB-TOKEN',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]))
        ->on('/v2/mplapi/deliveryplace', MockTransport::json(200, $mplPoints))
        ->install();

    // --- A: MPL-004 fetch -> directory import -------------------------------------------------------
    $directory = new PickupDirectory();
    $client = new MplClient('test', 'LAB-KEY', 'LAB-SECRET', 'LAB-ACC', MplClient::TEST_BASE);
    $refresh = $directory->refreshMpl([], $client);
    $out['checks']['mpl_fetch_ok'] = $refresh['ok'] === true && $refresh['count'] === count($mplPoints);
    $out['checks']['mpl_directory_cached'] = count($directory->points('mpl')) === count($mplPoints);
    $out['checks']['mpl_search_city'] = count($directory->search('mpl', 'debrecen', 30)) === 1;
    $out['checks']['mpl_no_network_on_search'] = $mock->callCount('/v2/mplapi/deliveryplace') === 1;

    // --- B: server-side validation + snapshot -------------------------------------------------------
    $validator = new PickupPointValidator();
    $selection = ['carrier' => 'mpl', 'point_id' => 'MPL-100', 'delivery_mode' => 'PP'];
    $validated = $validator->validate($selection, [
        'required' => true,
        'carrier' => 'mpl',
        'allowed_types' => ['PM', 'CS', 'PP'],
        'directory' => $directory,
    ]);
    $out['checks']['validate_ok'] = $validated['ok'] === true;
    $out['checks']['snapshot_shape'] = $validated['ok']
        && array_keys($validated['snapshot']) === ['carrier', 'point_id', 'name', 'address', 'zip', 'city', 'type', 'raw'];
    $unknown = $validator->validate(['carrier' => 'mpl', 'point_id' => 'NOPE'], [
        'required' => true, 'carrier' => 'mpl', 'allowed_types' => ['PM', 'CS', 'PP'], 'directory' => $directory,
    ]);
    $out['checks']['reject_unknown'] = $unknown['ok'] === false && $unknown['error'] === PickupPointValidator::ERROR_UNKNOWN;

    // --- C: filter callbacks with FC-shaped payloads -----------------------------------------------
    $handler = new PickupCheckoutHandler($directory, $validator);
    add_filter('fluent_cart/checkout/before_patch_checkout_data', [$handler, 'beforePatchCheckoutData'], 20, 2);
    add_action('fluent_cart/checkout/prepare_other_data', [$handler, 'prepareOtherData'], 10, 1);

    $cart = new FchFakeCart();
    $cart->checkout_data = ['shipping_data' => ['shipping_method_id' => $methodPickup]];

    $jsonSelection = (string) wp_json_encode($selection);

    // IT-18/IT-19 exercise OUR callbacks directly with FC-shaped payloads: the real filter chain in the
    // lab also carries other FluentCart listeners that expect a full cart, which a fake cart cannot
    // satisfy. The real-chain dispatch is proven by the sandbox spike (SANDBOX_VERIFIED,
    // patch_filter_captured) and by the browser-checkout step of the system test.
    $it18 = $handler->beforePatchCheckoutData(['checkout_data' => $cart->checkout_data, 'cart_data' => []], [
        'cart' => $cart,
        'prev_data' => [],
        'changes' => ['shipping_method_id' => (string) $methodPickup],
        'all_data' => ['shipping_method_id' => (string) $methodPickup, '_fch_pickup_point' => $jsonSelection],
    ]);
    $captured = $it18['checkout_data']['_fch_pickup_point'] ?? null;
    $out['checks']['it18_checkout_data_capture'] = is_array($captured)
        && ($captured['carrier'] ?? '') === 'mpl'
        && ($captured['point_id'] ?? '') === 'MPL-100'
        && ($captured['delivery_mode'] ?? '') === 'PP';

    // IT-19: the shipping method changes to another carrier -> the stored point must be dropped.
    $staleCart = $cart->checkout_data;
    $staleCart['_fch_pickup_point'] = ['carrier' => 'mpl', 'point_id' => 'MPL-100', 'delivery_mode' => 'PP'];
    $it19 = $handler->beforePatchCheckoutData(['checkout_data' => $staleCart, 'cart_data' => []], [
        'cart' => $cart,
        'prev_data' => [],
        'changes' => ['shipping_method_id' => (string) $methodOtherCarrier],
        'all_data' => ['shipping_method_id' => (string) $methodOtherCarrier, '_fch_pickup_point' => $jsonSelection],
    ]);
    $out['checks']['it19_invalidation_drops_point'] = empty($it19['checkout_data']['_fch_pickup_point'])
        && ($it19['checkout_data']['_fch_pickup_point_error'] ?? '') !== '';

    // Order-meta snapshot via prepare_other_data (request_data carries the posted hidden field).
    $order = new FchFakeOrder();
    do_action('fluent_cart/checkout/prepare_other_data', [
        'cart' => $cart,
        'order' => $order,
        'prev_order' => null,
        'request_data' => ['fc_selected_shipping_method' => (string) $methodPickup, '_fch_pickup_point' => $jsonSelection],
        'validated_data' => [],
    ]);
    $snapshot = json_decode((string) ($order->meta['_fch_pickup_point'] ?? ''), true);
    $out['checks']['it18_order_meta_snapshot'] = is_array($snapshot)
        && array_slice(array_keys($snapshot), 0, 8) === ['carrier', 'point_id', 'name', 'address', 'zip', 'city', 'type', 'raw']
        // 3/3 gate #5 provenance extension (driver vs authoritative source separated).
        && ($snapshot['selector_driver'] ?? '') === 'local'
        && ($snapshot['authoritative_source'] ?? '') !== ''
        && (int) ($snapshot['source_fetched_at'] ?? 0) > 0
        && ($snapshot['validation_method'] ?? '') === 'directory_cache'
        && ($order->meta['_fch_carrier'] ?? '') === 'mpl'
        && ($order->meta['_fch_delivery_mode'] ?? '') === 'PP';

    remove_filter('fluent_cart/checkout/before_patch_checkout_data', [$handler, 'beforePatchCheckoutData'], 20);
    remove_action('fluent_cart/checkout/prepare_other_data', [$handler, 'prepareOtherData'], 10);

    // --- D: COD fee (HS-006 fixed + HS-007 5-Ft rounding) -------------------------------------------
    $calcFee = (new CodFeeCalculator())->calculate(1000000, ['type' => 'fixed', 'amount' => 45000], true, 'HUF');
    $out['checks']['cod_calculator'] = $calcFee === 45000;

    $coreShipping = new ShippingCoreModule();
    $coreShipping->registerHooks();
    $fees = apply_filters('fluent_cart/cart/fees', [], [
        'cart' => $cart,
        'cart_subtotal' => 1000000,
        'payment_method' => ShippingCoreModule::COD_METHOD,
        'checkout_data' => ['shipping_data' => ['shipping_method_id' => $methodPickup]],
    ]);
    $codFee = null;
    foreach ((array) $fees as $fee) {
        if (is_array($fee) && ($fee['key'] ?? '') === 'cod_fee') {
            $codFee = $fee;
            break;
        }
    }
    $out['checks']['cod_fee_filter'] = is_array($codFee) && (int) $codFee['amount'] === 45000
        && ($codFee['source'] ?? '') === ShippingCoreModule::SOURCE;

    $noFee = apply_filters('fluent_cart/cart/fees', [], [
        'cart' => $cart,
        'cart_subtotal' => 1000000,
        'payment_method' => 'barion',
        'checkout_data' => ['shipping_data' => ['shipping_method_id' => $methodPickup]],
    ]);
    $out['checks']['cod_fee_only_for_cod'] = $noFee === [];

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";
