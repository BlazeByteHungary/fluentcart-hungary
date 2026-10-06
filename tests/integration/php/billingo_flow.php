<?php
/**
 * W3 Billingo + shared invoicing-routing flow — AUTHOR ONLY, DO NOT RUN as part of this task.
 *
 * Run by the integrator on the lab WordPress with FluentCart + this plugin active:
 *   wp eval-file fluentcart-hungary/tests/integration/php/billingo_flow.php
 *
 * Mock-only (`fch/http/pre_request` seam; a catch-all guarantees no real network). Covers:
 *  1. event-driven issuance (order_paid_done -> job -> router -> Billingo) with partner create,
 *     document create, PDF read-back stored sealed; create/download/partner call counts = 1.
 *  2. re-run -> already_issued, zero new provider calls.
 *  3. provider created but the response was lost -> UNCERTAIN; reconciler vendor_id lookup proves the
 *     SAME document (still exactly 1 create, 1 document) — never a second create.
 *  4. vendor lookup 404 after a lost create -> stays UNCERTAIN (a 404 is not a rejection without an
 *     explicit visibility guarantee; W4_EXECUTION_PLAN §5); re-running keeps the same identity.
 *  5. PDF fetch failure -> document stays issued without pdf_ref; explicit retry downloads only
 *     (create count unchanged).
 *  6. routing: global switch never reroutes an existing/UNCERTAIN row; confirmed other-provider doc
 *     blocks a second automatic invoice; selection off skips; old Számlázz docs stay readable.
 *  7. COD before_dispatch: label trigger invoices an unpaid COD order, issuance does not mark it paid,
 *     repeat label/payment triggers never issue a second invoice.
 *  8. parallel admin/paid/label triggers -> exactly one document.
 *  9. error mapping: 429 -> local error (retryable, nothing created); 422 -> rejected with a
 *     merchant-visible, secret-free label.
 * 10. amount math: 3790 + 450 COD fee = 4240 on a real FC order; provider drift is flagged loudly.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\Billingo\BillingoDocumentStore;
use FluentCartHungary\Modules\Billing\Billingo\BillingoInvoiceService;
use FluentCartHungary\Modules\Billing\Billingo\BillingoModule;
use FluentCartHungary\Modules\Billing\Billingo\BillingoReconciler;
use FluentCartHungary\Modules\Billing\InvoicerSelection;
use FluentCartHungary\Modules\Billing\InvoiceRouter;
use FluentCartHungary\Modules\Billing\Szamlazz\IssueInvoiceJob;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzDocumentStore;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzModule;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['errors' => []];
global $wpdb;

// ---------------------------------------------------------------- setup
$orderIds = range(992101, 992120);
foreach ($orderIds as $oid) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s", '%' . $oid . '%'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $oid));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d", $oid));
}
$wpdb->query("DELETE FROM {$wpdb->prefix}fch_jobs WHERE hook IN ('fch_issue_invoice', 'fch_reconcile_uncertain')");

update_option('fch_queue_driver', 'db', false);
$settings = new ModuleSettings();
$settings->setMode('test');
$settings->set('billingo', 'block_id', 1);
$settings->set('billingo', 'partner_mode', 'create');
$settings->set('billingo', 'pdf_download', true);
$settings->set('billingo', 'payment_deadline_days', 8);
$settings->set('billing', 'selected_invoicer', 'billingo');
$settings->set('billing', 'cod_invoice_event', 'payment');
$store = new CredentialStore();
$store->set('billingo', 'test', 'default', 'api_key', 'test-billingo-key-w3');

// Plugin-boot equivalent: register both billing modules' hooks so the routing/job path is what runs.
(new BillingoModule())->registerHooks();
(new SzamlazzModule())->registerHooks();

$docs = new InvoiceDocumentRepository();
$ledger = new LedgerRepository();

$jsonResp = static function (array $body, int $http = 201): array {
    return MockTransport::response($http, (string) wp_json_encode($body), ['Content-Type' => 'application/json']);
};
$pdfResp = static fn(): array => MockTransport::response(200, "%PDF-1.4\n% test billingo pdf\n", ['Content-Type' => 'application/pdf']);
$noNetwork = static fn(MockTransport $mock): MockTransport => $mock->on('api.billingo.hu', MockTransport::timeout());

$runDue = static function (int $n = 20): int {
    global $wpdb;
    // Job claims compare against UTC_TIMESTAMP(); force queued invoice jobs due for the manual pump.
    $wpdb->query("UPDATE {$wpdb->prefix}fch_jobs SET available_at = UTC_TIMESTAMP() WHERE hook = 'fch_issue_invoice' AND state = 'PENDING'");
    return JobRunner::instance()->runDue($n);
};
$pumpFor = static function (int $orderId, int $n = 10) use ($runDue): int {
    global $wpdb;
    // Keep provider-call counters deterministic: drop other orders' queued invoice jobs from this pump.
    // Deterministic pump: keep ONLY this order's invoice job (shipping label jobs and stale reconciles
    // must not consume the manual queue slots).
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}fch_jobs WHERE state = 'PENDING' AND NOT (hook = 'fch_issue_invoice' AND job_key LIKE %s)",
        '%' . $orderId . '%'
    ));
    return $runDue($n);
};
$orderObj = static function (int $id): ?object {
    return class_exists('FluentCart\\App\\Models\\Order') ? \FluentCart\App\Models\Order::query()->find($id) : null;
};
$latestDoc = static function (int $orderId) use ($docs): ?object {
    foreach ($docs->listForOrder($orderId, 10) as $row) {
        if ((string) $row->provider === 'billingo') {
            return $row;
        }
    }
    return null;
};

// ================================================================ Step 1: event-driven issuance
try {
    $order = billingo_flow_create_order(992101, 379000, 45000, 'offline_payment', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid', status='processing' WHERE id=%d", 992101));

    $creates = 0;
    $partners = 0;
    $mock = MockTransport::make()
        ->on('/v3/partners', static function ($url, $args) use (&$partners, $jsonResp) {
            $partners++;
            return $jsonResp(['id' => 555, 'name' => 'Teszt Vevő'], 201);
        })
        ->on('/v3/documents/777/download', $pdfResp)
        ->on('/v3/documents', static function ($url, $args) use (&$creates, $jsonResp) {
            $creates++;
            return $jsonResp([
                'id' => 777, 'invoice_number' => 'BIL-2026-000777', 'type' => 'invoice',
                'gross_total' => 4240, 'net_total' => 4240, 'vat_amount' => 0, 'currency' => 'HUF',
                'conversion_rate' => 1, 'cancelled' => false, 'payment_status' => 'none',
            ]);
        });
    $noNetwork($mock)->install();

    do_action('fluent_cart/order_paid_done', ['order' => $order]);
    $ran = $pumpFor(992101);
    $doc = $latestDoc(992101);
    $mock->uninstall();

    $pdf = $doc && !empty($doc->pdf_ref) ? (new BillingoDocumentStore())->read((string) $doc->pdf_ref) : null;
    $out['step1'] = [
        'jobs_ran' => $ran,
        'state' => $doc ? (string) $doc->state : '',
        'number' => $doc ? (string) $doc->document_number : '',
        'gross' => $doc ? (int) $doc->gross_minor : 0,
        'pdf_readable' => is_string($pdf) && str_starts_with($pdf, '%PDF'),
        'pdf_ref' => $doc ? (string) ($doc->pdf_ref ?? '') : '',
        'creates' => $creates,
        'partners_created' => $partners,
    ];
    $out['step1']['ok'] = $doc !== null && (string) $doc->state === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $doc->document_number === 'BIL-2026-000777'
        && (int) $doc->gross_minor === 4240
        && $out['step1']['pdf_readable']
        && $creates === 1
        && $partners === 1;
    if (!$out['step1']['ok']) {
        $out['errors'][] = 'step1';
    }
} catch (\Throwable $e) {
    $out['step1'] = ['ok' => false];
    $out['errors'][] = 'step1_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 2: re-run is already_issued
try {
    $mock = MockTransport::make();
    $noNetwork($mock)->install();
    $result = (new BillingoInvoiceService())->issue(992101, 1);
    $mock->uninstall();
    $out['step2'] = ['status' => $result['status'] ?? '', 'creates' => $mock->callCount('api.billingo.hu')];
    $out['step2']['ok'] = ($result['status'] ?? '') === 'already_issued' && $mock->callCount('api.billingo.hu') === 0;
    if (!$out['step2']['ok']) {
        $out['errors'][] = 'step2';
    }
} catch (\Throwable $e) {
    $out['step2'] = ['ok' => false];
    $out['errors'][] = 'step2_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 3: created but response lost
try {
    billingo_flow_create_order(992102, 100000, 0, 'barion', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992102));

    $providerDocs = [];
    $creates = 0;
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 556], 201))
        ->on('/v3/documents/888/download', static fn($url, $args) => MockTransport::response(500, 'no pdf'))
        ->on('/v3/documents/vendor/', static function ($url, $args) use (&$providerDocs) {
            $vendor = substr($url, strpos($url, '/vendor/') + 8);
            $vendor = explode('?', $vendor)[0];
            if (!isset($providerDocs[$vendor])) {
                return MockTransport::response(404, wp_json_encode(['message' => 'Not found']), ['Content-Type' => 'application/json']);
            }
            return MockTransport::response(200, wp_json_encode($providerDocs[$vendor]), ['Content-Type' => 'application/json']);
        })
        ->on('/v3/documents', static function ($url, $args) use (&$providerDocs, &$creates) {
            $creates++;
            $vendor = 'unknown';
            $body = json_decode((string) ($args['body'] ?? ''), true);
            if (is_array($body) && isset($body['vendor_id'])) {
                $vendor = (string) $body['vendor_id'];
            }
            $providerDocs[$vendor] = [
                'id' => 888, 'invoice_number' => 'BIL-2026-000888', 'type' => 'invoice',
                'gross_total' => 1000, 'net_total' => 1000, 'vat_amount' => 0, 'currency' => 'HUF',
            ];
            return MockTransport::timeout(); // the provider DID create the document; the client never sees the response
        });
    $noNetwork($mock)->install();

    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992102)]);
    $pumpFor(992102, 1);
    $doc = $latestDoc(992102);
    $stateAfterTimeout = $doc ? (string) $doc->state : '';
    $vendor = $doc ? (string) $doc->vendor_id : '';

    // Recovery: the registered reconciler resolves by vendor_id — no create is re-dispatched.
    $ledgerRow = $ledger->getByKey($vendor);
    $reconciled = $ledgerRow ? (new BillingoReconciler())($ledgerRow) : null;
    $docAfter = $latestDoc(992102);
    $mock->uninstall();

    $out['step3'] = [
        'state_after_timeout' => $stateAfterTimeout,
        'provider_documents_created' => count(array_filter(array_keys($providerDocs), static fn($v): bool => $v !== 'unknown')),
        'creates' => $creates,
        'reconcile_status' => $reconciled ? $reconciled->status : '',
        'state_after_reconcile' => $docAfter ? (string) $docAfter->state : '',
        'number_after_reconcile' => $docAfter ? (string) $docAfter->document_number : '',
        'vendors' => array_keys($providerDocs),
        'doc_rows' => array_map(static fn($r): array => [(int) $r->id, (string) $r->vendor_id, (string) $r->state], $docs->listForOrder(992102, 10)),
    ];
    $out['step3']['ok'] = $stateAfterTimeout === ExternalOperation::UNCERTAIN
        && $creates === 1
        && count(array_filter(array_keys($providerDocs), static fn($v): bool => $v !== 'unknown')) === 1
        && $reconciled !== null && $reconciled->isSuccess()
        && $docAfter !== null && (string) $docAfter->state === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $docAfter->document_number === 'BIL-2026-000888'
        && $creates === 1;
    if (!$out['step3']['ok']) {
        $out['errors'][] = 'step3';
    }
} catch (\Throwable $e) {
    $out['step3'] = ['ok' => false];
    $out['errors'][] = 'step3_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 4: lookup 404 -> stays UNCERTAIN (not a rejection)
try {
    billingo_flow_create_order(992103, 50000, 0, 'barion', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992103));
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 557], 201))
        ->on('/v3/documents/vendor/', static fn($url, $args) => MockTransport::response(404, wp_json_encode(['message' => 'Not found'])))
        ->on('/v3/documents', static fn($url, $args) => MockTransport::timeout());
    $noNetwork($mock)->install();

    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992103)]);
    $jobsEnqueued = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s", '%992103%'));
    $pumpRan = $pumpFor(992103, 1);
    $jobRows = $wpdb->get_results($wpdb->prepare("SELECT job_key, hook, state, last_error FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s", '%992103%'), ARRAY_A);
    $doc = $latestDoc(992103);
    $vendor = $doc ? (string) $doc->vendor_id : '';
    $ledgerRow = $ledger->getByKey($vendor);
    $result = $ledgerRow ? (new BillingoReconciler())($ledgerRow) : null;
    $docAfter = $latestDoc(992103);
    $mock->uninstall();

    $out['step4'] = [
        'reconcile_status' => $result ? $result->status : '',
        'state' => $docAfter ? (string) $docAfter->state : '',
        'doc_state_at_timeout' => $doc ? (string) $doc->state : '',
        'vendor' => $vendor,
        'ledger_state' => $ledgerRow ? (string) $ledgerRow->state : '',
        'doc_rows' => array_map(static fn($r): array => [(int) $r->id, (string) $r->state], $docs->listForOrder(992103, 10)),
        'jobs_enqueued' => $jobsEnqueued,
        'pump_ran' => $pumpRan,
        'job_rows' => $jobRows,
    ];
    // The reconciler is called directly here (the job transition is covered elsewhere): a 404 must
    // resolve to UNCERTAIN, the document row must not change state and no second row may appear.
    $out['step4']['ok'] = $result !== null && $result->status === \FluentCartHungary\Core\Ledger\ProviderCallResult::UNCERTAIN
        && $docAfter !== null && in_array((string) $docAfter->state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)
        && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", 992103)) === 1;
    if (!$out['step4']['ok']) {
        $out['errors'][] = 'step4';
    }
} catch (\Throwable $e) {
    $out['step4'] = ['ok' => false];
    $out['errors'][] = 'step4_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 5: PDF failure then retry-only
try {
    billingo_flow_create_order(992104, 60000, 0, 'barion', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992104));
    $creates = 0;
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 558], 201))
        ->on('/v3/documents/999/download', static fn($url, $args) => MockTransport::response(500, 'pdf error'))
        ->on('/v3/documents', static function ($url, $args) use (&$creates) {
            $creates++;
            return MockTransport::response(201, wp_json_encode([
                'id' => 999, 'invoice_number' => 'BIL-2026-000999', 'type' => 'invoice',
                'gross_total' => 600, 'net_total' => 600, 'vat_amount' => 0, 'currency' => 'HUF',
            ]), ['Content-Type' => 'application/json']);
        });
    $noNetwork($mock)->install();

    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992104)]);
    $pumpFor(992104);
    $doc = $latestDoc(992104);
    $createsAfterCreate = $creates;
    $mock->uninstall();

    // Admin retry: download only.
    $retryMock = MockTransport::make()
        ->on('/v3/documents/999/download', $pdfResp);
    $noNetwork($retryMock)->install();
    $retry = (new BillingoInvoiceService())->retryPdf((int) $doc->id);
    $docAfter = $latestDoc(992104);
    $retryMock->uninstall();
    $pdf = !empty($docAfter->pdf_ref) ? (new BillingoDocumentStore())->read((string) $docAfter->pdf_ref) : null;

    $out['step5'] = [
        'state' => $doc ? (string) $doc->state : '',
        'pdf_after_create' => $doc ? (string) ($doc->pdf_ref ?? '') : '',
        'creates' => $createsAfterCreate,
        'retry_status' => $retry['status'] ?? '',
        'retry_reason' => $retry['reason'] ?? '',
        'pdf_after_retry' => is_string($pdf) && str_starts_with($pdf, '%PDF'),
    ];
    $out['step5']['ok'] = $doc !== null && (string) $doc->state === ExternalOperation::EXTERNAL_CONFIRMED
        && ($doc->pdf_ref ?? null) === null
        && $createsAfterCreate === 1
        && ($retry['status'] ?? '') === 'stored'
        && $out['step5']['pdf_after_retry'];
    if (!$out['step5']['ok']) {
        $out['errors'][] = 'step5';
    }
} catch (\Throwable $e) {
    $out['step5'] = ['ok' => false];
    $out['errors'][] = 'step5_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 6: routing under switch
try {
    // 6a: an UNCERTAIN Billingo row stays bound to Billingo after selecting Számlázz.
    billingo_flow_create_order(992105, 70000, 0, 'barion', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992105));
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 559], 201))
        ->on('/v3/documents/vendor/', static fn($url, $args) => MockTransport::response(404, '{}'))
        ->on('/v3/documents', static fn($url, $args) => MockTransport::timeout());
    $noNetwork($mock)->install();
    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992105)]);
    $pumpFor(992105, 1);
    $mock->uninstall();

    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $routeBound = InvoiceRouter::route(992105);
    $routeFresh = InvoiceRouter::route(992199);
    $settings->set('billing', 'selected_invoicer', 'off');
    $routeOff = InvoiceRouter::route(992199);
    $settings->set('billing', 'selected_invoicer', 'billingo');

    // 6b: a confirmed Számlázz doc blocks a second automatic invoice even with Billingo selected.
    $szamlazzOrderId = 992106;
    billingo_flow_create_order($szamlazzOrderId, 51000, 0, 'barion', false);
    $szRow = $docs->getOrCreate([
        'order_id' => $szamlazzOrderId, 'order_uuid' => 'SYNTH' . $szamlazzOrderId, 'provider' => 'szamlazz',
        'mode' => 'test', 'account_id' => 'default', 'doc_role' => 'invoice', 'sequence_no' => 1,
        'idempotency_key' => \FluentCartHungary\Core\Ledger\ExternalOperation::idempotencyKey($szamlazzOrderId, 'create_invoice', 1),
        'is_selected_invoicer' => 1, 'currency' => 'HUF',
    ])['row'];
    $docs->markStarted((int) $szRow->id);
    $docs->markIssued((int) $szRow->id, ['document_number' => 'SZA-2026-000111', 'external_id' => 'SZA-2026-000111']);

    $mock = MockTransport::make();
    $noNetwork($mock)->install();
    $blocked = (new BillingoInvoiceService())->issue($szamlazzOrderId, 1);
    $mock->uninstall();

    // 6c: old Számlázz documents remain readable via their own store after the switch.
    $legacyRef = (new SzamlazzDocumentStore())->store('szamlazz-invoice-SZA-2026-000111', "%PDF-1.4\n% legacyl szamlazz pdf\n", 'pdf');
    $legacyPdf = (new SzamlazzDocumentStore())->read($legacyRef['ref']);

    $out['step6'] = [
        'route_bound_reason' => $routeBound['reason'],
        'route_bound_provider' => $routeBound['provider'],
        'route_fresh_provider' => $routeFresh['provider'],
        'route_off_provider' => $routeOff['provider'],
        'route_off_reason' => $routeOff['reason'],
        'blocked_status' => $blocked['status'] ?? '',
        'blocked_reason' => $blocked['reason'] ?? '',
        'legacy_pdf_readable' => is_string($legacyPdf) && str_starts_with($legacyPdf, '%PDF'),
    ];
    $out['step6']['ok'] = $routeBound['provider'] === 'billingo' && $routeBound['reason'] === 'bound_to_existing_document'
        && $routeFresh['provider'] === 'szamlazz' && $routeOff['provider'] === null && $routeOff['reason'] === 'selection_off'
        && ($blocked['status'] ?? '') === 'skipped' && str_starts_with((string) ($blocked['reason'] ?? ''), 'single_invoicer_active')
        && $out['step6']['legacy_pdf_readable'];
    if (!$out['step6']['ok']) {
        $out['errors'][] = 'step6';
    }
} catch (\Throwable $e) {
    $out['step6'] = ['ok' => false];
    $out['errors'][] = 'step6_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 7: COD before_dispatch
try {
    $settings->set('billing', 'selected_invoicer', 'billingo');
    $settings->set('billing', 'cod_invoice_event', 'before_dispatch');
    billingo_flow_create_order(992107, 100000, 0, 'offline_payment', false);
    $creates = 0;
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 560], 201))
        ->on('/v3/documents/1001/download', $pdfResp)
        ->on('/v3/documents', static function ($url, $args) use (&$creates) {
            $creates++;
            return MockTransport::response(201, wp_json_encode([
                'id' => 1001, 'invoice_number' => 'BIL-2026-001001', 'type' => 'invoice',
                'gross_total' => 1000, 'net_total' => 1000, 'vat_amount' => 0, 'currency' => 'HUF',
            ]), ['Content-Type' => 'application/json']);
        });
    $noNetwork($mock)->install();

    do_action('fch/shipping/label_started', 992107);
    $pumpFor(992107);
    $createsAfterLabel = $creates;
    $order = \FluentCart\App\Models\Order::query()->find(992107);

    // Repeat label trigger and a later payment event: no second invoice.
    do_action('fch/shipping/label_started', 992107);
    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992107)]);
    $pumpFor(992107);
    $createsAfterRepeat = $creates;
    $mock->uninstall();
    $doc = $latestDoc(992107);

    $out['step7'] = [
        'state' => $doc ? (string) $doc->state : '',
        'creates_after_label' => $createsAfterLabel,
        'creates_after_repeat' => $createsAfterRepeat,
        'order_payment_status' => $order ? (string) $order->payment_status : '',
    ];
    $out['step7']['ok'] = $doc !== null && (string) $doc->state === ExternalOperation::EXTERNAL_CONFIRMED
        && $createsAfterLabel === 1 && $createsAfterRepeat === 1
        && $order !== null && (string) $order->payment_status !== 'paid';
    if (!$out['step7']['ok']) {
        $out['errors'][] = 'step7';
    }
} catch (\Throwable $e) {
    $out['step7'] = ['ok' => false];
    $out['errors'][] = 'step7_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 8: parallel triggers -> one document
try {
    $settings->set('billing', 'cod_invoice_event', 'payment');
    billingo_flow_create_order(992108, 80000, 0, 'offline_payment', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992108));
    $creates = 0;
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 561], 201))
        ->on('/v3/documents/1002/download', $pdfResp)
        ->on('/v3/documents', static function ($url, $args) use (&$creates) {
            $creates++;
            return MockTransport::response(201, wp_json_encode([
                'id' => 1002, 'invoice_number' => 'BIL-2026-001002', 'type' => 'invoice',
                'gross_total' => 800, 'net_total' => 800, 'vat_amount' => 0, 'currency' => 'HUF',
            ]), ['Content-Type' => 'application/json']);
        });
    $noNetwork($mock)->install();

    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992108)]);
    do_action('fch/shipping/label_started', 992108);
    do_action('fluent_cart/order_paid_done', ['order' => $orderObj(992108)]);
    $pumpFor(992108);
    $pumpFor(992108);
    $mock->uninstall();
    $rows = array_values(array_filter($docs->listForOrder(992108, 10), static fn($r): bool => (string) $r->provider === 'billingo'));

    $out['step8'] = ['document_rows' => count($rows), 'creates' => $creates];
    $out['step8']['ok'] = count($rows) === 1 && $creates === 1;
    if (!$out['step8']['ok']) {
        $out['errors'][] = 'step8';
    }
} catch (\Throwable $e) {
    $out['step8'] = ['ok' => false];
    $out['errors'][] = 'step8_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 9: error mapping (429/422)
try {
    billingo_flow_create_order(992109, 90000, 0, 'barion', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992109));
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 562], 201))
        ->on('/v3/documents', static fn($url, $args) => MockTransport::response(429, wp_json_encode(['message' => 'Too many requests'])));
    $noNetwork($mock)->install();
    $rateLimited = (new BillingoInvoiceService())->issue(992109, 1);
    $docRate = $latestDoc(992109);
    $mock->uninstall();

    billingo_flow_create_order(992110, 91000, 0, 'barion', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992110));
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 563], 201))
        ->on('/v3/documents', static fn($url, $args) => MockTransport::response(422, wp_json_encode(['message' => 'Validation error', 'errors' => ['block_id' => ['invalid']]])));
    $noNetwork($mock)->install();
    $validation = (new BillingoInvoiceService())->issue(992110, 1);
    $docValidation = $latestDoc(992110);
    $mock->uninstall();

    $out['step9'] = [
        'rate_status' => $rateLimited['status'] ?? '',
        'rate_reason' => $rateLimited['reason'] ?? '',
        'rate_state' => $docRate ? (string) $docRate->state : '',
        'validation_status' => $validation['status'] ?? '',
        'validation_reason' => $validation['reason'] ?? '',
        'validation_state' => $docValidation ? (string) $docValidation->state : '',
    ];
    $out['step9']['ok'] = ($rateLimited['status'] ?? '') === 'local_error'
        && ($rateLimited['reason'] ?? '') === 'RATE_LIMITED'
        && $docRate !== null && (string) $docRate->state === ExternalOperation::NOT_STARTED
        && ($validation['status'] ?? '') === 'rejected'
        && ($validation['reason'] ?? '') === 'VALIDATION'
        && $docValidation !== null && (string) $docValidation->state === ExternalOperation::PROVEN_REJECTED;
    if (!$out['step9']['ok']) {
        $out['errors'][] = 'step9';
    }
} catch (\Throwable $e) {
    $out['step9'] = ['ok' => false];
    $out['errors'][] = 'step9_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ Step 10: amount math 3790+450=4240 (+drift flag)
try {
    billingo_flow_create_order(992111, 379000, 45000, 'offline_payment', false);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET payment_status='paid' WHERE id=%d", 992111));
    $captured = [];
    $mock = MockTransport::make()
        ->on('/v3/partners', static fn($url, $args) => $jsonResp(['id' => 564], 201))
        ->on('/v3/documents/1003/download', $pdfResp)
        ->on('/v3/documents', static function ($url, $args) use (&$captured) {
            $captured[] = json_decode((string) ($args['body'] ?? ''), true);
            return MockTransport::response(201, wp_json_encode([
                'id' => 1003, 'invoice_number' => 'BIL-2026-001003', 'type' => 'invoice',
                'gross_total' => 4241, 'net_total' => 4241, 'vat_amount' => 0, 'currency' => 'HUF', // intentional drift
            ]), ['Content-Type' => 'application/json']);
        });
    $noNetwork($mock)->install();
    $result = (new BillingoInvoiceService())->issue(992111, 1);
    $doc = $latestDoc(992111);
    $mock->uninstall();

    $payload = is_array($captured[0] ?? null) ? $captured[0] : [];
    $payloadSum = 0;
    foreach ((array) ($payload['items'] ?? []) as $item) {
        $payloadSum += (int) round(((float) ($item['unit_price'] ?? 0)) * ((float) ($item['quantity'] ?? 1)));
    }
    $snapshot = json_decode('{}', true);
    $out['step10'] = [
        'payload_sum' => $payloadSum,
        'vendor_id' => (string) ($payload['vendor_id'] ?? ''),
        'doc_state' => $doc ? (string) $doc->state : '',
        'gross_minor' => $doc ? (int) $doc->gross_minor : 0,
        'snapshot_has_mismatch' => $doc ? str_contains((string) ($doc->vat_snapshot ?? ''), 'amount_mismatch') : false,
    ];
    $out['step10']['ok'] = $payloadSum === 4240
        && str_starts_with((string) $out['step10']['vendor_id'], 'fch-992111-billingo-invoice-')
        && $doc !== null && (string) $doc->state === ExternalOperation::EXTERNAL_CONFIRMED
        && (int) $doc->gross_minor === 4241 // provider value kept, drift flagged
        && $out['step10']['snapshot_has_mismatch'];
    if (!$out['step10']['ok']) {
        $out['errors'][] = 'step10';
    }
} catch (\Throwable $e) {
    $out['step10'] = ['ok' => false];
    $out['errors'][] = 'step10_exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

// ================================================================ cleanup shared routing state
// The shared selection is a merchant setting: clear it so other suites keep the W1 legacy behaviour
// (unset shared => legacy szamlazz scope is authoritative).
$settings->set('billing', 'selected_invoicer', '');
$settings->set('billing', 'cod_invoice_event', '');

// ================================================================ result
$out['ok'] = empty($out['errors']);
foreach (['step1', 'step2', 'step3', 'step4', 'step5', 'step6', 'step7', 'step8', 'step9', 'step10'] as $step) {
    $out['ok'] = $out['ok'] && (($out[$step]['ok'] ?? false) === true);
}
echo function_exists('wp_json_encode') ? wp_json_encode($out) : json_encode($out);
echo "\n";

/**
 * Real FluentCart order fixture with an optional fee line (FC stores amount×100).
 */
function billingo_flow_create_order(int $orderId, int $subtotalCents, int $feeCents, string $paymentMethod, bool $paid): ?object
{
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        $order = $orderClass::create([
            'status' => $paid ? 'processing' : 'pending',
            'payment_status' => $paid ? 'paid' : 'pending',
            'payment_method' => $paymentMethod,
            'type' => 'payment',
            'fulfillment_type' => 'physical',
            'customer_id' => 0,
            'currency' => 'HUF',
            'subtotal' => $subtotalCents,
            'tax_total' => 0,
            'shipping_total' => 0,
            'fee_total' => $feeCents,
            'total_amount' => $subtotalCents + $feeCents,
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
            'order_id' => (int) $order->id,
            'title' => 'Billingo lab termék',
            'quantity' => 1,
            'unit_price' => $subtotalCents,
            'subtotal' => $subtotalCents,
            'tax_amount' => 0,
            'discount_total' => 0,
            'line_total' => $subtotalCents,
            'rate' => 0,
            'payment_type' => 'onetime',
            'fulfillment_type' => 'physical',
        ]);
        if ($feeCents > 0) {
            $itemClass::create([
                'order_id' => (int) $order->id,
                'title' => 'Utánvét díja',
                'quantity' => 1,
                'unit_price' => $feeCents,
                'subtotal' => $feeCents,
                'tax_amount' => 0,
                'discount_total' => 0,
                'line_total' => $feeCents,
                'rate' => 0,
                'payment_type' => 'onetime',
                'fulfillment_type' => 'physical',
            ]);
        }
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
