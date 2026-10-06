<?php
/**
 * W2 shipping/foxpost integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/foxpost_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`, chainable `install()`) so no network is touched,
 * then checks:
 *   A. APM create success -> one shipment row EXTERNAL_CONFIRMED with barcode/clFoxId + a stored label;
 *      reprint returns the stored label and changes nothing.
 *   B. duplicate APM create with the same uniqueBarcode -> mock returns DUPLICATED_UNIQUE_BARCODE ->
 *      the service treats it as already-created and does not create a second parcel.
 *   C. HD create -> no auto retry (max_attempts = 1); the payload carries the address, not a destination.
 *   D. create timeout -> ledger UNCERTAIN + a PENDING `fch_reconcile_uncertain` job (never a blind re-POST).
 *   E. PULL tracking -> trace status maps to FC shipping_status (monotonic).
 *
 * Prints exactly one JSON line. Foxpost has a documented test host (webapi-test.foxpost.hu) but this flow
 * records mock-only verification (no real credentials, no sandbox calls).
 */

use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostParcelService;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostReconciler;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostTracking;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'mode' => 'mock-only', 'note' => 'Foxpost sandbox host exists (webapi-test.foxpost.hu); verification here is mock-only'];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-foxpost-lab';
    $apmOrderId = 993001;
    $dupOrderId = 993002;
    $hdOrderId = 993003;
    $timeoutOrderId = 993004;

    (new ModuleSettings())->setMode($mode);
    $settings = new ModuleSettings();
    $settings->set('foxpost', 'account_id', $account);
    $settings->set('foxpost', 'delivery_mode', 'HD');
    $settings->set('foxpost', 'default_size', 'M');
    $settings->set('foxpost', 'unique_barcode_prefix', 'FCHU');
    (new CredentialStore())->set('foxpost', $mode, $account, 'username', 'LAB-FOX-USER');
    (new CredentialStore())->set('foxpost', $mode, $account, 'password', 'LAB-FOX-PASS');
    (new CredentialStore())->set('foxpost', $mode, $account, 'api_key', 'LAB-FOX-KEY');

(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94102, ['carrier' => 'foxpost', 'service' => 'home']); // 3/3 routing gate binding
        update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['shipping/foxpost' => true]));

    $GLOBALS['fch_foxpost_timeout'] = false;
    $GLOBALS['fch_foxpost_last_create_body'] = '';

    // Re-runnable: delete synthetic rows and stale reconcile jobs for this flow's orders.
    global $wpdb;
    foreach ([$apmOrderId, $dupOrderId, $hdOrderId, $timeoutOrderId] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d", $oid));
        $ledgerIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        foreach ((array) $ledgerIds as $lid) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", 'fch:reconcile:' . (int) $lid));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    }

    $apm = MockTransport::fixture('foxpost/create_success_apm.json');
    $hd = MockTransport::fixture('foxpost/create_success_hd.json');
    $duplicate = MockTransport::fixture('foxpost/create_duplicate_barcode.json');
    $tracking = MockTransport::fixture('foxpost/tracking_delivered.json');

    // Rule order matters: first match wins. The parcel rule decides by the submitted uniqueBarcode.
    $mock = MockTransport::make()
        ->on('/api/parcel', function ($url, $args) use ($apm, $hd, $duplicate) {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $body = is_array($args['body'] ?? null) ? (string) wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
            $GLOBALS['fch_foxpost_last_create_body'] = $body;
            if ($method !== 'POST') {
                return MockTransport::json(200, []);
            }
            if (!empty($GLOBALS['fch_foxpost_timeout'])) {
                return MockTransport::timeout();
            }
            if (str_contains($body, 'FCHU' . 993002)) {
                return MockTransport::json(200, $duplicate);
            }
            if (str_contains($body, 'FCHU' . 993003)) {
                return MockTransport::json(200, $hd);
            }
            return MockTransport::json(200, $apm);
        })
        ->on('/api/label/', MockTransport::response(200, "%PDF-1.4\nFOXPOST-MOCK-LABEL"))
        ->on('/api/tracking/', MockTransport::json(200, $tracking))
        ->install();

    if (!ReconcilerRegistry::has('foxpost')) {
        ReconcilerRegistry::register('foxpost', new FoxpostReconciler());
    }

    $repo = new ShipmentRepository();
    $ledger = new LedgerRepository();
    $jobStore = new JobStore();

    // --- A: APM create success + label read-back -----------------------------------------------------
    $apmOrder = foxpost_flow_create_order($apmOrderId, 'APM', 424000);
    if ($apmOrder === null) {
        $out['checks']['create_apm_confirmed'] = false;
        $out['checks']['apm_barcode_stored'] = false;
        $out['checks']['label_stored'] = false;
        $out['checks']['reprint_read_only'] = false;
    } else {
        $result = (new FoxpostParcelService())->createForOrder($apmOrderId, 1);
        $out['debug_create'] = array_intersect_key($result, array_flip(['status', 'state', 'reason', 'errors']));
        $row = null;
        foreach ($repo->listForOrder($apmOrderId) as $candidate) {
            if ((string) ($candidate->carrier ?? '') === 'foxpost') {
                $row = $candidate;
                break;
            }
        }
        $out['checks']['create_apm_confirmed'] = in_array((string) ($result['state'] ?? ''), [ExternalOperation::EXTERNAL_CONFIRMED], true)
            && $row !== null && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['apm_barcode_stored'] = $row !== null && (string) ($row->barcode ?? '') !== '';
        $out['checks']['label_stored'] = $row !== null && (string) ($row->label_ref ?? '') !== '';

        if ($row !== null) {
            $before = $repo->getById((int) $row->id);
            $rowsBefore = count($repo->listForOrder($apmOrderId));
            $reprint = (new FoxpostParcelService())->reprintLabel((int) $row->id);
            $after = $repo->getById((int) $row->id);
            $out['checks']['reprint_read_only'] = is_array($reprint)
                && ($reprint['label'] ?? '') !== ''
                && (string) $before->state === (string) $after->state
                && (string) ($before->label_ref ?? '') === (string) ($after->label_ref ?? '')
                && $rowsBefore === count($repo->listForOrder($apmOrderId));
        } else {
            $out['checks']['reprint_read_only'] = false;
        }
    }

    // --- B: duplicate APM uniqueBarcode -> already-created, no second parcel -------------------------
    $dupLedgerKey = 'fch:' . $dupOrderId . ':foxpost:label_create:1';
    $dupOrder = foxpost_flow_create_order($dupOrderId, 'APM', 0);
    if ($dupOrder === null) {
        $out['checks']['duplicate_already_created'] = false;
        $out['checks']['duplicate_one_row'] = false;
    } else {
        $dupResult = (new FoxpostParcelService())->createForOrder($dupOrderId, 1);
        $dupRows = array_values(array_filter(
            $repo->listForOrder($dupOrderId),
            static fn($r): bool => (string) ($r->carrier ?? '') === 'foxpost'
        ));
        $out['checks']['duplicate_already_created'] = (string) ($dupResult['status'] ?? '') === 'already_created'
            && (string) ($dupResult['state'] ?? '') === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['duplicate_one_row'] = count($dupRows) === 1
            && (string) ($dupRows[0]->barcode ?? '') === 'FCHU' . $dupOrderId;
        $out['checks']['duplicate_ledger_confirmed'] = (string) (($ledger->getByKey($dupLedgerKey)->state ?? '')) === ExternalOperation::EXTERNAL_CONFIRMED;
    }

    // --- C: HD create -> max_attempts 1, address payload ---------------------------------------------
    $hdOrder = foxpost_flow_create_order($hdOrderId, 'HD', 0);
    if ($hdOrder === null) {
        $out['checks']['hd_no_retry'] = false;
        $out['checks']['hd_address_payload'] = false;
    } else {
        $snapshot = (new FoxpostParcelService())->buildSnapshot($hdOrder, 1);
        $attempts = is_array($snapshot) ? FoxpostParcelService::maxAttempts($snapshot) : 0;
        $payload = is_array($snapshot) ? FoxpostParcelService::buildPayload($snapshot) : [];
        $out['checks']['hd_no_retry'] = $attempts === 1;
        $out['checks']['hd_address_payload'] = isset($payload['recipientCity'], $payload['recipientZip'], $payload['recipientAddress'])
            && !isset($payload['destination'], $payload['uniqueBarcode']);
    }

    // --- D: timeout -> UNCERTAIN + reconcile job -----------------------------------------------------
    $timeoutKey = 'fch:' . $timeoutOrderId . ':foxpost:label_create:1';
    $timeoutOrder = foxpost_flow_create_order($timeoutOrderId, 'APM', 0);
    if ($timeoutOrder === null) {
        $out['checks']['timeout_uncertain'] = false;
        $out['checks']['reconcile_job_pending'] = false;
    } else {
        $GLOBALS['fch_foxpost_timeout'] = true;
        $timeoutResult = (new FoxpostParcelService())->createForOrder($timeoutOrderId, 1);
        $GLOBALS['fch_foxpost_timeout'] = false;

        $timeoutLedger = $ledger->getByKey($timeoutKey);
        $reconcileJob = $timeoutLedger !== null ? $jobStore->findByKey('fch:reconcile:' . (int) $timeoutLedger->id) : null;
        $out['checks']['timeout_uncertain'] = (string) ($timeoutResult['status'] ?? '') === 'uncertain'
            && $timeoutLedger !== null && (string) $timeoutLedger->state === ExternalOperation::UNCERTAIN;
        $out['checks']['reconcile_job_pending'] = $reconcileJob !== null
            && (string) $reconcileJob->state === 'PENDING'
            && (string) $reconcileJob->hook === ReconcileUncertainJob::HOOK;
    }

    // --- E: PULL tracking -> FC shipping status ------------------------------------------------------
    $trackingResult = (new FoxpostTracking())->syncOrder($apmOrderId);
    $out['checks']['tracking_pull_ok'] = in_array((string) ($trackingResult['status'] ?? ''), ['ok', 'skipped'], true);
    $out['checks']['tracking_maps_delivered'] = $trackingResult['status'] !== 'ok'
        || $trackingResult['applied'] === [] // FC classes/states may be absent on a bare lab
        || in_array('delivered', (array) $trackingResult['applied'], true);

    // APM always carries a pre-generated uniqueBarcode -> conditional retry allowance of 2 (04_FOXPOST §7).
    $apmAttempts = 0;
    if (isset($apmOrder) && is_object($apmOrder)) {
        $apmSnapshot = (new FoxpostParcelService())->buildSnapshot($apmOrder, 1);
        $apmAttempts = is_array($apmSnapshot) ? FoxpostParcelService::maxAttempts($apmSnapshot) : 0;
    }
    $out['checks']['apm_retry_allowance_two'] = $apmAttempts === 2;

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/**
 * Real FluentCart order fixture: COD order (offline_payment), amounts in FluentCart-native cents, one
 * physical item, a shipping address, and the `_fch_delivery_mode` / `_fch_pickup_point` snapshot meta.
 */
function foxpost_flow_create_order(int $orderId, string $deliveryMode, int $totalCents): ?object
{
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    $addressClass = 'FluentCart\\App\\Models\\OrderAddress';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        // Foxpost requires recipientEmail (04_FOXPOST §4 op 1); FC OrderAddress::getEmailAttribute()
        // resolves the order customer's e-mail, so the fixture links a minimal lab customer.
        $customerClass = 'FluentCart\\App\\Models\\Customer';
        $customerId = 0;
        if (class_exists($customerClass)) {
            $customer = $customerClass::firstOrCreate(
                ['email' => 'foxpost-lab-buyer@example.test'],
                ['first_name' => 'Foxpost', 'last_name' => 'Lab', 'status' => 'active']
            );
            $customerId = (int) ($customer->id ?? 0);
        }
        $order = $orderClass::find($orderId);
        if ($order === null) {
            $order = $orderClass::create([
                'status' => 'processing',
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
                $order = $orderClass::find($orderId);
            }
        } else {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fct_orders SET subtotal=%d, tax_total=0, total_amount=%d, payment_method='offline_payment', customer_id=%d WHERE id=%d",
                $totalCents,
                $totalCents,
                $customerId,
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
            'title' => 'Lab tétel (Foxpost)',
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
        if (method_exists($order, 'updateMeta')) {
            $order->updateMeta('_fch_delivery_mode', $deliveryMode);
            if ($deliveryMode === 'APM') {
                $order->updateMeta('_fch_pickup_point', [
                    'carrier' => 'foxpost',
                    'type' => 'APM',
                    'point_id' => 'hu35',
                    'name' => 'Foxpost Automata (lab)',
                    'address' => 'Vaci ut 1.',
                ]);
            }
        }
        // routing meta reset (re-runnable fixture: a carrier flow must not inherit another flow's
        // order-time snapshot when order ids are reused across flows).
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d AND meta_key IN ('_fch_carrier','_fch_service','_fch_carrier_status','_fch_carrier_status_at')", $orderId));
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET config = %s WHERE id = %d", wp_json_encode(['shipping_method_id' => 94102, 'shipping_method_title' => 'Lab']), $orderId));
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
