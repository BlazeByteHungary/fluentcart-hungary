<?php
/**
 * T42 — carrier routing gate, release gate #1 (checkout 3/3 goal).
 *
 * Proves with REAL FluentCart orders and MOCK provider transports (no network, no real shipment):
 *  - an explicit order-time binding routes to exactly one carrier: the bound carrier makes exactly ONE
 *    create call, every other carrier is skipped with a routing reason (0 calls);
 *  - all five carriers create for their own bound order (per-carrier create counts shown);
 *  - an unbound legacy order is NOT guessed: all carriers skip, the order gets the actionable
 *    'unresolved' state, 0 calls; a later method binding does not silently adopt a carrier;
 *  - the explicit legacy-default policy routes and locks new unbound orders;
 *  - digital/no-shipping orders are 'not_applicable' (silent, no admin state);
 *  - repeated call/job retry and an admin method re-binding after the snapshot never duplicate or
 *    re-route (immutable order-time decision).
 *
 * Mock only: the provider create endpoints are intercepted by MockTransport; nothing external happens.
 */

use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings;
use FluentCartHungary\Modules\Shipping\Dpd\CreateLabelJob as DpdCreateLabelJob;
use FluentCartHungary\Modules\Shipping\Dpd\DpdShipmentService;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostParcelService;
use FluentCartHungary\Modules\Shipping\Gls\GlsShipmentService;
use FluentCartHungary\Modules\Shipping\Mpl\CreateLabelJob as MplCreateLabelJob;
use FluentCartHungary\Modules\Shipping\Mpl\MplShipmentService;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaPacketService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => true, 'checks' => [], 'calls' => [], 'statuses' => []];

$mode = 'test';
$account = 'default';
(new ModuleSettings())->setMode($mode);
$settings = new ModuleSettings();
$settings->set('mpl', 'delivery_mode', 'HA');
$settings->set('mpl', 'label_format', 'PDF');
$settings->set('mpl', 'default_weight_g', 1000);
$settings->set('gls', 'account_id', $account);
$settings->set('gls', 'webshop_engine', 'FluentCart');
$settings->set('gls', 'default_weight_g', 2000);
$settings->set('gls', 'type_of_printer', 'A4_2x2');
$settings->set('gls', 'client_reference', '');
$settings->set('dpd', 'account_id', $account);
$settings->set('dpd', 'bu_code', '123');
$settings->set('dpd', 'customer_id', 'CUST-933001');
$settings->set('dpd', 'main_service_code', '101');
$settings->set('dpd', 'sender_address_id', 'ADDR-LAB-1');
$settings->set('packeta', 'account_id', $account);
$settings->set('packeta', 'eshop', 'FCHLAB');
$settings->set('packeta', 'number_prefix', '');
$settings->set('packeta', 'hd_address_id', '4159');
$settings->set('packeta', 'label_format', 'A6 on A6');
$settings->set('foxpost', 'account_id', $account);
$settings->set('foxpost', 'delivery_mode', 'HD');
$settings->set('foxpost', 'default_size', 'M');
$settings->set('foxpost', 'unique_barcode_prefix', 'FCHU');
$settings->set('shipping', CarrierBinding::SETTING_LEGACY_DEFAULT, '');

$creds = new CredentialStore();
$creds->set('mpl', $mode, $account, 'api_key', 'LAB-MPL-KEY');
$creds->set('mpl', $mode, $account, 'api_secret', 'LAB-MPL-SECRET');
$creds->set('gls', $mode, $account, 'username', 'LAB-GLS-USER');
$creds->set('gls', $mode, $account, 'password', 'LAB-GLS-PASSWORD');
$creds->set('dpd', $mode, $account, 'token', 'LAB-DPD-JWT-SYNTHETIC');
$creds->set('packeta', $mode, $account, 'api_password', str_repeat('p', 32));
$creds->set('packeta', $mode, $account, 'api_key', str_repeat('k', 16));
$creds->set('foxpost', $mode, $account, 'username', 'LAB-FOX-USER');
$creds->set('foxpost', $mode, $account, 'password', 'LAB-FOX-PASS');
$creds->set('foxpost', $mode, $account, 'api_key', 'LAB-FOX-KEY');

// Fresh bindings every run (the earlier run's rebinds must not leak into this one).
$shipSettings = new ShippingMethodSettings();
$shipSettings->saveForMethod(93101, ['carrier' => 'mpl', 'service' => 'home']);
$shipSettings->saveForMethod(93102, ['carrier' => 'foxpost', 'service' => 'locker', 'service_types' => 'locker']);
$shipSettings->saveForMethod(93103, ['carrier' => 'gls', 'service' => 'home']);
$shipSettings->saveForMethod(93104, ['carrier' => 'packeta', 'service' => 'home']);
$shipSettings->saveForMethod(93105, ['carrier' => 'dpd', 'service' => 'home']);
$shipSettings->saveForMethod(93106, ['carrier' => '', 'service' => '']);
$shipSettings->saveForMethod(93107, ['carrier' => '', 'service' => '']);

global $wpdb;
$orderIds = [933001, 933002, 933003, 933004, 933005, 933006, 933007, 933008];
foreach ($orderIds as $oid) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key LIKE '_fch%'", $oid));
}

$counts = ['mpl' => 0, 'gls' => 0, 'dpd' => 0, 'packeta' => 0, 'foxpost' => 0];
$mock = MockTransport::make()
    ->on('/oauth2/token', MockTransport::json(200, MockTransport::fixture('mpl/token.json')))
    ->on('/v2/mplapi/shipments/label', function () use (&$counts) {
        $counts['mpl']++;
        return MockTransport::json(200, MockTransport::fixture('mpl/create_success.json'));
    })
    ->on('/v2/mplapi/shipments', function ($url, $args) use (&$counts) {
        if (strtoupper((string) ($args['method'] ?? 'GET')) !== 'POST') {
            return MockTransport::json(200, []);
        }
        $counts['mpl']++;
        return MockTransport::json(200, MockTransport::fixture('mpl/create_success.json'));
    })
    ->on('/ParcelService.svc/json/PrintLabels', function () use (&$counts) {
        $counts['gls']++;
        return MockTransport::json(200, MockTransport::fixture('gls/print_labels_success.json'));
    })
    ->on('/shipments', function ($url, $args) use (&$counts) {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        if ($method !== 'POST') {
            return MockTransport::json(200, []);
        }
        $counts['dpd']++;
        return MockTransport::json(200, MockTransport::fixture('dpd/create_success.json'));
    })
    ->on('/api/rest', function ($url, $args) use (&$counts) {
        $body = is_array($args['body'] ?? null) ? (string) wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
        if (str_contains($body, '<createPacket>')) {
            $counts['packeta']++;
            return MockTransport::response(200, MockTransport::fixtureRaw('packeta/create_packet_success.xml'), ['Content-Type' => 'text/xml']);
        }
        return MockTransport::response(404, '');
    })
    ->on('/api/parcel', function ($url, $args) use (&$counts) {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        if ($method !== 'POST') {
            return MockTransport::json(200, []);
        }
        $counts['foxpost']++;
        return MockTransport::json(200, MockTransport::fixture('foxpost/create_success_apm.json'));
    })
    ->on('/api/label/', MockTransport::response(200, "%PDF-1.4\nFOXPOST-MOCK-LABEL"))
    ->install();

// --- orders -------------------------------------------------------------------------------------
routing_create_order(933001, 93101, 424000, 1190.0);   // mpl/home bound
routing_create_order(933002, 93102, 424000, 990.0, [    // foxpost/locker bound + point snapshot
    '_fch_pickup_point' => [
        'carrier' => 'foxpost', 'type' => 'APM', 'point_id' => 'hu35',
        'name' => 'Foxpost Automata (lab)', 'address' => 'Blaha Lujza tér 1.',
    ],
    '_fch_delivery_mode' => 'APM',
]);
routing_create_order(933003, 93103, 424000, 1490.0);   // gls/home bound
routing_create_order(933004, 93104, 424000, 1690.0);   // packeta/home bound
routing_create_order(933005, 93105, 424000, 1790.0);   // dpd/home bound
routing_create_order(933006, 0, 100000, 0.0);          // digital / no shipping
routing_create_order(933007, 93106, 424000, 1990.0);   // unbound legacy (method without binding)

$services = [
    'mpl' => static fn (int $id): array => (new MplShipmentService())->createForOrder($id),
    'gls' => static fn (int $id): array => (new GlsShipmentService())->createForOrder($id),
    'dpd' => static fn (int $id): array => (new DpdShipmentService())->createForOrder($id),
    'packeta' => static fn (int $id): array => (new PacketaPacketService())->createForOrder($id),
    'foxpost' => static fn (int $id): array => (new FoxpostParcelService())->createForOrder($id),
];
$boundCarrier = ['A' => 'mpl', 'B' => 'foxpost', 'C' => 'gls', 'D' => 'packeta', 'E' => 'dpd'];

$runOrder = static function (string $label, int $orderId) use ($services, &$out): array {
    $statuses = [];
    foreach ($services as $carrier => $run) {
        $result = $run($orderId);
        $statuses[$carrier] = (string) ($result['status'] ?? '?') . ':' . (string) ($result['reason'] ?? '');
    }
    $out['statuses'][$label] = $statuses;
    return $statuses;
};
$isCreated = static fn (string $status): bool => str_starts_with($status, 'created') || str_starts_with($status, 'confirmed') || str_starts_with($status, 'already_created');

// 1) bound orders: exactly the bound carrier creates, every other carrier is a mismatch skip.
foreach (['A' => 933001, 'B' => 933002, 'C' => 933003, 'D' => 933004, 'E' => 933005] as $label => $oid) {
    $statuses = $runOrder($label, $oid);
    $carrier = $boundCarrier[$label];
    $ok = true;
    foreach ($statuses as $name => $status) {
        $ok = $ok && ($name === $carrier ? $isCreated($status) : str_starts_with($status, 'skipped:carrier_mismatch'));
    }
    $out['checks']['bound_' . $label . '_only_' . $carrier . '_creates'] = $ok;
}
$out['calls'] = $counts;
$out['checks']['one_create_per_carrier'] = $counts === ['mpl' => 1, 'gls' => 1, 'dpd' => 1, 'packeta' => 1, 'foxpost' => 1];

// 2) repeated call must not duplicate (service-level idempotency used by job retries).
$second = (new MplShipmentService())->createForOrder(933001);
$out['checks']['repeat_call_no_duplicate'] = (($second['status'] ?? '') === 'already_created') && $counts['mpl'] === 1;

// 3) unbound legacy order: nothing guessed, unresolved state recorded, 0 calls.
$statusesG = $runOrder('G', 933007);
$allSkipped = true;
foreach ($statusesG as $status) {
    $allSkipped = $allSkipped && str_starts_with($status, 'skipped:carrier_unresolved');
}
$unresolvedMeta = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key = %s",
    933007,
    CarrierBinding::META_STATUS
));
$out['checks']['legacy_unbound_unresolved'] = $allSkipped && $unresolvedMeta === CarrierBinding::STATUS_UNRESOLVED;
$out['checks']['legacy_unbound_no_calls'] = $counts === ['mpl' => 1, 'gls' => 1, 'dpd' => 1, 'packeta' => 1, 'foxpost' => 1];

// 4) a later method binding must NOT silently adopt this order.
$shipSettings->saveForMethod(93106, ['carrier' => 'mpl', 'service' => 'home']);
$statusesGRetry = $runOrder('G2', 933007);
$out['checks']['rebinding_does_not_adopt'] = str_starts_with((string) ($statusesGRetry['mpl'] ?? ''), 'skipped:carrier_unresolved')
    && $counts['mpl'] === 1;

// 5) pending-job retry: the job handler re-enters the service and must NOT bypass the gate.
$jobOk = true;
try {
    (new MplCreateLabelJob())->handle(['order_id' => 933007, 'shipment_no' => 1]);
    (new DpdCreateLabelJob())->handle(['order_id' => 933007, 'shipment_no' => 1]);
} catch (\Throwable $e) {
    $jobOk = false;
}
$out['checks']['job_retry_respects_gate'] = $jobOk && $counts['mpl'] === 1 && $counts['dpd'] === 1;

// 6) explicit legacy-default policy: applies to NEW unbound orders and locks the snapshot.
$settings->set('shipping', CarrierBinding::SETTING_LEGACY_DEFAULT, 'mpl');
routing_create_order(933008, 93107, 424000, 1990.0);
$statusesH = $runOrder('H', 933008);
$out['checks']['legacy_default_policy_routes'] = $isCreated((string) ($statusesH['mpl'] ?? ''))
    && str_starts_with((string) ($statusesH['gls'] ?? ''), 'skipped:carrier_mismatch')
    && $counts['mpl'] === 2;
$settings->set('shipping', CarrierBinding::SETTING_LEGACY_DEFAULT, '');

// 7) digital / no shipping: not applicable, silent (no unresolved marker, no calls).
$statusesF = $runOrder('F', 933006);
$out['checks']['digital_not_applicable'] = str_starts_with((string) ($statusesF['mpl'] ?? ''), 'skipped:carrier_not_applicable');
$digitalStatus = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key = %s",
    933006,
    CarrierBinding::META_STATUS
));
$out['checks']['digital_no_admin_state'] = $digitalStatus === '';

// 8) order-created listener: every server-side creation path locks the decision (no label attempt).
$eventClass = 'FluentCart\\App\\Events\\Order\\OrderCreated';
if (class_exists($eventClass)) {
    routing_create_order(933009, 93101, 424000, 1190.0);
    $eventOrder = routing_load_order(933009);
    if ($eventOrder !== null) {
        (new $eventClass($eventOrder, null, $eventOrder->customer ?? null))->dispatch();
    }
    $createdMeta = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key = %s",
        933009,
        CarrierBinding::META_CARRIER
    ));
    $out['checks']['order_created_locks_binding'] = $createdMeta === 'mpl';
} else {
    $out['checks']['order_created_locks_binding'] = false;
}

// 9) snapshot immutability: admin re-binds method 93101 (mpl -> gls) after order A was locked.
$shipSettings->saveForMethod(93101, ['carrier' => 'gls', 'service' => 'home']);
$resolvedA = CarrierBinding::resolveForOrder(routing_load_order(933001), false);
$out['checks']['snapshot_immutable_after_rebind'] = ($resolvedA['carrier'] ?? '') === 'mpl'
    && ($resolvedA['source'] ?? '') === 'order_snapshot';

$out['calls'] = $counts;
$mock->uninstall();

foreach ($out['checks'] as $name => $ok) {
    if (!$ok) {
        $out['ok'] = false;
        $out['failed'][] = $name;
    }
}
$out['mode'] = 'mock-only';
$out['note'] = 'routing gate: per-carrier mock create counts, unresolved legacy state, policy, retry, immutability';

echo wp_json_encode($out) . "\n";

/** @param array<string,mixed> $meta */
function routing_create_order(int $orderId, int $shippingMethodId, int $totalCents, float $shippingTotal, array $meta = []): ?object
{
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    $addressClass = 'FluentCart\\App\\Models\\OrderAddress';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $orderId));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d", $orderId));
    if ($orderClass::find($orderId) !== null) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId));
    }
    $order = $orderClass::create([
        'status' => 'processing',
        'payment_status' => 'pending',
        'payment_method' => 'offline_payment',
        'type' => 'payment',
        'fulfillment_type' => 'physical',
        'customer_id' => 0,
        'currency' => 'HUF',
        'subtotal' => $totalCents,
        'tax_total' => 0,
        'shipping_total' => (int) round($shippingTotal * 100),
        'total_amount' => $totalCents + (int) round($shippingTotal * 100),
        'total_paid' => 0,
        'tax_behavior' => 0,
        'mode' => 'test',
        // FluentCart 1.7.0 keeps the chosen shipping method in the config JSON (no own column).
        'config' => [
            'shipping_method_id' => $shippingMethodId,
            'shipping_method_title' => $shippingMethodId > 0 ? 'Lab szállítás #' . $shippingMethodId : '',
        ],
    ]);
    $actual = (int) $order->id;
    if ($actual !== $orderId) {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
        $order = $orderClass::find($orderId);
    }
    if ($order === null) {
        return null;
    }
    $itemClass::create([
        'order_id' => $orderId,
        'title' => 'Routing lab tétel',
        'quantity' => 1,
        'unit_price' => $totalCents,
        'subtotal' => $totalCents,
        'tax_amount' => 0,
        'discount_total' => 0,
        'line_total' => $totalCents,
        'rate' => 0,
        'payment_type' => 'onetime',
        'fulfillment_type' => 'physical',
    ]);
    if (class_exists($addressClass)) {
        $addressClass::create([
            'order_id' => $orderId,
            'type' => 'shipping',
            'name' => 'Routing Lab Vásárló',
            'address_1' => 'Teszt utca 1.',
            'city' => 'Budapest',
            'state' => '',
            'postcode' => '1051',
            'country' => 'HU',
            'meta' => [
                'phone' => '+36301234567',
                'email' => 'routing-lab@example.test',
                'other_data' => ['phone' => '+36301234567', 'email' => 'routing-lab@example.test'],
            ],
        ]);
    }
    // A real checkout order always has a customer; the recipient e-mail is read from it.
    $customerClass = 'FluentCart\\App\\Models\\Customer';
    if (class_exists($customerClass)) {
        $email = 'routing-lab@example.test';
        $customer = $customerClass::query()->where('email', $email)->first();
        if ($customer === null) {
            $customer = $customerClass::create([
                'email' => $email,
                'first_name' => 'Routing',
                'last_name' => 'Lab',
                'status' => 'active',
            ]);
        }
        if (is_object($customer) && (int) $order->customer_id !== (int) $customer->id) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET customer_id = %d WHERE id = %d", (int) $customer->id, $orderId));
            $order = $orderClass::find($orderId);
        }
    }
    foreach ($meta as $key => $value) {
        if (method_exists($order, 'updateMeta')) {
            $order->updateMeta((string) $key, $value);
        }
    }
    return $order;
}

function routing_load_order(int $orderId): ?object
{
    $class = 'FluentCart\\App\\Models\\Order';
    if (!class_exists($class)) {
        return null;
    }
    $order = $class::find($orderId);
    return is_object($order) ? $order : null;
}
