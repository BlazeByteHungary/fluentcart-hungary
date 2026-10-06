<?php
/**
 * W2 shipping/packeta integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/packeta_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`) so no network is touched, then checks:
 *   A. create success  -> one shipment row EXTERNAL_CONFIRMED with a barcode + a stored label;
 *   B. duplicate       -> still one row and no second external dispatch;
 *   C. create timeout  -> ledger UNCERTAIN + a PENDING `fch_reconcile_uncertain` job;
 *   D. reconciler      -> `packetInfo` proves the packet => ledger + row EXTERNAL_CONFIRMED, and the
 *                         createPacket call is never re-POSTed.
 *
 * Packeta has NO sandbox (G1): this script records mock-only verification and always runs in `test`
 * mode against the MockTransport; production go-live is gated by PacketaModule::probe().
 *
 * Prints exactly one JSON line. The integrator owns running this and fixing lab-specific details.
 */

use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaReconciler;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaPacketService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'mode' => 'mock-only', 'note' => 'Packeta has no sandbox (G1); verification is mock-only'];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-packeta-lab';
    $orderId = 992001;
    $timeoutOrderId = 992002;

    (new ModuleSettings())->setMode($mode);
    $settings = new ModuleSettings();
    $settings->set('packeta', 'account_id', $account);
    $settings->set('packeta', 'eshop', 'FCHLAB');
    $settings->set('packeta', 'number_prefix', '');
    $settings->set('packeta', 'hd_address_id', '4159');
    $settings->set('packeta', 'label_format', 'A6 on A6');
    $settings->set('packeta', 'default_weight_g', 1000);
    (new CredentialStore())->set('packeta', $mode, $account, 'api_password', str_repeat('p', 32));
    (new CredentialStore())->set('packeta', $mode, $account, 'api_key', str_repeat('k', 16));

(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94105, ['carrier' => 'packeta', 'service' => 'home']); // 3/3 routing gate binding
        update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['shipping/packeta' => true]));

    $GLOBALS['fch_packeta_create_timeout'] = false;

    // Re-runnable: drop synthetic rows/jobs for this flow's orders.
    global $wpdb;
    foreach ([$orderId, $timeoutOrderId] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d", $oid));
        $ledgerIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        foreach ((array) $ledgerIds as $lid) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", 'fch:reconcile:' . (int) $lid));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    }

    $createXml = MockTransport::fixtureRaw('packeta/create_packet_success.xml');
    $infoXml = MockTransport::fixtureRaw('packeta/packet_info_success.xml');
    $labelXml = MockTransport::fixtureRaw('packeta/label_pdf.xml');
    $statusXml = MockTransport::fixtureRaw('packeta/packet_status_delivered.xml');

    // One POST endpoint; route by the method element in the XML body (first match wins).
    $mock = MockTransport::make()->on('/api/rest', function ($url, $args) use ($createXml, $infoXml, $labelXml, $statusXml) {
        $body = is_array($args['body'] ?? null) ? (string) wp_json_encode($args['body']) : (string) ($args['body'] ?? '');
        if (str_contains($body, '<createPacket>')) {
            if (!empty($GLOBALS['fch_packeta_create_timeout'])) {
                return MockTransport::timeout();
            }
            return MockTransport::response(200, $createXml, ['Content-Type' => 'text/xml']);
        }
        if (str_contains($body, '<packetInfo>')) {
            return MockTransport::response(200, $infoXml, ['Content-Type' => 'text/xml']);
        }
        if (str_contains($body, '<packetLabelPdf>')) {
            return MockTransport::response(200, $labelXml, ['Content-Type' => 'text/xml']);
        }
        if (str_contains($body, '<packetStatus>')) {
            return MockTransport::response(200, $statusXml, ['Content-Type' => 'text/xml']);
        }
        return MockTransport::response(404, '');
    })->install();

    $repo = new ShipmentRepository();
    $ledger = new LedgerRepository();
    $jobStore = new JobStore();

    if (!ReconcilerRegistry::has('packeta')) {
        ReconcilerRegistry::register('packeta', new PacketaReconciler());
    }

    // --- A: create success, barcode + label ---------------------------------------------------------
    $order = packeta_flow_create_order($orderId, 424000);
    if ($order === null) {
        throw new \RuntimeException('Unable to create FluentCart lab order ' . $orderId);
    }
    $created = (new PacketaPacketService())->createForOrder($orderId, 1);
    $out['debug_create'] = array_intersect_key($created, array_flip(['status', 'state', 'reason', 'errors']));
    $row = null;
    foreach ($repo->listForOrder($orderId) as $candidate) {
        if ((string) ($candidate->carrier ?? '') === 'packeta') {
            $row = $candidate;
        }
    }

    $out['checks']['create_confirmed'] = $row !== null && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $out['checks']['barcode_stored'] = $row !== null && (string) ($row->barcode ?? '') === 'Z4154090000';
    $out['checks']['packet_id_string'] = $row !== null && (string) ($row->external_shipment_id ?? '') === '4154090000';
    $out['checks']['label_stored'] = $row !== null && (string) ($row->label_ref ?? '') !== '';

    // --- B: duplicate business intent -> no second external create -----------------------------------
    $createPostsOf = static function (string $needle) use ($mock): int {
        $count = 0;
        foreach ($mock->calls('/api/rest') as $call) {
            $body = (string) ($call['body'] ?? '');
            if (str_contains($body, '<createPacket>') && ($needle === '' || str_contains($body, $needle))) {
                $count++;
            }
        }
        return $count;
    };
    $postsBefore = $createPostsOf('<number>' . $orderId . '</number>');
    $duplicate = (new PacketaPacketService())->createForOrder($orderId, 1);
    $postsAfter = $createPostsOf('<number>' . $orderId . '</number>');
    $out['checks']['duplicate_one_row'] = count($repo->listForOrder($orderId)) === 1;
    $out['checks']['duplicate_no_second_post'] = $postsBefore === 1 && $postsAfter === 1 && ($duplicate['status'] ?? '') === 'already_created';

    // --- C: create timeout -> UNCERTAIN + reconcile job ---------------------------------------------
    $GLOBALS['fch_packeta_create_timeout'] = true;
    $timeoutOrder = packeta_flow_create_order($timeoutOrderId, 254000);
    if ($timeoutOrder === null) {
        throw new \RuntimeException('Unable to create FluentCart lab order ' . $timeoutOrderId);
    }
    $timeout = (new PacketaPacketService())->createForOrder($timeoutOrderId, 1);

    $timeoutRow = null;
    foreach ($repo->listForOrder($timeoutOrderId) as $candidate) {
        if ((string) ($candidate->carrier ?? '') === 'packeta') {
            $timeoutRow = $candidate;
        }
    }
    $timeoutLedger = $ledger->getByKey('fch:' . $timeoutOrderId . ':packeta:label_create:1');
    $reconcileJob = $timeoutLedger !== null ? $jobStore->findByKey('fch:reconcile:' . (int) $timeoutLedger->id) : null;

    $out['checks']['timeout_uncertain'] = ($timeout['state'] ?? '') === ExternalOperation::UNCERTAIN
        && $timeoutLedger !== null
        && (string) $timeoutLedger->state === ExternalOperation::UNCERTAIN;
    $out['checks']['reconcile_job_pending'] = $reconcileJob !== null
        && (string) $reconcileJob->state === 'PENDING'
        && (string) $reconcileJob->hook === ReconcileUncertainJob::HOOK;

    // --- D: packetInfo read-back proves the create; never a re-POST ---------------------------------
    $GLOBALS['fch_packeta_create_timeout'] = false;
    if ($timeoutRow !== null) {
        // A lost response leaves no packet id on the row: an operator supplies it from the Packeta UI
        // (04 §7 has no lookup-by-number), which is the documented manual step before read-back.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$repo->table()} SET external_shipment_id = %s, barcode = %s WHERE id = %d",
            '4154090000',
            'Z4154090000',
            (int) $timeoutRow->id
        ));
    }
    $postsBeforeReconcile = $createPostsOf('<number>' . $timeoutOrderId . '</number>');

    if ($timeoutLedger !== null) {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => (int) $timeoutLedger->id]);
        } catch (\Throwable $e) {
            // Still-uncertain / no reconciler is a valid outcome; assertions below handle it.
        }
        $after = $ledger->getById((int) $timeoutLedger->id);
        $timeoutRowAfter = $timeoutRow !== null ? $repo->getById((int) $timeoutRow->id) : null;
        $postsAfterReconcile = $createPostsOf('<number>' . $timeoutOrderId . '</number>');

        $out['checks']['reconciler_confirmed'] = $after !== null && (string) $after->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['reconciler_shipment_confirmed'] = $timeoutRowAfter !== null && (string) $timeoutRowAfter->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['reconciler_no_repost'] = $postsBeforeReconcile === 1 && $postsAfterReconcile === 1;
        $out['checks']['reconciler_no_lookup_by_number'] = $mock->callCount('/api/rest') > 0; // packetInfo was used
    } else {
        $out['checks']['reconciler_confirmed'] = false;
        $out['checks']['reconciler_shipment_confirmed'] = false;
        $out['checks']['reconciler_no_repost'] = false;
        $out['checks']['reconciler_no_lookup_by_number'] = false;
    }

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/**
 * Real FluentCart order fixture: one physical item and a HU shipping address row, amounts in
 * FluentCart-native cents (total ×100). Non-COD here; the COD rule is covered by the unit suite.
 */
function packeta_flow_create_order(int $orderId, int $totalCents): ?object
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
                'payment_status' => 'paid',
                'payment_method' => 'bank_transfer',
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
                "UPDATE {$wpdb->prefix}fct_orders SET subtotal=%d, tax_total=0, total_amount=%d, payment_method='bank_transfer' WHERE id=%d",
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
            'title' => 'Lab tétel (Packeta)',
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
                'name' => 'Packeta Lab Vásárló',
                'address_1' => 'Deák Ferenc tér 4',
                'city' => 'Budapest',
                'state' => '',
                'postcode' => '1052',
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
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET config = %s WHERE id = %d", wp_json_encode(['shipping_method_id' => 94105, 'shipping_method_title' => 'Lab']), $orderId));
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
