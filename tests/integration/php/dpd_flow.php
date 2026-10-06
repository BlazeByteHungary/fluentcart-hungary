<?php
/**
 * W2 shipping/dpd integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/dpd_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`, chainable `install()`) so no network is touched,
 * injects a synthetic bearer JWT into the CredentialStore, then checks:
 *   A. create success  -> one shipment row EXTERNAL_CONFIRMED with a tracking number + a stored label;
 *   B. duplicate job    -> still one shipment row and no second external POST;
 *   C. create timeout   -> ledger UNCERTAIN + a PENDING `fch_reconcile_uncertain` job;
 *   D. reprint          -> read-only read of the stored label (no provider call, no state change);
 *   E. no DPD reconciler is registered (no query-by-reference endpoint, DPD-906/DPD-U8).
 *
 * Prints exactly one JSON line. The integrator owns running this and fixing lab-specific details.
 * DPD has no known sandbox credentials (JWT acquisition is undocumented, G10): this is mock-only.
 */

use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Dpd\DpdShipmentService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'mode' => 'mock-only', 'note' => 'DPD JWT acquisition is undocumented (G10); verification is mock-only via an injected token'];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-dpd-lab';
    $orderId = 993001;
    $timeoutOrderId = 993002;

    (new ModuleSettings())->setMode($mode);
    $settings = new ModuleSettings();
    $settings->set('dpd', 'account_id', $account);
    $settings->set('dpd', 'bu_code', '123');
    $settings->set('dpd', 'customer_id', 'CUST-993001');
    $settings->set('dpd', 'main_service_code', '101');
    $settings->set('dpd', 'sender_address_id', 'ADDR-LAB-1');
    $settings->set('dpd', 'label_size', 'A4');
    $settings->set('dpd', 'print_format', 'pdf');
    $settings->set('dpd', 'dpi', 203);
    $settings->set('dpd', 'save_mode', 'printed');
    $settings->set('dpd', 'cod_payment_type', 'Cash');
    $settings->set('dpd', 'default_weight_g', 1000);
    (new CredentialStore())->set('dpd', $mode, $account, 'token', 'LAB-DPD-JWT-SYNTHETIC');

(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94104, ['carrier' => 'dpd', 'service' => 'home']); // 3/3 routing gate binding
        update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['shipping/dpd' => true]));

    $GLOBALS['fch_dpd_create_timeout'] = false;

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

    $create = MockTransport::fixture('dpd/create_success.json');
    $label = MockTransport::fixture('dpd/label_response.json');

    // Rule order matters: MockTransport matches by URL substring, first match wins.
    $mock = MockTransport::make()
        ->on('/labels', MockTransport::json(200, $label))
        ->on('/shipments', function ($url, $args) use ($create) {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            if ($method === 'POST') {
                if (!empty($GLOBALS['fch_dpd_create_timeout'])) {
                    return MockTransport::timeout();
                }
                return MockTransport::json(200, $create);
            }
            // GET /shipments/{id} (DPD-005) — not used by the create path; provided for completeness.
            return MockTransport::json(200, MockTransport::fixture('dpd/shipment_get.json'));
        })
        ->install();

    $repo = new ShipmentRepository();
    $ledger = new LedgerRepository();
    $jobStore = new JobStore();

    // DPD must have NO reconciler (no documented query-by-reference endpoint, DPD-906/DPD-U8).
    $out['checks']['no_reconciler_registered'] = !ReconcilerRegistry::has('dpd');

    // --- A: create success -> confirmed + stored label -----------------------------------------------
    $codOrder = dpd_flow_create_order($orderId, 424000, 'offline_payment');
    if ($codOrder === null) {
        $out['checks']['create_confirmed'] = false;
        $out['checks']['tracking_stored'] = false;
        $out['checks']['label_stored'] = false;
        $out['checks']['duplicate_one_post'] = false;
    } else {
        $created = (new DpdShipmentService())->createForOrder($orderId, 1);
        $rows = $repo->listForOrder($orderId);
        $row = $rows[0] ?? null;

        $out['checks']['create_confirmed'] = ($created['state'] ?? '') === ExternalOperation::EXTERNAL_CONFIRMED
            && $row !== null && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['tracking_stored'] = $row !== null && (string) ($row->tracking_number ?? '') !== '';
        $out['checks']['label_stored'] = $row !== null && (string) ($row->label_ref ?? '') !== '';

        $postsAfterFirst = dpd_flow_create_posts($mock);
        $duplicate = (new DpdShipmentService())->createForOrder($orderId, 1);
        $postsAfterDuplicate = dpd_flow_create_posts($mock);
        $out['checks']['duplicate_one_post'] = $postsAfterFirst === 1
            && $postsAfterDuplicate === 1
            && (($duplicate['status'] ?? '') === 'already_created')
            && count($repo->listForOrder($orderId)) === 1;

        // --- D: reprint is read-only (stored label only) --------------------------------------------
        $before = $repo->getById((int) $row->id);
        $reprint = (new DpdShipmentService())->reprintLabel((int) $row->id);
        $afterReprint = $repo->getById((int) $row->id);
        $out['checks']['reprint_returns_label'] = is_array($reprint) && ($reprint['label'] ?? '') !== '';
        $out['checks']['reprint_read_only'] = $before !== null && $afterReprint !== null
            && (string) $before->state === (string) $afterReprint->state
            && (string) ($before->label_state ?? '') === (string) ($afterReprint->label_state ?? '');
    }

    // --- C: timeout -> UNCERTAIN + PENDING reconcile job ---------------------------------------------
    $GLOBALS['fch_dpd_create_timeout'] = true;
    $timeoutOrder = dpd_flow_create_order($timeoutOrderId, 0, 'offline_payment');
    if ($timeoutOrder === null) {
        $out['checks']['timeout_uncertain'] = false;
        $out['checks']['reconcile_job_pending'] = false;
    } else {
        $timeoutResult = (new DpdShipmentService())->createForOrder($timeoutOrderId, 1);
        $key = DpdShipmentService::idempotencyKey($timeoutOrderId, 1);
        $ledgerRow = $ledger->getByKey($key);
        $reconcileJob = $ledgerRow !== null ? $jobStore->findByKey('fch:reconcile:' . (int) $ledgerRow->id) : null;

        $out['checks']['timeout_uncertain'] = ($timeoutResult['state'] ?? '') === ExternalOperation::UNCERTAIN
            && $ledgerRow !== null && (string) $ledgerRow->state === ExternalOperation::UNCERTAIN;
        $out['checks']['reconcile_job_pending'] = $reconcileJob !== null
            && (string) $reconcileJob->state === 'PENDING'
            && (string) $reconcileJob->hook === ReconcileUncertainJob::HOOK;
    }
    $GLOBALS['fch_dpd_create_timeout'] = false;

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/** Count create POSTs to /shipments (never the cancellation/labels sub-paths). */
function dpd_flow_create_posts(MockTransport $mock): int
{
    $count = 0;
    foreach ($mock->calls('/shipments') as $call) {
        if (strtoupper((string) $call['method']) === 'POST' && !str_contains((string) $call['url'], '/shipments/')) {
            $count++;
        }
    }
    return $count;
}

/**
 * Real FluentCart order fixture for the flow: amounts in FluentCart-native cents, one physical item and a
 * shipping address row. `$paymentMethod` is `offline_payment` for the COD case.
 */
function dpd_flow_create_order(int $orderId, int $totalCents, string $paymentMethod): ?object
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
                'payment_method' => $paymentMethod,
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
                "UPDATE {$wpdb->prefix}fct_orders SET subtotal=%d, tax_total=0, total_amount=%d, payment_method=%s WHERE id=%d",
                $totalCents,
                $totalCents,
                $paymentMethod,
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
            'title' => 'Lab tétel (DPD)',
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
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET config = %s WHERE id = %d", wp_json_encode(['shipping_method_id' => 94104, 'shipping_method_title' => 'Lab']), $orderId));
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
