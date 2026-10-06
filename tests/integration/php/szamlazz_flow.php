<?php
/**
 * W1 billing/szamlazz integration flow — AUTHOR ONLY, DO NOT RUN AS PART OF THE UNIT SUITE.
 *
 * Run on the FluentCart lab (WP + FluentCart 1.7.0) with:
 *   wp eval-file tests/integration/php/szamlazz_flow.php
 *
 * It installs the MockTransport (`fch/http/pre_request`) so no network is touched, then checks:
 *   A. `fluent_cart/order_paid_done` wiring enqueues exactly one `fch_issue_invoice` job per order;
 *   B. a successful create -> one document row, EXTERNAL_CONFIRMED, PDF read-back stored (pdf_ref set);
 *   C. a duplicate run -> still one document row and no second dispatch;
 *   D. a create timeout -> ledger UNCERTAIN + a PENDING `fch_reconcile_uncertain` job;
 *   E. the reconciler query proves the document -> ledger + document EXTERNAL_CONFIRMED (never re-created).
 *
 * Prints exactly one JSON line. The integrator owns running this and fixing lab-specific details.
 */

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\Szamlazz\IssueInvoiceJob;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzModule;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzReconciler;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => []];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }

    $mode = 'test';
    $account = 'acc-szamlazz-lab';
    $orderId = 991001;
    $timeoutOrderId = 991002;
    $feeOrderId = 991003; // invoice double-count regression (fee is both an item and fee_total in FC)

    // Re-runnable: delete synthetic document/ledger/job rows for this flow's orders.
    global $wpdb;
    foreach ([$orderId, $timeoutOrderId, $feeOrderId] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid));
        $ledgerIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        foreach ((array) $ledgerIds as $lid) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", 'fch:reconcile:' . (int) $lid));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key = %s", sprintf('fch:%d:invoice_job', $oid)));
    }

    (new ModuleSettings())->setMode($mode);
    $settings = new ModuleSettings();
    $settings->set('szamlazz', 'account_id', $account);
    $settings->set('szamlazz', 'selected_invoicer', 'szamlazz');
    $settings->set('szamlazz', 'amount_basis', 'auto');
    $settings->set('szamlazz', 'pdf_download', true);
    (new CredentialStore())->set('szamlazz', $mode, $account, 'agent_key', 'LAB-AGENT-KEY');

    update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['billing/szamlazz' => true]));

    $successXml = MockTransport::fixtureRaw('szamlazz/success.xml');
    $queryXml = MockTransport::fixtureRaw('szamlazz/query_szamla.xml');
    $GLOBALS['fch_sz_create_timeout'] = false;

    $GLOBALS['fch_sz_last_create_body'] = '';
    $router = static function ($url, $args) use ($successXml, $queryXml) {
        $body = (string) ($args['body'] ?? '');
        if (str_contains($body, 'action-xmlagentxmlfile')) {
            $GLOBALS['fch_sz_last_create_body'] = $body;
            if (!empty($GLOBALS['fch_sz_create_timeout'])) {
                return MockTransport::timeout();
            }
            return MockTransport::response(200, $successXml, ['szlahu_szamlaszam' => 'FCH-2026-000123']);
        }
        if (str_contains($body, 'xmlszamlapdf')) {
            return MockTransport::response(200, "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        }
        if (str_contains($body, 'xmlszamlaxml')) {
            return MockTransport::response(200, $queryXml);
        }
        return MockTransport::response(200, $successXml);
    };

    $mock = MockTransport::make()->on('szamlazz.hu/szamla', $router);
    $mock->install();

    // --- A: hook wiring enqueues exactly one fch_issue_invoice job per order -------------------------
    update_option('fch_modules', array_merge((array) get_option('fch_modules', []), ['billing/szamlazz' => true]));
    ReconcilerRegistry::register('szamlazz', new SzamlazzReconciler());
    JobRunner::instance()->registerHandler(new IssueInvoiceJob());

    $module = new SzamlazzModule();
    $module->onOrderPaidDone(['order' => (object) ['id' => $orderId]]);
    $module->onOrderPaidDone(['order' => (object) ['id' => $orderId]]);
    $jobStore = new JobStore();
    $hookJob = $jobStore->findByKey(sprintf('fch:%d:invoice_job', $orderId));
    $jobs = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$jobStore->table()} WHERE job_key = %s",
        sprintf('fch:%d:invoice_job', $orderId)
    ));
    $out['checks']['hook_enqueues_single_job'] = $hookJob !== null
        && $hookJob->hook === IssueInvoiceJob::HOOK
        && (int) $jobs === 1;

    // --- B/C: successful create -> one confirmed document row + PDF; duplicate run is a no-op ---------
    // FluentCart-native amounts: 1 500 Ft net + 27% VAT (405 Ft) = 1 905 Ft gross, stored as FC cents
    // (150000 / 40500 / 190500). The invoice must carry the FORINT values after the boundary conversion.
    $createdOrder = szamlazz_flow_create_order($orderId, 150000, 40500);
    if ($createdOrder === null) {
        // No real FluentCart order available: exercise the ledger/document mechanism directly.
        $out['checks']['order_fixture'] = false;
        $serviceResult = szamlazz_flow_direct_issue($orderId, $successXml, $queryXml);
    } else {
        $out['checks']['order_fixture'] = true;
        $service = new SzamlazzInvoiceService();
        $serviceResult = $service->issue($orderId);
        $service->issue($orderId); // duplicate hook: must not create a second document/dispatch
    }

    $documents = new InvoiceDocumentRepository();
    $rows = $documents->listForOrder($orderId);
    $confirmed = array_values(array_filter($rows, static fn($r): bool => (string) $r->state === ExternalOperation::EXTERNAL_CONFIRMED));
    $out['checks']['one_document_row'] = count($rows) === 1;
    $out['checks']['document_confirmed'] = count($confirmed) === 1 && ($confirmed[0]->document_number ?? '') !== '';
    $out['checks']['pdf_stored'] = count($confirmed) === 1 && !empty($confirmed[0]->pdf_ref);
    $out['checks']['single_dispatch'] = (($serviceResult['status'] ?? '') === 'issued')
        || (($serviceResult['status'] ?? '') === 'already_issued');

    // --- B2: money boundary — the create XML carries the real FORINT amounts, not FC cents -----------
    $xmlBody = (string) ($GLOBALS['fch_sz_last_create_body'] ?? '');
    $out['checks']['money_boundary_huf'] = str_contains($xmlBody, '<nettoErtek>1500</nettoErtek>')
        && str_contains($xmlBody, '<afaErtek>405</afaErtek>')
        && str_contains($xmlBody, '<bruttoErtek>1905</bruttoErtek>')
        && str_contains($xmlBody, '<nettoEgysegar>1500.00</nettoEgysegar>')
        && !str_contains($xmlBody, '<nettoErtek>150000</nettoErtek>');

    // --- B3: a FluentCart fee (item + fee_total) must appear EXACTLY ONCE and the invoice must sum to
    // the order total (regression for the real-checkout double-count).
    $GLOBALS['fch_sz_last_create_body'] = '';
    $feeOrder = szamlazz_flow_create_order($feeOrderId, 150000, 40500, 45000);
    if ($feeOrder === null) {
        $out['checks']['fee_not_double_counted'] = false;
    } else {
        (new SzamlazzInvoiceService())->issue($feeOrderId);
        $feeBody = (string) ($GLOBALS['fch_sz_last_create_body'] ?? '');
        $grosses = [];
        if (preg_match_all('/<bruttoErtek>([^<]*)</', $feeBody, $m)) {
            $grosses = array_map('intval', $m[1]);
        }
        $out['checks']['fee_not_double_counted'] = count($grosses) >= 2
            && array_sum($grosses) === 2355
            && count(array_filter($grosses, static fn(int $g): bool => $g === 450)) === 1
            && str_contains($feeBody, '<bruttoErtek>1905</bruttoErtek>')
            && !str_contains($feeBody, '450000');
    }

    // --- D: timeout -> ledger UNCERTAIN + reconcile job ----------------------------------------------
    $GLOBALS['fch_sz_create_timeout'] = true;
    $timeoutOrder = szamlazz_flow_create_order($timeoutOrderId, 150000, 40500);
    if ($timeoutOrder === null) {
        $timeoutResult = szamlazz_flow_direct_issue($timeoutOrderId, $successXml, $queryXml, true);
    } else {
        $timeoutResult = (new SzamlazzInvoiceService())->issue($timeoutOrderId);
    }
    $ledger = new LedgerRepository();
    $utcKey = ExternalOperation::idempotencyKey($timeoutOrderId, 'create_invoice', 1);
    $ledgerRow = $ledger->getByKey($utcKey);
    $reconcileJob = $ledgerRow !== null ? $jobStore->findByKey('fch:reconcile:' . (int) $ledgerRow->id) : null;
    $out['checks']['timeout_uncertain'] = $ledgerRow !== null && (string) $ledgerRow->state === ExternalOperation::UNCERTAIN;
    $out['checks']['reconcile_job_pending'] = $reconcileJob !== null
        && (string) $reconcileJob->state === 'PENDING'
        && (string) $reconcileJob->hook === ReconcileUncertainJob::HOOK;

    // --- E: reconciler proves the timeout via query -> confirmed (never re-created) ------------------
    $GLOBALS['fch_sz_create_timeout'] = false;
    if ($ledgerRow !== null) {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => (int) $ledgerRow->id]);
        } catch (\Throwable $e) {
            // Still-uncertain / no reconciler is a valid outcome; assert below handles it.
        }
        $after = $ledger->getById((int) $ledgerRow->id);
        $out['checks']['reconciler_confirmed'] = $after !== null
            && (string) $after->state === ExternalOperation::EXTERNAL_CONFIRMED;
        $timeoutDocs = $documents->listForOrder($timeoutOrderId);
        $out['checks']['reconciler_no_new_document'] = count($timeoutDocs) <= 1;
    } else {
        $out['checks']['reconciler_confirmed'] = false;
        $out['checks']['reconciler_no_new_document'] = false;
    }

    $mock->uninstall();
    $out['ok'] = !in_array(false, $out['checks'], true);
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";

/**
 * Best-effort real FluentCart order fixture. Amounts are FluentCart-native: amount×100 ("cents"), even
 * for HUF. Returns null when Core is absent or creation fails.
 */
function szamlazz_flow_create_order(int $orderId, int $netCents, int $taxCents, int $feeCents = 0): ?object
{
    $gross = $netCents + $taxCents;
    $net = $netCents;
    $fee = max(0, $feeCents);
    $total = $gross + $fee;
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        $order = $orderClass::find($orderId);
        if ($order === null) {
            // FluentCart's Order model does not take an explicit id (guarded): create, then renumber the
            // fresh row to the fixed synthetic id so the flow's keys stay deterministic.
            $order = $orderClass::create([
                'status' => 'completed',
                'payment_status' => 'paid',
                'type' => 'payment',
                'fulfillment_type' => 'digital',
                'customer_id' => 0,
                'currency' => 'HUF',
                'subtotal' => $net,
                'tax_total' => $gross - $net,
                'fee_total' => $fee,
                'total_amount' => $total,
                'total_paid' => $total,
                'tax_behavior' => 1,
                'mode' => 'test',
            ]);
            global $wpdb;
            $actual = (int) $order->id;
            if ($actual !== $orderId) {
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
                $order = $orderClass::find($orderId);
            }
        } else {
            // Self-healing fixture: older runs may have left non-native amounts behind; normalize so the
            // assertions always test the same FluentCart-native cents.
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fct_orders SET subtotal=%d, tax_total=%d, fee_total=%d, total_amount=%d, total_paid=%d WHERE id=%d",
                $net,
                $gross - $net,
                $fee,
                $total,
                $total,
                $orderId
            ));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id=%d", $orderId));
            $order = $orderClass::find($orderId);
        }
        if ($order === null) {
            return null;
        }
        $itemClass::create([
            'order_id' => (int) $order->id,
            'title' => 'Lab könyv',
            'quantity' => 1,
            'unit_price' => $net,
            'subtotal' => $net,
            'tax_amount' => $gross - $net,
            'discount_total' => 0,
            'line_total' => $net,
            'rate' => 27,
            'payment_type' => 'onetime',
            'fulfillment_type' => 'digital',
        ]);
        if ($fee > 0) {
            // FluentCart models the COD fee as a real order item AND sets order.fee_total.
            $itemClass::create([
                'order_id' => (int) $order->id,
                'title' => 'Utánvét díja',
                'quantity' => 1,
                'unit_price' => $fee,
                'subtotal' => $fee,
                'tax_amount' => 0,
                'discount_total' => 0,
                'line_total' => $fee,
                'rate' => 0,
                'payment_type' => 'onetime',
                'fulfillment_type' => 'digital',
            ]);
        }
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Direct ledger/document/runner/reconciler flow used when no real order can be created.
 * @return array{status:string,state:string}
 */
function szamlazz_flow_direct_issue(int $orderId, string $successXml, string $queryXml, bool $timeout = false): array
{
    $documents = new InvoiceDocumentRepository();
    $key = ExternalOperation::idempotencyKey($orderId, 'create_invoice', 1);
    $doc = $documents->getOrCreate([
        'order_id' => $orderId,
        'provider' => 'szamlazz',
        'mode' => 'test',
        'account_id' => 'acc-szamlazz-lab',
        'doc_role' => 'invoice',
        'sequence_no' => 1,
        'idempotency_key' => $key,
        'vendor_id' => $key,
        'is_selected_invoicer' => 1,
        'currency' => 'HUF',
    ]);
    $docId = (int) $doc['row']->id;

    $run = (new OperationRunner())->run([
        'idempotency_key' => $key,
        'module' => 'billing',
        'provider' => 'szamlazz',
        'kind' => 'create_invoice',
        'mode' => 'test',
        'account_id' => 'acc-szamlazz-lab',
        'order_id' => $orderId,
        'owner_type' => 'invoice_document',
        'owner_id' => $docId,
        'currency' => 'HUF',
        'amount_minor' => 1905,
        'business_reference' => 'FCH-' . $orderId,
        'max_attempts' => 3,
    ], static function () use ($timeout, $successXml): ProviderCallResult {
        if ($timeout) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH');
        }
        return ProviderCallResult::success(['external_id' => 'FCH-2026-000123', 'document_number' => 'FCH-2026-000123']);
    });

    if ((string) $run['state'] === ExternalOperation::EXTERNAL_CONFIRMED) {
        SzamlazzInvoiceService::ensureDocumentInProgress($documents, $docId);
        $documents->markIssued($docId, ['document_number' => 'FCH-2026-000123', 'external_id' => 'FCH-2026-000123']);
    } elseif ((string) $run['state'] === ExternalOperation::UNCERTAIN) {
        SzamlazzInvoiceService::ensureDocumentInProgress($documents, $docId);
        $documents->markUncertain($docId);
    }

    return ['status' => (string) $run['state'] === ExternalOperation::EXTERNAL_CONFIRMED ? 'issued' : (string) $run['state'], 'state' => (string) $run['state']];
}
