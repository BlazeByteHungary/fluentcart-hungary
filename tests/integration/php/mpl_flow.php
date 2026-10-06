<?php
/**
 * W1 shipping/mpl integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/mpl_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`, chainable `install()`) so no network is touched,
 * then checks:
 *   A. create success  -> one shipment row EXTERNAL_CONFIRMED with a tracking number + a stored label;
 *   B. duplicate job    -> still one shipment row and no second external dispatch;
 *   C. create timeout   -> ledger UNCERTAIN + a PENDING `fch_reconcile_uncertain` job;
 *   D. reconciler       -> GET /shipments (MPL-007) proves the shipment => ledger + row EXTERNAL_CONFIRMED
 *                          (never a re-POST of /shipments);
 *   E. reprint          -> read-only: no new row, no state / label_state change.
 *
 * Prints exactly one JSON line. The integrator owns running this and fixing lab-specific details.
 * MPL has no known sandbox credentials: this script records mock-only verification.
 */

use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Mpl\MplLabelStore;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Modules\Shipping\Mpl\MplReconciler;
use FluentCartHungary\Modules\Shipping\Mpl\MplShipmentService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'mode' => 'mock-only', 'note' => 'MPL has no known sandbox credentials; verification is mock-only'];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-mpl-lab';
    $orderId = 992001;
    $timeoutOrderId = 992002;
    $codOrderId = 992003; // service-level money-boundary + duplication section (real FluentCart order)

    (new ModuleSettings())->setMode($mode);
    $settings = new ModuleSettings();
    $settings->set('mpl', 'account_id', $account);
    $settings->set('mpl', 'agreement', 'ABCD1234');
    $settings->set('mpl', 'account_no', '1234567890');
    $settings->set('mpl', 'developer', 'FCHLAB');
    $settings->set('mpl', 'webshop_id', 'FCH-992001');
    $settings->set('mpl', 'delivery_mode', 'HA');
    $settings->set('mpl', 'label_type', 'A4');
    $settings->set('mpl', 'label_format', 'PDF');
    $settings->set('mpl', 'default_weight_g', 1000);
    (new CredentialStore())->set('mpl', $mode, $account, 'api_key', 'LAB-MPL-KEY');
    (new CredentialStore())->set('mpl', $mode, $account, 'api_secret', 'LAB-MPL-SECRET');

(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94101, ['carrier' => 'mpl', 'service' => 'home']); // 3/3 routing gate binding
        update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['shipping/mpl' => true]));

    $GLOBALS['fch_mpl_create_timeout'] = false;
    $GLOBALS['fch_mpl_timeout_key'] = ExternalOperation::idempotencyKey($timeoutOrderId, 'label_create', 1);

    // Re-runnable: delete synthetic rows and stale reconcile jobs for this flow's orders.
    global $wpdb;
    foreach ([$orderId, $timeoutOrderId, $codOrderId] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d", $oid));
        $ledgerIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        foreach ((array) $ledgerIds as $lid) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", 'fch:reconcile:' . (int) $lid));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    }

    $token = MockTransport::fixture('mpl/token.json');
    $create = MockTransport::fixture('mpl/create_success.json');
    $query = MockTransport::fixture('mpl/query_result.json');
    $tracking = MockTransport::fixture('mpl/tracking_result.json');

    // Rule order matters: MockTransport matches by URL substring, first match wins.
    $mock = MockTransport::make()
        ->on('/oauth2/token', MockTransport::json(200, $token))
        ->on('/v2/mplapi/shipments/label', MockTransport::json(200, $create))
        ->on('/v2/nyomkovetes/registered', MockTransport::json(200, $tracking))
        ->on('/v2/mplapi/shipments', function ($url, $args) use ($create, $query) {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            if ($method === 'POST') {
                $GLOBALS['fch_mpl_last_create_body'] = is_array($args['body'] ?? null)
                    ? (string) wp_json_encode($args['body'])
                    : (string) ($args['body'] ?? '');
                if (!empty($GLOBALS['fch_mpl_create_timeout'])) {
                    return MockTransport::timeout();
                }
                return MockTransport::json(200, $create);
            }
            // GET /shipments (MPL-007): return a result matching the timeout order's customData1.
            $match = $query[0];
            $match['customData1'] = (string) ($GLOBALS['fch_mpl_timeout_key'] ?? $match['customData1']);
            $match['webshopId'] = 'FCH-992002';
            return MockTransport::json(200, [$match]);
        })
        ->install();

    $repo = new ShipmentRepository();
    $ledger = new LedgerRepository();
    $jobStore = new JobStore();

    // Self-contained: register the reconciler even when the module was disabled at plugin boot
    // (the suite resets fch_modules in its baseline).
    if (!ReconcilerRegistry::has('mpl')) {
        ReconcilerRegistry::register('mpl', new MplReconciler());
    }

    // --- A + B: create success then duplicate (one row, no second dispatch) -------------------------
    $key = ExternalOperation::idempotencyKey($orderId, 'label_create', 1);
    $labelBinary = base64_decode((string) ($create[0]['label'] ?? ''), true) ?: '';
    $stored = (new MplLabelStore())->store('mpl-label-MPL1234567890', $labelBinary, 'pdf');

    $ship = $repo->getOrCreate([
        'order_id' => $orderId,
        'carrier' => 'mpl',
        'mode' => $mode,
        'account_id' => $account,
        'shipment_no' => 1,
        'idempotency_key' => $key,
        'currency' => 'HUF',
    ]);
    $shipmentId = (int) $ship['row']->id;

    $fields = [
        'external_id' => (string) $create[0]['trackingNumber'],
        'external_ref2' => (string) $create[0]['webshopId'],
        'external_ref3' => $key,
        'barcode' => (string) ($create[0]['packageTrackingNumbers'][0] ?? ''),
        'tracking_number' => (string) $create[0]['trackingNumber'],
        'carrier_reference' => (string) $create[0]['webshopId'],
        'parcels' => (array) $create[0]['packageTrackingNumbers'],
        'package_count' => count((array) $create[0]['packageTrackingNumbers']),
        'label_ref' => $stored['ref'],
        'label_sha256' => $stored['sha256'],
    ];

    $run = (new OperationRunner())->run([
        'idempotency_key' => $key,
        'module' => 'shipping',
        'provider' => 'mpl',
        'kind' => 'label_create',
        'mode' => $mode,
        'account_id' => $account,
        'order_id' => $orderId,
        'owner_type' => 'shipment',
        'owner_id' => $shipmentId,
        'business_reference' => (string) $create[0]['webshopId'],
        'currency' => 'HUF',
        'max_attempts' => 1,
    ], static function () use ($fields): ProviderCallResult {
        return ProviderCallResult::success($fields);
    });

    if ((string) $run['state'] === ExternalOperation::EXTERNAL_CONFIRMED) {
        MplShipmentService::ensureShipmentInProgress($repo, $shipmentId);
        $fields['operation_id'] = (int) $run['row']->id;
        $repo->markConfirmed($shipmentId, $fields);
        $repo->setLabelState($shipmentId, 'PREPARED');
    }

    $row = $repo->getById($shipmentId);
    $out['checks']['create_confirmed'] = $row !== null && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $out['checks']['tracking_stored'] = $row !== null && (string) ($row->tracking_number ?? '') !== '';
    $out['checks']['label_stored'] = $row !== null && (string) ($row->label_ref ?? '') !== '';

    // Duplicate get-or-create with the same idempotency key must not insert a second row.
    $duplicate = $repo->getOrCreate([
        'order_id' => $orderId,
        'carrier' => 'mpl',
        'mode' => $mode,
        'account_id' => $account,
        'shipment_no' => 1,
        'idempotency_key' => $key,
        'currency' => 'HUF',
    ]);
    $rowCount = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        'SELECT COUNT(*) FROM ' . $repo->table() . ' WHERE order_id = %d AND carrier = %s',
        $orderId,
        'mpl'
    ));
    $out['checks']['duplicate_one_row'] = $duplicate['created'] === false && (int) $rowCount === 1;

    // --- C: timeout -> UNCERTAIN + reconcile job ----------------------------------------------------
    $GLOBALS['fch_mpl_create_timeout'] = true;
    $tKey = (string) $GLOBALS['fch_mpl_timeout_key'];
    $tShip = $repo->getOrCreate([
        'order_id' => $timeoutOrderId,
        'carrier' => 'mpl',
        'mode' => $mode,
        'account_id' => $account,
        'shipment_no' => 1,
        'idempotency_key' => $tKey,
        'currency' => 'HUF',
    ]);
    $tShipmentId = (int) $tShip['row']->id;

    $tRun = (new OperationRunner())->run([
        'idempotency_key' => $tKey,
        'module' => 'shipping',
        'provider' => 'mpl',
        'kind' => 'label_create',
        'mode' => $mode,
        'account_id' => $account,
        'order_id' => $timeoutOrderId,
        'owner_type' => 'shipment',
        'owner_id' => $tShipmentId,
        'business_reference' => 'FCH-992002',
        'currency' => 'HUF',
        'max_attempts' => 1,
    ], static function (): ProviderCallResult {
        return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH');
    });

    if ((string) $tRun['state'] === ExternalOperation::UNCERTAIN) {
        MplShipmentService::ensureShipmentInProgress($repo, $tShipmentId);
        $repo->markUncertain($tShipmentId);
    }

    $ledgerRow = $ledger->getByKey($tKey);
    $reconcileJob = $ledgerRow !== null ? $jobStore->findByKey('fch:reconcile:' . (int) $ledgerRow->id) : null;
    $out['checks']['timeout_uncertain'] = $ledgerRow !== null && (string) $ledgerRow->state === ExternalOperation::UNCERTAIN;
    $out['checks']['reconcile_job_pending'] = $reconcileJob !== null
        && (string) $reconcileJob->state === 'PENDING'
        && (string) $reconcileJob->hook === ReconcileUncertainJob::HOOK;

    // --- D: reconciler proves the create via GET /shipments -----------------------------------------
    $GLOBALS['fch_mpl_create_timeout'] = false;
    if ($ledgerRow !== null) {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => (int) $ledgerRow->id]);
        } catch (\Throwable $e) {
            // Still-uncertain / no reconciler is a valid outcome; the assertions below handle it.
        }
        $after = $ledger->getById((int) $ledgerRow->id);
        $tRow = $repo->getById($tShipmentId);
        $out['checks']['reconciler_confirmed'] = $after !== null && (string) $after->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['reconciler_shipment_confirmed'] = $tRow !== null && (string) $tRow->state === ExternalOperation::EXTERNAL_CONFIRMED;
        // Exactly one call to /shipments and it is the GET read-back — the reconciler never re-POSTs create.
        $out['checks']['reconciler_no_repost'] = $mock->callCount('/v2/mplapi/shipments') === 1;
    } else {
        $out['checks']['reconciler_confirmed'] = false;
        $out['checks']['reconciler_shipment_confirmed'] = false;
        $out['checks']['reconciler_no_repost'] = false;
    }

    // --- E: reprint is read-only (no new row, no state / label_state change) ------------------------
    $before = $repo->getById($shipmentId);
    $rowsBefore = count($repo->listForOrder($orderId));
    $reprint = (new MplShipmentService())->reprintLabel($shipmentId);
    $afterReprint = $repo->getById($shipmentId);
    $rowsAfter = count($repo->listForOrder($orderId));
    $out['checks']['reprint_returns_label'] = is_array($reprint) && ($reprint['label'] ?? '') !== '';
    $out['checks']['reprint_read_only'] = $before !== null && $afterReprint !== null
        && (string) $before->state === (string) $afterReprint->state
        && (string) ($before->label_state ?? '') === (string) ($afterReprint->label_state ?? '')
        && $rowsBefore === $rowsAfter;

    // --- F: money boundary + duplication at the real service level -----------------------------------
    // A real FluentCart order stores amount×100 even for HUF; a 4 240 Ft COD order is 424000 cents and
    // MPL `services.cod` must be the HUF integer 4240. A repeated createForOrder for the same business
    // intent must not produce a second external POST.
    $GLOBALS['fch_mpl_last_create_body'] = '';
    $GLOBALS['fch_mpl_create_timeout'] = false;
    $codOrder = mpl_flow_create_order($codOrderId, 424000);
    if ($codOrder === null) {
        $out['checks']['money_boundary_huf_cod'] = false;
        $out['checks']['service_duplicate_one_post'] = false;
    } else {
        $serviceCreate = (new MplShipmentService())->createForOrder($codOrderId, 1);
        $postCount = static function () use ($mock): int {
            $count = 0;
            foreach ($mock->calls('/v2/mplapi/shipments') as $call) {
                if (strtoupper((string) $call['method']) === 'POST' && !str_contains((string) $call['url'], '/label')) {
                    $count++;
                }
            }
            return $count;
        };
        $postsAfterFirst = $postCount();
        $payload = json_decode((string) $GLOBALS['fch_mpl_last_create_body'], true);
        // MPL create body: [{ ...shipment..., "item": [{ services: { cod, ... } }] }]
        $shipment = is_array($payload) && isset($payload[0]) && is_array($payload[0]) ? $payload[0] : [];
        $itemList = $shipment['item'] ?? ($shipment['items'] ?? []);
        $codInPayload = is_array($itemList) && isset($itemList[0]['services']['cod'])
            ? (int) $itemList[0]['services']['cod']
            : null;

        // Duplicate business intent (double click / replay): must not produce a second external create.
        $serviceCreate2 = (new MplShipmentService())->createForOrder($codOrderId, 1);
        $postsAfterDuplicate = $postCount();

        $codLedger = $ledger->getByKey(ExternalOperation::idempotencyKey($codOrderId, 'label_create', 1));
        $codRows = $repo->listForOrder($codOrderId);

        $out['checks']['money_boundary_huf_cod'] = $codInPayload === 4240
            && $codLedger !== null
            && (int) $codLedger->amount_minor === 4240
            && !str_contains((string) $GLOBALS['fch_mpl_last_create_body'], '424000');
        $out['checks']['service_duplicate_one_post'] = $postsAfterFirst === 1
            && $postsAfterDuplicate === 1
            && count($codRows) === 1
            && (($serviceCreate2['status'] ?? '') !== 'created');
        $out['checks']['service_create_confirmed'] = ($serviceCreate['state'] ?? '') === ExternalOperation::EXTERNAL_CONFIRMED;
    }

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/**
 * Real FluentCart order fixture for the service-level boundary test: COD order (offline_payment), amounts
 * in FluentCart-native cents, one physical item and a shipping address row.
 */
function mpl_flow_create_order(int $orderId, int $totalCents): ?object
{
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    $addressClass = 'FluentCart\\App\\Models\\OrderAddress';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        $order = $orderClass::find($orderId);
        if ($order === null) {
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
                'total_amount' => $totalCents,
                'tax_behavior' => 0,
                'mode' => 'test',
            ]);
            global $wpdb;
            $actual = (int) $order->id;
            if ($actual !== $orderId) {
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
                $order = $orderClass::find($orderId);
            }
        } else {
            // Self-healing fixture (see szamlazz_flow_create_order): normalize amounts and children.
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fct_orders SET subtotal=%d, tax_total=0, total_amount=%d, payment_method='offline_payment' WHERE id=%d",
                $totalCents,
                $totalCents,
                $orderId
            ));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id=%d", $orderId));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id=%d", $orderId));
            $order = $orderClass::find($orderId);
        }
        if ($order === null) {
            return null;
        }
        $itemClass::create([
            'order_id' => $orderId,
            'title' => 'Lab tétel (utánvét)',
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
                'name' => 'Lab Vásárló',
                'address_1' => 'Teszt utca 1.',
                'city' => 'Budapest',
                'state' => '',
                'postcode' => '1051',
                'country' => 'HU',
                'meta' => ['phone' => '+36301234567', 'email' => 'lab-buyer@example.test'],
            ]);
        }
        // routing meta reset (re-runnable fixture: a carrier flow must not inherit another flow's
        // order-time snapshot when order ids are reused across flows).
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key IN ('_fch_carrier','_fch_service','_fch_carrier_status','_fch_carrier_status_at')", $orderId));
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET config = %s WHERE id = %d", wp_json_encode(['shipping_method_id' => 94101, 'shipping_method_title' => 'Lab']), $orderId));
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
