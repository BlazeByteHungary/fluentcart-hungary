<?php
/**
 * T35 — invoicing routing across ALL FIVE carrier label paths, driven by real events.
 *
 * Precondition (run.sh): every provider module is enabled in `fch_modules` BEFORE this process boots,
 * so the hooks under test are registered by the real plugin boot (the W2 DPD lesson: a directly called
 * job proves nothing about boot/trigger registration). This flow never instantiates modules manually.
 *
 * Per carrier (mpl, gls, dpd, packeta, foxpost) x invoicer (billingo, szamlazz), on a real FluentCart
 * COD order (`offline_payment`, HUF, 4 240 Ft):
 *   1. fire the genuine FC COD trigger `fluent_cart/order_status_changed` (type=order, processing),
 *   2. assert the carrier's label job row was enqueued by the module,
 *   3. run the queue (real JobRunner) -> carrier create (mocked transport) -> label store ->
 *      `fch/shipping/label_started` -> the selected invoicer gets EXACTLY ONE issuance intent,
 *   4. assert: one confirmed shipment, one confirmed invoice doc of the selected provider, the other
 *      provider received ZERO creates, the order stayed unpaid,
 *   5. repeat the trigger and a later payment event -> still one label, one invoice, zero extra creates.
 * Plus: the payment-policy default (COD invoiced only after payment) is preserved, and switching the
 * shared COD policy to before_dispatch then issuing from the real `fch/shipping/label_started` event
 * produces the document (positive control).
 *
 * All provider hosts are mocked with a catch-all timeout rule — no real network is possible.
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'cases' => [], 'errors' => []];
if (!defined('FCH_CREDENTIALS_KEY')) {
    define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
}
global $wpdb;

// ---------------------------------------------------------------- boot precondition
$bootIds = ['shipping/mpl', 'shipping/gls', 'shipping/dpd', 'shipping/packeta', 'shipping/foxpost', 'billing/billingo', 'billing/szamlazz'];
$bootStates = [];
foreach ($bootIds as $id) {
    $bootStates[$id] = ModuleRegistry::instance()->state($id);
}
$out['boot_states'] = $bootStates;

// The precondition is not merely "state active": what matters is that each carrier module BOOTED from
// the real plugin load and registered its label job. A `degraded` module (missing credential / prod-only
// gating, honestly reported by its probe) has still registered its hooks — `inactive`/`blocked` has not.
$liveHandlers = JobRunner::instance()->handlers();
$bootedWithHandlers = !in_array(false, array_map(static fn($s): bool => in_array($s, ['active', 'degraded'], true), $bootStates), true);
foreach ($GLOBALS['t35_expected_hooks'] as $hook) {
    if (!array_key_exists($hook, $liveHandlers)) {
        $bootedWithHandlers = false;
    }
}
$out['checks']['modules_booted_with_handlers'] = $bootedWithHandlers;

// ---------------------------------------------------------------- cleanup + baseline config
$orderIds = range(995000, 995040);
foreach ($orderIds as $oid) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s", '%' . $oid . '%'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d", $oid));
}
$wpdb->query("DELETE FROM {$wpdb->prefix}fch_jobs WHERE hook IN ('fch_issue_invoice') AND state = 'PENDING'");

update_option('fch_queue_driver', 'db', false);

$settings = new ModuleSettings();
$settings->setMode('test');
$store = new CredentialStore();

$settings->set('billingo', 'block_id', 1);
$settings->set('billingo', 'partner_mode', 'create');
$settings->set('billingo', 'pdf_download', true);
$settings->set('billingo', 'payment_deadline_days', 8);
// Gross basis: a tax-free lab order must invoice exactly the order total (4240 Ft), so amount drift
// would be a real mapper/Billingo failure rather than a net/gross interpretation difference.
$settings->set('billingo', 'amount_basis', 'gross');
$store->set('billingo', 'test', 'default', 'api_key', 'test-billingo-key-w3');

$settings->set('szamlazz', 'account_id', 'acc-szamlazz-lab');
$settings->set('szamlazz', 'amount_basis', 'auto');
$settings->set('szamlazz', 'pdf_download', true);
$store->set('szamlazz', 'test', 'acc-szamlazz-lab', 'agent_key', 'LAB-AGENT-KEY');

$carrierConfig = [
    'mpl' => ['label_hook' => 'fch_create_label', 'traits' => []],
    'gls' => ['label_hook' => 'fch_create_label_gls', 'traits' => []],
    'dpd' => ['label_hook' => 'fch_create_label_dpd', 'traits' => []],
    'packeta' => ['label_hook' => 'fch_create_label_packeta', 'traits' => []],
    'foxpost' => ['label_hook' => 'fch_create_label_foxpost', 'traits' => ['delivery_mode' => 'HD']],
];

if (!in_array(false, $out['checks'], true)) {
    $settings->set('mpl', 'account_id', 'acc-mpl-lab');
    $settings->set('mpl', 'label_format', 'PDF');
    $settings->set('mpl', 'default_weight_g', 1000);
    $store->set('mpl', 'test', 'acc-mpl-lab', 'api_key', 'LAB-MPL-KEY');
    $store->set('mpl', 'test', 'acc-mpl-lab', 'api_secret', 'LAB-MPL-SECRET');

    $settings->set('gls', 'account_id', 'acc-gls-lab');
    $settings->set('gls', 'webshop_engine', 'FluentCart');
    $settings->set('gls', 'default_weight_g', 2000);
    $settings->set('gls', 'type_of_printer', 'A4_2x2');
    $store->set('gls', 'test', 'acc-gls-lab', 'username', 'LAB-GLS-USER');
    $store->set('gls', 'test', 'acc-gls-lab', 'password', 'LAB-GLS-PASSWORD');

    $settings->set('dpd', 'account_id', 'acc-dpd-lab');
    $settings->set('dpd', 'bu_code', '123');
    $settings->set('dpd', 'customer_id', 'CUST-995001');
    $settings->set('dpd', 'main_service_code', '101');
    $settings->set('dpd', 'sender_address_id', 'ADDR-LAB-1');
    $settings->set('dpd', 'label_size', 'A4');
    $settings->set('dpd', 'print_format', 'pdf');
    $settings->set('dpd', 'default_weight_g', 1000);
    $store->set('dpd', 'test', 'acc-dpd-lab', 'token', 'LAB-DPD-JWT-SYNTHETIC');

    $settings->set('packeta', 'account_id', 'acc-packeta-lab');
    $settings->set('packeta', 'eshop', 'FCHLAB');
    $settings->set('packeta', 'hd_address_id', '4159');
    $settings->set('packeta', 'label_format', 'A6 on A6');
    $settings->set('packeta', 'default_weight_g', 1000);
    $store->set('packeta', 'test', 'acc-packeta-lab', 'api_password', str_repeat('p', 32));
    $store->set('packeta', 'test', 'acc-packeta-lab', 'api_key', str_repeat('k', 16));

    $settings->set('foxpost', 'account_id', 'acc-foxpost-lab');
    $settings->set('foxpost', 'delivery_mode', 'HD');
    $settings->set('foxpost', 'default_size', 'M');
    $settings->set('foxpost', 'unique_barcode_prefix', 'FCHU');
    $store->set('foxpost', 'test', 'acc-foxpost-lab', 'username', 'LAB-FOX-USER');
    $store->set('foxpost', 'test', 'acc-foxpost-lab', 'password', 'LAB-FOX-PASS');
    $store->set('foxpost', 'test', 'acc-foxpost-lab', 'api_key', 'LAB-FOX-KEY');
}

// ---------------------------------------------------------------- counters
$GLOBALS['t35_counts'] = ['billingo' => 0, 'szamlazz' => 0, 'carrier' => ['mpl' => 0, 'gls' => 0, 'dpd' => 0, 'packeta' => 0, 'foxpost' => 0]];
$GLOBALS['t35_expected_hooks'] = ['fch_create_label', 'fch_create_label_gls', 'fch_create_label_dpd', 'fch_create_label_packeta', 'fch_create_label_foxpost', 'fch_issue_invoice'];

// ---------------------------------------------------------------- provider + carrier mocks
$jsonResp = static function (array $body, int $http = 201): array {
    return MockTransport::response($http, (string) wp_json_encode($body), ['Content-Type' => 'application/json']);
};
$pdfResp = static fn(): array => MockTransport::response(200, "%PDF-1.4\n% t35 mock pdf\n", ['Content-Type' => 'application/pdf']);

$billingoDocs = [];
$billingoRouter = static function ($url, $args) use ($jsonResp, $pdfResp, &$billingoDocs): array {
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    $body = is_array($args['body'] ?? null) ? $args['body'] : (json_decode((string) ($args['body'] ?? ''), true) ?: []);

    if ($path === '/v3/utils/time') {
        return $jsonResp(['time' => gmdate('c')], 200);
    }
    if ($method === 'POST' && $path === '/v3/partners') {
        return $jsonResp(['id' => 900 + count($billingoDocs), 'name' => 'T35 Partner'], 201);
    }
    if ($method === 'POST' && ($path === '/v3/documents' || $path === '/v3/documents/receipt')) {
        $GLOBALS['t35_counts']['billingo']++;
        $net = 0.0;
        $gross = 0.0;
        foreach ((array) ($body['items'] ?? []) as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['unit_price'] ?? 0);
            if ((string) ($item['unit_price_type'] ?? 'net') === 'gross') {
                $g = $price * $qty;
                $n = $g / 1.27;
            } else {
                $n = $price * $qty;
                $g = $n * 1.27;
            }
            $net += $n;
            $gross += $g;
        }
        $id = 8100 + $GLOBALS['t35_counts']['billingo'];
        $doc = [
            'id' => $id,
            'invoice_number' => 'T35-BIL-' . $id,
            'type' => (string) ($body['type'] ?? 'invoice'),
            'vendor_id' => (string) ($body['vendor_id'] ?? ''),
            'currency' => (string) ($body['currency'] ?? 'HUF'),
            'conversion_rate' => 1.0,
            'net_total' => (int) round($net),
            'vat_amount' => (int) round($gross - $net),
            'gross_total' => (int) round($gross),
        ];
        if ($doc['vendor_id'] !== '') {
            $billingoDocs[$doc['vendor_id']] = $doc;
        }
        return $jsonResp($doc, 201);
    }
    if ($method === 'GET' && str_starts_with($path, '/v3/documents/vendor/')) {
        $vendor = rawurldecode(substr($path, strlen('/v3/documents/vendor/')));
        return isset($billingoDocs[$vendor]) ? $jsonResp($billingoDocs[$vendor], 200) : $jsonResp(['error' => 'document_not_found'], 404);
    }
    if ($method === 'GET' && str_ends_with($path, '/download')) {
        return $pdfResp();
    }
    if ($method === 'POST' && str_ends_with($path, '/send')) {
        return $jsonResp(['message' => 'sent_mock'], 200);
    }
    if (preg_match('#^/v3/documents/(\d+)$#', $path, $m)) {
        foreach ($billingoDocs as $doc) {
            if ((int) $doc['id'] === (int) $m[1]) {
                return $jsonResp($doc, 200);
            }
        }
        return $jsonResp(['error' => 'document_not_found'], 404);
    }
    return $jsonResp(['error' => 'mock_missing_endpoint', 'path' => $path], 404);
};

$szSuccess = MockTransport::fixtureRaw('szamlazz/success.xml');
$szQuery = MockTransport::fixtureRaw('szamlazz/query_szamla.xml');
$szRouter = static function ($url, $args) use ($szSuccess, $szQuery): array {
    $body = is_array($args['body'] ?? null) ? (string) wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
    if (str_contains($body, 'action-xmlagentxmlfile')) {
        $GLOBALS['t35_counts']['szamlazz']++;
        return MockTransport::response(200, $szSuccess, ['szlahu_szamlaszam' => 'T35-SZ-000123']);
    }
    if (str_contains($body, 'xmlszamlapdf')) {
        return MockTransport::response(200, "%PDF-1.4\n% t35 szamlazz pdf\n");
    }
    if (str_contains($body, 'xmlszamlaxml')) {
        return MockTransport::response(200, $szQuery);
    }
    return MockTransport::response(200, $szSuccess);
};

$mplToken = MockTransport::fixture('mpl/token.json');
$mplCreate = MockTransport::fixture('mpl/create_success.json');
$glsPrint = MockTransport::fixture('gls/print_labels_success.json');
$dpdCreate = MockTransport::fixture('dpd/create_success.json');
$dpdLabel = MockTransport::fixture('dpd/label_response.json');
$packetaCreate = MockTransport::fixtureRaw('packeta/create_packet_success.xml');
$packetaLabel = MockTransport::fixtureRaw('packeta/label_pdf.xml');
$foxHd = MockTransport::fixture('foxpost/create_success_hd.json');

$mock = MockTransport::make()
    ->on('/oauth2/token', MockTransport::json(200, $mplToken))
    ->on('/v2/mplapi/shipments/label', static function () use ($mplCreate) {
        $GLOBALS['t35_counts']['carrier']['mpl']++;
        return MockTransport::json(200, $mplCreate);
    })
    ->on('/v2/mplapi/shipments', static function () use ($mplCreate) {
        return MockTransport::json(200, $mplCreate);
    })
    ->on('/ParcelService.svc/json/PrintLabels', static function () use ($glsPrint) {
        $GLOBALS['t35_counts']['carrier']['gls']++;
        return MockTransport::json(200, $glsPrint);
    })
    ->on('/ParcelService.svc/json/GetParcelList', static function () {
        return MockTransport::json(200, []);
    })
    ->on('/ParcelService.svc/json/GetParcelStatuses', static function () {
        return MockTransport::json(200, []);
    })
    ->on('/labels', static function () use ($dpdLabel) {
        return MockTransport::json(200, $dpdLabel);
    })
    ->on('/shipments', static function ($url, $args) use ($dpdCreate) {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        if ($method === 'POST') {
            $GLOBALS['t35_counts']['carrier']['dpd']++;
            return MockTransport::json(200, $dpdCreate);
        }
        return MockTransport::json(200, $dpdCreate);
    })
    ->on('/api/rest', static function ($url, $args) use ($packetaCreate, $packetaLabel) {
        $body = is_array($args['body'] ?? null) ? (string) wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
        if (str_contains($body, '<createPacket>')) {
            $GLOBALS['t35_counts']['carrier']['packeta']++;
            return MockTransport::response(200, $packetaCreate, ['Content-Type' => 'text/xml']);
        }
        if (str_contains($body, '<packetLabelPdf>')) {
            return MockTransport::response(200, $packetaLabel, ['Content-Type' => 'text/xml']);
        }
        if (str_contains($body, '<packetInfo>')) {
            return MockTransport::response(200, $packetaCreate, ['Content-Type' => 'text/xml']);
        }
        return MockTransport::response(404, '');
    })
    ->on('/api/parcel', static function ($url, $args) use ($foxHd) {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        if ($method === 'POST') {
            $GLOBALS['t35_counts']['carrier']['foxpost']++;
            return MockTransport::json(200, $foxHd);
        }
        return MockTransport::json(200, []);
    })
    ->on('/api/label/', MockTransport::response(200, "%PDF-1.4\n% t35 fox label\n"))
    ->on('szamlazz.hu/szamla', $szRouter)
    ->on('api.billingo.hu', $billingoRouter)
    // Catch-all LAST: anything not matched above times out locally instead of reaching the network.
    ->on('http', MockTransport::timeout())
    ->install();

// ---------------------------------------------------------------- helpers
$pump = static function (int $orderId): int {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}fch_jobs WHERE state = 'PENDING' AND NOT (job_key LIKE %s)",
        '%' . $orderId . '%'
    ));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}fch_jobs SET available_at = UTC_TIMESTAMP() WHERE state = 'PENDING' AND job_key LIKE %s",
        '%' . $orderId . '%'
    ));
    $total = 0;
    for ($i = 0; $i < 6; $i++) {
        $ran = JobRunner::instance()->runDue(50);
        $total += $ran;
        if ($ran === 0) {
            break;
        }
    }
    return $total;
};

$createOrder = static function (int $orderId, int $totalCents, array $traits): ?object {
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    $addressClass = 'FluentCart\\App\\Models\\OrderAddress';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        // Foxpost requires recipientEmail; OrderAddress::getEmailAttribute() resolves the ORDER
        // CUSTOMER's e-mail, so the fixture links a minimal lab customer (as the W2 flow does).
        $customerClass = 'FluentCart\\App\\Models\\Customer';
        $customerId = 0;
        if (class_exists($customerClass)) {
            $customer = $customerClass::firstOrCreate(
                ['email' => 't35-lab@example.test'],
                ['first_name' => 'T35', 'last_name' => 'Lab', 'status' => 'active']
            );
            $customerId = (int) ($customer->id ?? 0);
        }
        $order = $orderClass::create([
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'offline_payment',
            'type' => 'payment',
            'fulfillment_type' => 'physical',
            'customer_id' => $customerId,
            'currency' => 'HUF',
            'subtotal' => $totalCents,
            'tax_total' => 0,
            'total_amount' => $totalCents,
            'tax_behavior' => 0,
            'mode' => 'test',
        ]);
        global $wpdb;
        $actual = (int) $order->id;
        if ($actual !== $orderId) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_order_items SET order_id = %d WHERE order_id = %d", $orderId, $actual));
            $order = $orderClass::find($orderId);
        }
        if ($order === null) {
            return null;
        }
        $itemClass::create([
            'order_id' => $orderId,
            'title' => 'T35 lab tétel (utánvét)',
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
                'name' => 'T35 Lab Vásárló',
                'address_1' => 'Teszt utca 1.',
                'city' => 'Budapest',
                'state' => '',
                'postcode' => '1051',
                'country' => 'HU',
                'meta' => [
                    'phone' => '+36301234567',
                    'email' => 't35-lab@example.test',
                    'other_data' => ['phone' => '+36301234567', 'email' => 't35-lab@example.test'],
                ],
            ]);
        }
        if (method_exists($order, 'updateMeta')) {
            if (($traits['delivery_mode'] ?? '') !== '') {
                $order->updateMeta('_fch_delivery_mode', (string) $traits['delivery_mode']);
            }
        }
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
};

$fireCodTrigger = static function (int $orderId): void {
    $order = class_exists('FluentCart\\App\\Models\\Order') ? \FluentCart\App\Models\Order::query()->find($orderId) : null;
    if ($order === null) {
        return;
    }
    do_action('fluent_cart/order_status_changed', [
        'order' => $order,
        'old_status' => 'pending',
        'new_status' => 'processing',
        'manageStock' => false,
        'activity' => [],
    ]);
};

$docRows = static function (int $orderId, string $provider): array {
    $rows = [];
    foreach ((new InvoiceDocumentRepository())->listForOrder($orderId, 20) as $row) {
        if ((string) ($row->provider ?? '') === $provider) {
            $rows[] = $row;
        }
    }
    return $rows;
};

$shipRows = static function (int $orderId, string $carrier): array {
    $rows = [];
    foreach ((new ShipmentRepository())->listForOrder($orderId) as $row) {
        if ((string) ($row->carrier ?? '') === $carrier) {
            $rows[] = $row;
        }
    }
    return $rows;
};

// ---------------------------------------------------------------- cases
if ($out['checks']['modules_booted_with_handlers']) {
    $index = 0;
    foreach ($carrierConfig as $carrier => $config) {
        foreach (['billingo', 'szamlazz'] as $invoicer) {
            $index++;
            $orderId = 995000 + $index;
            $case = ['carrier' => $carrier, 'invoicer' => $invoicer, 'order_id' => $orderId];
            try {
                $settings->set('billing', 'selected_invoicer', $invoicer);
                $settings->set('billing', 'cod_invoice_event', 'before_dispatch');

                $order = $createOrder($orderId, 424000, $config['traits']);
                if ($order === null) {
                    throw new \RuntimeException('order fixture failed');
                }

                $countsBefore = $GLOBALS['t35_counts'];
                $fireCodTrigger($orderId);

                $job = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, state FROM {$wpdb->prefix}fch_jobs WHERE hook = %s AND job_key LIKE %s AND state = 'PENDING'",
                    $config['label_hook'],
                    '%' . $orderId . '%'
                ), ARRAY_A);
                $case['label_job_enqueued'] = $job !== null;

                $pump($orderId);

                $shipments = $shipRows($orderId, $carrier);
                $docs = $docRows($orderId, $invoicer);
                $other = $docRows($orderId, $invoicer === 'billingo' ? 'szamlazz' : 'billingo');

                $case['shipments'] = count($shipments);
                $case['shipment_confirmed'] = count($shipments) === 1
                    && (string) ($shipments[0]->state ?? '') === ExternalOperation::EXTERNAL_CONFIRMED
                    && ((string) ($shipments[0]->label_ref ?? '') !== '' || (string) ($shipments[0]->barcode ?? '') !== '');
                $case['carrier_calls'] = $GLOBALS['t35_counts']['carrier'][$carrier] - ($countsBefore['carrier'][$carrier] ?? 0);
                $case['invoice_docs'] = count($docs);
                $case['invoice_confirmed'] = count($docs) === 1
                    && (string) ($docs[0]->state ?? '') === ExternalOperation::EXTERNAL_CONFIRMED
                    && trim((string) ($docs[0]->document_number ?? '')) !== '';
                $case['gross_minor'] = (int) ($docs[0]->gross_minor ?? 0);
                $case['amount_exact'] = $case['gross_minor'] === 4240;
                $case['other_provider_docs'] = count($other);
                $case['selected_creates'] = $GLOBALS['t35_counts'][$invoicer] - ($countsBefore[$invoicer] ?? 0);
                $case['other_creates'] = $GLOBALS['t35_counts'][$invoicer === 'billingo' ? 'szamlazz' : 'billingo'] - ($countsBefore[$invoicer === 'billingo' ? 'szamlazz' : 'billingo'] ?? 0);
                $case['router_exact'] = $case['selected_creates'] === 1 && $case['other_creates'] === 0 && $case['other_provider_docs'] === 0;

                $pay = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId));
                $case['order_unpaid'] = $pay === 'pending';

                // repeat trigger + queue -> no duplicates
                $fireCodTrigger($orderId);
                $pump($orderId);
                $case['repeat_shipments'] = count($shipRows($orderId, $carrier));
                $case['repeat_docs'] = count($docRows($orderId, $invoicer));
                $case['repeat_selected_creates'] = $GLOBALS['t35_counts'][$invoicer] - $countsBefore[$invoicer];
                $case['repeat_no_duplicate'] = $case['repeat_shipments'] === 1
                    && $case['repeat_docs'] === 1
                    && $case['repeat_selected_creates'] === 1;

                // later payment event -> no second invoice
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status = 'paid', status = 'processing' WHERE id = %d", $orderId));
                $paidOrder = \FluentCart\App\Models\Order::query()->find($orderId);
                do_action('fluent_cart/order_paid_done', ['order' => $paidOrder]);
                $pump($orderId);
                $case['paid_docs'] = count($docRows($orderId, $invoicer));
                $case['paid_creates'] = $GLOBALS['t35_counts'][$invoicer] - $countsBefore[$invoicer];
                $case['paid_no_duplicate'] = $case['paid_docs'] === 1 && $case['paid_creates'] === 1;

                $case['ok'] = $case['label_job_enqueued'] && $case['shipment_confirmed'] && $case['invoice_confirmed']
                    && $case['amount_exact'] && $case['router_exact'] && $case['order_unpaid']
                    && $case['repeat_no_duplicate'] && $case['paid_no_duplicate'];
            } catch (\Throwable $e) {
                $case['ok'] = false;
                $case['exception'] = get_class($e) . ': ' . $e->getMessage();
                $out['errors'][] = $carrier . '/' . $invoicer . ': ' . $e->getMessage();
            }
            $out['cases'][] = $case;
        }
    }

    // ---- payment-policy default + positive control (MPL) --------------------------------------------
    $defaultCase = ['carrier' => 'mpl', 'invoicer' => 'billingo', 'order_id' => 995030, 'policy' => 'payment_default'];
    try {
        $settings->set('billing', 'selected_invoicer', 'billingo');
        $settings->set('billing', 'cod_invoice_event', '');
        $settings->set('szamlazz', 'cod_invoice_event', 'payment');

        $order = $createOrder(995030, 424000, []);
        if ($order === null) {
            throw new \RuntimeException('order fixture failed');
        }
        $fireCodTrigger(995030);
        $pump(995030);
        $defaultCase['docs_under_payment_policy'] = count($docRows(995030, 'billingo'));
        $defaultCase['default_skips_unpaid'] = $defaultCase['docs_under_payment_policy'] === 0;

        $settings->set('billing', 'cod_invoice_event', 'before_dispatch');
        do_action('fch/shipping/label_started', 995030);
        $pump(995030);
        $defaultCase['docs_after_switch'] = count($docRows(995030, 'billingo'));
        $defaultCase['switch_issues_one'] = $defaultCase['docs_after_switch'] === 1;
        $defaultCase['ok'] = $defaultCase['default_skips_unpaid'] && $defaultCase['switch_issues_one'];
    } catch (\Throwable $e) {
        $defaultCase['ok'] = false;
        $defaultCase['exception'] = get_class($e) . ': ' . $e->getMessage();
        $out['errors'][] = 'mpl/default: ' . $e->getMessage();
    }
    $out['cases'][] = $defaultCase;

    // cleanup shared selection so sibling suites keep the W1 legacy behaviour
    $settings->set('billing', 'selected_invoicer', '');
    $settings->set('billing', 'cod_invoice_event', '');
} else {
    $out['errors'][] = 'modules were not active from boot — trigger registration would be untested';
}

$allOk = count($out['cases']) >= 11;
foreach ($out['cases'] as $case) {
    if (($case['ok'] ?? false) !== true) {
        $allOk = false;
    }
}
$out['checks']['all_cases_ok'] = $allOk;
$out['ok'] = !in_array(false, $out['checks'], true);
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
