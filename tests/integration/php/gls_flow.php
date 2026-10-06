<?php
/**
 * W2 shipping/gls integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/gls_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`, chainable `install()`) so no network is touched,
 * then checks:
 *   A. create success  -> one shipment row EXTERNAL_CONFIRMED with a ParcelNumber + a stored label; the
 *                         label reads back locally (reprint = stored label_ref only, no external call);
 *   B. duplicate create -> still one shipment row and no second PrintLabels dispatch;
 *   C. HUF money boundary: FC stores amount×100, the MyGLS `CODAmount` must be the domain forint (2500);
 *   D. create timeout   -> ledger UNCERTAIN + a PENDING `fch_reconcile_uncertain` job, one PrintLabels POST;
 *   E. reconciler       -> GetParcelList read-back proves the create => no re-POST of PrintLabels;
 *   F. tracking sync    -> GetParcelStatuses maps to shipped/delivered and never regresses (monotonic).
 *
 * Prints exactly one JSON line. The integrator owns running this and fixing lab-specific details.
 * GLS has no verified HU sandbox credentials beyond vendor fixtures: this script records mock-only verif.
 */

use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Gls\GlsReconciler;
use FluentCartHungary\Modules\Shipping\Gls\GlsShipmentService;
use FluentCartHungary\Modules\Shipping\Gls\GlsTracking;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'mode' => 'mock-only', 'note' => 'GLS test host + vendor fixtures; verification is mock-only'];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-gls-lab';
    $orderId = 991001;
    $timeoutOrderId = 991002;

    (new ModuleSettings())->setMode($mode);
    $settings = new ModuleSettings();
    $settings->set('gls', 'account_id', $account);
    $settings->set('gls', 'webshop_engine', 'FluentCart');
    $settings->set('gls', 'client_reference', ''); // default: FluentCart order uuid (GLS-005)
    $settings->set('gls', 'default_weight_g', 2000);
    $settings->set('gls', 'type_of_printer', 'A4_2x2');
    $settings->set('gls', 'cod_min_minor', 0);
    (new CredentialStore())->set('gls', $mode, $account, 'username', 'LAB-GLS-USER');
    (new CredentialStore())->set('gls', $mode, $account, 'password', 'LAB-GLS-PASSWORD');

(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94103, ['carrier' => 'gls', 'service' => 'home']); // 3/3 routing gate binding
        update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['shipping/gls' => true]));

    $GLOBALS['fch_gls_timeout'] = false;
    $GLOBALS['fch_gls_timeout_ref'] = '';
    $GLOBALS['fch_gls_last_print_body'] = '';
    $GLOBALS['fch_gls_status_phase'] = 'transit';
    $GLOBALS['fch_gls_print'] = MockTransport::fixture('gls/print_labels_success.json');
    $GLOBALS['fch_gls_parcel_list'] = MockTransport::fixture('gls/parcel_list.json');
    $GLOBALS['fch_gls_transit'] = MockTransport::fixture('gls/parcel_statuses_transit.json');
    $GLOBALS['fch_gls_delivered'] = MockTransport::fixture('gls/parcel_statuses_delivered.json');

    // Re-runnable: delete synthetic rows and stale reconcile jobs for this flow's orders.
    global $wpdb;
    foreach ([$orderId, $timeoutOrderId] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d", $oid));
        $ledgerIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        foreach ((array) $ledgerIds as $lid) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", 'fch:reconcile:' . (int) $lid));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    }

    // Rule order matters only for readability: MockTransport matches by URL substring, first match wins.
    $mock = MockTransport::make()
        ->on('/ParcelService.svc/json/GetParcelStatuses', function () {
            return MockTransport::json(200, $GLOBALS['fch_gls_status_phase'] === 'delivered'
                ? $GLOBALS['fch_gls_delivered']
                : $GLOBALS['fch_gls_transit']);
        })
        ->on('/ParcelService.svc/json/GetParcelList', function () {
            $row = $GLOBALS['fch_gls_parcel_list'][0];
            if (($GLOBALS['fch_gls_timeout_ref'] ?? '') !== '') {
                $row['ClientReference'] = (string) $GLOBALS['fch_gls_timeout_ref'];
            }
            return MockTransport::json(200, [$row]);
        })
        ->on('/ParcelService.svc/json/PrintLabels', function ($url, $args) {
            $body = is_array($args['body'] ?? null) ? wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
            $GLOBALS['fch_gls_last_print_body'] = $body;
            if (!empty($GLOBALS['fch_gls_timeout'])) {
                return MockTransport::timeout();
            }
            return MockTransport::json(200, $GLOBALS['fch_gls_print']);
        })
        ->install();

    $repo = new ShipmentRepository();
    $ledger = new LedgerRepository();
    $jobStore = new JobStore();

    // Self-contained: register the reconciler even when the module was disabled at plugin boot.
    if (!ReconcilerRegistry::has('gls')) {
        ReconcilerRegistry::register('gls', new GlsReconciler());
    }

    // --- A/B/C: create success, label read-back, duplicate, money boundary --------------------------
    // Real FluentCart COD order: amount×100 for HUF → a 2 500 Ft order is 250000 cents.
    $order = gls_flow_create_order($orderId, 250000);
    $create = (new GlsShipmentService())->createForOrder($orderId, 1);
    $shipmentId = (int) ($create['shipment_id'] ?? 0);
    $row = $shipmentId > 0 ? $repo->getById($shipmentId) : null;

    $out['checks']['create_confirmed'] = $order !== null && ($create['state'] ?? '') === ExternalOperation::EXTERNAL_CONFIRMED;
    $out['checks']['tracking_stored'] = $row !== null && (string) ($row->tracking_number ?? '') !== '';
    $out['checks']['label_stored'] = $row !== null && (string) ($row->label_ref ?? '') !== '';

    $reprint = $shipmentId > 0 ? (new GlsShipmentService())->reprintLabel($shipmentId) : null;
    $out['checks']['reprint_returns_label'] = is_array($reprint) && (string) ($reprint['label'] ?? '') !== '';

    // MyGLS CODAmount is the domain forint, never the FC cents value.
    $printBody = json_decode((string) $GLOBALS['fch_gls_last_print_body'], true);
    $parcelPayload = is_array($printBody) && isset($printBody['ParcelList'][0]) ? $printBody['ParcelList'][0] : [];
    $codInPayload = $parcelPayload['CODAmount'] ?? null;
    $codLedger = $ledger->getByKey(GlsShipmentService::idempotencyKey($orderId, 1));
    $out['checks']['money_boundary_huf_cod'] = $codInPayload === 2500
        && $codLedger !== null
        && (int) $codLedger->amount_minor === 2500
        && !str_contains((string) $GLOBALS['fch_gls_last_print_body'], '"CODAmount":250000');

    $postsBeforeDuplicate = $mock->callCount('/ParcelService.svc/json/PrintLabels');
    $duplicate = (new GlsShipmentService())->createForOrder($orderId, 1);
    $postsAfterDuplicate = $mock->callCount('/ParcelService.svc/json/PrintLabels');
    $orderRows = $repo->listForOrder($orderId);
    $out['checks']['duplicate_no_extra_call'] = $postsAfterDuplicate === $postsBeforeDuplicate
        && count($orderRows) === 1
        && ($duplicate['status'] ?? '') !== 'created';

    // --- D: timeout -> UNCERTAIN + reconcile job, exactly one create POST ---------------------------
    gls_flow_create_order($timeoutOrderId, 100000);
    $GLOBALS['fch_gls_timeout'] = true;
    (new GlsShipmentService())->createForOrder($timeoutOrderId, 1);
    $GLOBALS['fch_gls_timeout'] = false;

    $timeoutKey = GlsShipmentService::idempotencyKey($timeoutOrderId, 1);
    $ledgerRow = $ledger->getByKey($timeoutKey);
    $GLOBALS['fch_gls_timeout_ref'] = (string) ($ledgerRow->business_reference ?? '');
    $postsAfterTimeout = $mock->callCount('/ParcelService.svc/json/PrintLabels');

    $out['checks']['timeout_uncertain'] = $ledgerRow !== null && (string) $ledgerRow->state === ExternalOperation::UNCERTAIN;
    $reconcileJob = $ledgerRow !== null ? $jobStore->findByKey('fch:reconcile:' . (int) $ledgerRow->id) : null;
    $out['checks']['reconcile_job_pending'] = $reconcileJob !== null
        && (string) $reconcileJob->state === 'PENDING'
        && (string) $reconcileJob->hook === ReconcileUncertainJob::HOOK;

    // --- E: reconciler read-back proves the create, never a re-POST ---------------------------------
    if ($ledgerRow !== null) {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => (int) $ledgerRow->id]);
        } catch (\Throwable $e) {
            // Still-uncertain / no reconciler is a valid outcome; the assertions below handle it.
        }
    }
    $postsAfterReconcile = $mock->callCount('/ParcelService.svc/json/PrintLabels');
    $out['checks']['reconcile_no_repost'] = $postsAfterReconcile === $postsAfterTimeout;
    $out['checks']['reconcile_used_read_back'] = $mock->callCount('/ParcelService.svc/json/GetParcelList') > 0;

    // --- F: tracking sync is monotonic --------------------------------------------------------------
    // Deterministic across reruns: the fixture order's shipping_status is reset, otherwise a previous
    // run's monotonic result (delivered) would correctly block the shipped step and the assertion
    // would measure leftover state instead of the transition.
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET shipping_status = '' WHERE id = %d", $orderId));
    $GLOBALS['fch_gls_status_phase'] = 'transit';
    $syncTransit = (new GlsTracking())->syncOrder($orderId);
    $GLOBALS['fch_gls_status_phase'] = 'delivered';
    $syncDelivered = (new GlsTracking())->syncOrder($orderId);

    $orderClass = 'FluentCart\\App\\Models\\Order';
    $shippedOrder = class_exists($orderClass) ? $orderClass::find($orderId) : null;
    $out['debug_tracking'] = [
        'transit' => array_intersect_key($syncTransit, array_flip(['status', 'applied', 'reason', 'shipments', 'checked'])),
        'delivered' => array_intersect_key($syncDelivered, array_flip(['status', 'applied', 'reason'])),
    ];
    $out['checks']['tracking_shipped'] = in_array('shipped', (array) ($syncTransit['applied'] ?? []), true)
        || (is_object($shippedOrder) && (string) ($shippedOrder->shipping_status ?? '') === 'shipped');
    $deliveredOrder = class_exists($orderClass) ? $orderClass::find($orderId) : null;
    $out['checks']['tracking_delivered'] = in_array('delivered', (array) ($syncDelivered['applied'] ?? []), true)
        || (is_object($deliveredOrder) && (string) ($deliveredOrder->shipping_status ?? '') === 'delivered');

    // Re-polling an earlier state must not regress a delivered order.
    $GLOBALS['fch_gls_status_phase'] = 'transit';
    $syncAgain = (new GlsTracking())->syncOrder($orderId);
    $finalOrder = class_exists($orderClass) ? $orderClass::find($orderId) : null;
    $out['checks']['tracking_monotonic'] = is_object($finalOrder)
        ? (string) ($finalOrder->shipping_status ?? '') === 'delivered'
        : !in_array('delivered', (array) ($syncAgain['applied'] ?? []), true);

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/**
 * Real FluentCart order fixture for the service-level tests: COD order (offline_payment), amounts in
 * FluentCart-native cents, one physical item and a shipping address row.
 */
function gls_flow_create_order(int $orderId, int $totalCents): ?object
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
            'title' => 'Lab tétel (GLS utánvét)',
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
                'meta' => [
                    'phone' => '+36301234567',
                    'email' => 'lab-buyer@example.test',
                    // FC OrderAddress exposes phone/email through meta.other_data (getPhoneAttribute/
                    // getEmailAttribute, OrderAddress.php:98-102, 286-293) — the table has no own column.
                    'other_data' => ['phone' => '+36301234567', 'email' => 'lab-buyer@example.test'],
                ],
            ]);
        }
        // routing meta reset (re-runnable fixture: a carrier flow must not inherit another flow's
        // order-time snapshot when order ids are reused across flows).
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key IN ('_fch_carrier','_fch_service','_fch_carrier_status','_fch_carrier_status_at')", $orderId));
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET config = %s WHERE id = %d", wp_json_encode(['shipping_method_id' => 94103, 'shipping_method_title' => 'Lab']), $orderId));
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
