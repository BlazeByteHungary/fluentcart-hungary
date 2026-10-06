<?php
/**
 * W1 closeout lab test — COD invoicing policy on REAL FluentCart orders with the mock Számlázz transport.
 *
 * Run on the FluentCart lab: wp eval-file tests/integration/php/cod_invoice_policy.php
 *
 * Merchant policy under test (default preserved):
 *   - prepaid: invoice only after the payment condition is verified (`order_paid_done`), unchanged;
 *   - COD + `cod_invoice_event=payment` (DEFAULT): unpaid COD order is skipped (`order_not_paid`);
 *   - COD + `cod_invoice_event=before_dispatch`: an unpaid COD order is invoiced when the label request
 *     fires; the order's payment status is NEVER mutated;
 *   - a later paid event or a repeated trigger creates NO second document and NO extra dispatch;
 *   - UNCERTAIN never re-dispatches a create (read-back reconciliation owns it);
 *   - invoice gross equals the order total in domain minor units.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\Szamlazz\IssueInvoiceJob;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzModule;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => []];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-cod-lab';
    $defaultOrderId = 991010;   // default policy check
    $beforeDispatchId = 991011; // before_dispatch happy path
    $uncertainOrderId = 991012; // before_dispatch + forced timeout

    global $wpdb;
    foreach ([$defaultOrderId, $beforeDispatchId, $uncertainOrderId] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", sprintf('fch:%d:invoice_job', $oid)));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $oid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d", $oid));
    }

    $settings = new ModuleSettings();
    $settings->setMode($mode);
    $settings->set('szamlazz', 'account_id', $account);
    $settings->set('szamlazz', 'selected_invoicer', 'szamlazz');
    $settings->set('szamlazz', 'amount_basis', 'auto');
    $settings->set('szamlazz', 'pdf_download', true);
    (new CredentialStore())->set('szamlazz', $mode, $account, 'agent_key', 'LAB-AGENT-KEY');
    update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['billing/szamlazz' => true]));

    $successXml = MockTransport::fixtureRaw('szamlazz/success.xml');
    $GLOBALS['fch_cod_create_calls'] = 0;
    $GLOBALS['fch_cod_create_timeout'] = false;
    $router = static function ($url, $args) use ($successXml) {
        $body = (string) ($args['body'] ?? '');
        if (str_contains($body, 'action-xmlagentxmlfile')) {
            $GLOBALS['fch_cod_create_calls']++;
            if (!empty($GLOBALS['fch_cod_create_timeout'])) {
                return MockTransport::timeout();
            }
            return MockTransport::response(200, $successXml, ['szlahu_szamlaszam' => 'COD-2026-000001']);
        }
        if (str_contains($body, 'xmlszamlapdf')) {
            return MockTransport::response(200, "%PDF-1.4\ncod\n%%EOF\n");
        }
        return MockTransport::response(200, $successXml);
    };
    $mock = MockTransport::make()->on('szamlazz.hu/szamla', $router);
    $mock->install();

    // --- 1. default policy: an unpaid COD order is skipped -------------------------------------------
    // Since W3 the shared selector is authoritative; set BOTH scopes so the flow is isolated from any
    // previously persisted shared value (D-W3-2 test-isolation rule).
    $settings->set('billing', 'cod_invoice_event', 'payment');
    $settings->set('szamlazz', 'cod_invoice_event', 'payment');
    $codOrder = cod_policy_create_order($defaultOrderId, 150000, 40500);
    $out['checks']['cod_fixture'] = $codOrder !== null;
    if ($codOrder !== null) {
        $result = (new SzamlazzInvoiceService())->issue($defaultOrderId);
        $out['checks']['default_skips_unpaid'] = ($result['status'] ?? '') === 'skipped'
            && ($result['reason'] ?? '') === 'order_not_paid'
            && $GLOBALS['fch_cod_create_calls'] === 0;
    } else {
        $out['checks']['default_skips_unpaid'] = false;
    }

    // --- 2. before_dispatch: unpaid COD order is invoiced, exactly once, without becoming paid --------
    $settings->set('billing', 'cod_invoice_event', 'before_dispatch');
    $settings->set('szamlazz', 'cod_invoice_event', 'before_dispatch');
    $beforeOrder = cod_policy_create_order($beforeDispatchId, 150000, 40500);
    if ($beforeOrder !== null) {
        $service = new SzamlazzInvoiceService();
        $first = $service->issue($beforeDispatchId);
        $callsAfterFirst = $GLOBALS['fch_cod_create_calls'];

        // Trigger path as the label job would: enqueue + execute the job again (idempotent).
        $module = new SzamlazzModule();
        $module->onLabelStarted($beforeDispatchId);
        (new IssueInvoiceJob())->handle(['order_id' => $beforeDispatchId]);
        $callsAfterRepeat = $GLOBALS['fch_cod_create_calls'];

        $documents = new InvoiceDocumentRepository();
        $rows = $documents->listForOrder($beforeDispatchId);
        $order = cod_policy_load_order($beforeDispatchId);

        $out['checks']['before_dispatch_issued'] = ($first['status'] ?? '') === 'issued' && $callsAfterFirst === 1;
        $out['checks']['single_document'] = count($rows) === 1
            && (string) $rows[0]->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $out['checks']['repeat_no_dispatch'] = $callsAfterRepeat === 1;
        $out['checks']['order_not_paid_after_invoice'] = $order !== null
            && (string) $order->payment_status === 'pending';

        // Later payment must not create a second invoice (same business identity already confirmed).
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d",
            $beforeDispatchId
        ));
        $module->onOrderPaidDone(['order' => (object) ['id' => $beforeDispatchId]]);
        (new IssueInvoiceJob())->handle(['order_id' => $beforeDispatchId]);
        $out['checks']['paid_later_no_new_invoice'] = $GLOBALS['fch_cod_create_calls'] === 1
            && count($documents->listForOrder($beforeDispatchId)) === 1;

        // Invoice amount equals the order total in domain minor units (1905 Ft gross).
        $out['checks']['amount_matches_order'] = (int) ($rows[0]->gross_minor ?? 0) === 1905;
    } else {
        $out['checks']['before_dispatch_issued'] = false;
        $out['checks']['single_document'] = false;
        $out['checks']['repeat_no_dispatch'] = false;
        $out['checks']['order_not_paid_after_invoice'] = false;
        $out['checks']['paid_later_no_new_invoice'] = false;
        $out['checks']['amount_matches_order'] = false;
    }

    // --- 3. UNCERTAIN: no second create on re-trigger -------------------------------------------------
    $GLOBALS['fch_cod_create_timeout'] = true;
    $uncertainOrder = cod_policy_create_order($uncertainOrderId, 150000, 40500);
    if ($uncertainOrder !== null) {
        $service = new SzamlazzInvoiceService();
        $uncertain = $service->issue($uncertainOrderId);
        $callsAfterTimeout = $GLOBALS['fch_cod_create_calls'];
        $GLOBALS['fch_cod_create_timeout'] = false;
        $again = $service->issue($uncertainOrderId);
        $out['checks']['uncertain_no_recreate'] = ($uncertain['status'] ?? '') === 'uncertain'
            && $callsAfterTimeout === 2 // 1 default-policy skipped + 1 timeout dispatch
            && $GLOBALS['fch_cod_create_calls'] === 2
            && ($again['state'] ?? '') === ExternalOperation::UNCERTAIN;
    } else {
        $out['checks']['uncertain_no_recreate'] = false;
    }

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/** FluentCart-native COD order fixture: unpaid, processing, offline_payment, amount×100 cents. */
function cod_policy_create_order(int $orderId, int $netCents, int $taxCents): ?object
{
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        $order = $orderClass::create([
            'status' => 'processing',
            'payment_status' => 'pending',
            'payment_method' => 'offline_payment',
            'type' => 'payment',
            'fulfillment_type' => 'digital',
            'customer_id' => 0,
            'currency' => 'HUF',
            'subtotal' => $netCents,
            'tax_total' => $taxCents,
            'total_amount' => $netCents + $taxCents,
            'total_paid' => 0,
            'tax_behavior' => 2,
            'mode' => 'test',
        ]);
        global $wpdb;
        $actual = (int) $order->id;
        if ($actual !== $orderId) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
            $order = $orderClass::find($orderId);
        }
        if ($order === null) {
            return null;
        }
        $itemClass::create([
            'order_id' => (int) $order->id,
            'title' => 'Utánvétes könyv',
            'quantity' => 1,
            'unit_price' => $netCents,
            'subtotal' => $netCents,
            'tax_amount' => $taxCents,
            'discount_total' => 0,
            'line_total' => $netCents,
            'rate' => 27,
            'payment_type' => 'onetime',
            'fulfillment_type' => 'digital',
        ]);
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}

function cod_policy_load_order(int $orderId): ?object
{
    $class = 'FluentCart\\App\\Models\\Order';
    if (!class_exists($class)) {
        return null;
    }
    $order = $class::find($orderId);
    return is_object($order) ? $order : null;
}
