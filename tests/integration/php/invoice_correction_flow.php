<?php
/**
 * T38 — invoice correction lifecycle (W4 invoicing scope): storno / corrected documents across
 * Számlázz.hu + Billingo, original-binding routing, idempotent ops, read-back after a lost response,
 * PDF-retry-only, late-event safety after a storno, and the refund↔correction link with per-component
 * recovery.
 *
 * Precondition (run.sh): billing/szamlazz, billing/billingo and payments/barion are enabled in
 * `fch_modules` BEFORE this process boots, so the correction dispatchers/reconcilers are the ones the
 * real plugin boot registered. All provider hosts are mocked (catch-all timeout) — no network/real
 * provider call is possible.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Billing\InvoiceCorrectionService;
use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\Billingo\BillingoInvoiceService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzCorrectionService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'cases' => [], 'errors' => []];
if (!defined('FCH_CREDENTIALS_KEY')) {
    define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
}
global $wpdb;

// ---------------------------------------------------------------- boot precondition
$moduleStates = [];
foreach (['billing/szamlazz', 'billing/billingo', 'payments/barion'] as $id) {
    $moduleStates[$id] = \FluentCartHungary\Core\ModuleRegistry::instance()->state($id);
}
$out['boot_states'] = $moduleStates;
$out['checks']['modules_booted'] = !in_array(false, array_map(static fn($s): bool => in_array($s, ['active', 'degraded'], true), $moduleStates), true);
$out['checks']['correction_seams'] = [
    'szamlazz' => \FluentCartHungary\Core\Billing\CorrectionDispatcherRegistry::has('szamlazz'),
    'billingo' => \FluentCartHungary\Core\Billing\CorrectionDispatcherRegistry::has('billingo'),
];

// ---------------------------------------------------------------- cleanup + baseline settings
$orderIds = range(998000, 998020);
foreach ($orderIds as $oid) {
    foreach ([
        "DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s",
        "DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d",
    ] as $sql) {
        $arg = str_contains($sql, 'LIKE') ? '%' . $oid . '%' : $oid;
        $wpdb->query($wpdb->prepare($sql, $arg));
    }
}
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_transactions WHERE order_id BETWEEN 998000 AND 998020");

update_option('fch_queue_driver', 'db', false);
$settings = new ModuleSettings();
$settings->setMode('test');
$settings->set('billing', 'selected_invoicer', 'szamlazz');
$settings->set('billing', 'cod_invoice_event', 'payment');
$settings->set('billing', 'refund_correction_policy', 'off');
$settings->set('szamlazz', 'account_id', 'default');
$settings->set('szamlazz', 'amount_basis', 'auto');
$settings->set('szamlazz', 'pdf_download', true);
$settings->set('billingo', 'account_id', 'default');
$settings->set('billingo', 'block_id', 1);
$settings->set('billingo', 'partner_id', 42);
$settings->set('billingo', 'partner_mode', 'create');
$settings->set('billingo', 'amount_basis', 'gross');
$settings->set('billingo', 'pdf_download', true);
$settings->set('barion', 'payee_email', 't38-lab@example.test');
$creds = new CredentialStore();
$creds->set('szamlazz', 'test', 'default', 'agent_key', 'T38-SZ-AGENT-KEY');
$creds->set('billingo', 'test', 'default', 'api_key', 't38-billingo-key');
$creds->set('barion', 'test', 'default', 'pos_key', 'T38-BARION-POS-KEY');

$GLOBALS['t38'] = [
    'sz_create' => 0, 'sz_storno' => 0, 'sz_delproforma' => 0, 'sz_pdf' => 0, 'sz_query' => 0,
    'sz_doc_seq' => 500, 'sz_last_create_body' => '',
    'bi_create' => 0, 'bi_cancel' => 0, 'bi_modify' => 0, 'bi_get' => 0, 'bi_pdf' => 0, 'bi_delete' => 0,
    'barion_refund' => 0, 'barion_state' => 0, 'barion_reject' => false,
    'sz_create_timeout' => false, 'sz_storno_timeout' => false, 'sz_pdf_timeout' => false,
    'sz_created' => [], 'bi_docs' => [],
];

// ---------------------------------------------------------------- helpers
$createOrder = static function (int $orderId, int $cents, string $method = 'barion', bool $paid = true, string $chargeStatus = 'succeeded'): ?object {
    $orderClass = 'FluentCart\App\Models\Order';
    $itemClass = 'FluentCart\App\Models\OrderItem';
    if (!class_exists($orderClass)) {
        return null;
    }
    global $wpdb;
    $order = $orderClass::create([
        'status' => 'processing',
        'payment_status' => $paid ? 'paid' : 'pending',
        'payment_method' => $method,
        'type' => 'payment',
        'fulfillment_type' => 'physical',
        'customer_id' => 0,
        'currency' => 'HUF',
        'subtotal' => $cents,
        'tax_total' => 0,
        'total_amount' => $cents,
        'total_paid' => $paid ? $cents : 0,
        'tax_behavior' => 0,
        'mode' => 'test',
    ]);
    $actual = (int) $order->id;
    if ($actual !== $orderId) {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_order_items SET order_id = %d WHERE order_id = %d", $orderId, $actual));
    }
    $itemClass::create([
        'order_id' => $orderId,
        'title' => 'T38 korrekciós tétel',
        'quantity' => 1,
        'unit_price' => $cents,
        'subtotal' => $cents,
        'tax_amount' => 0,
        'discount_total' => 0,
        'line_total' => $cents,
        'rate' => 0,
        'payment_type' => 'onetime',
        'fulfillment_type' => 'physical',
    ]);
    if (!$paid) {
        return null;
    }
    $txnClass = 'FluentCart\App\Models\OrderTransaction';
    $txn = $txnClass::create([
        'order_id' => $orderId,
        'transaction_type' => 'charge',
        'status' => $chargeStatus,
        'total' => $cents,
        'currency' => 'HUF',
        'payment_method' => $method,
        'payment_mode' => 'test',
        'vendor_charge_id' => 'T38-' . $orderId,
        'meta' => [],
    ]);
    if ($method === 'barion') {
        $ledger = new LedgerRepository();
        $claim = $ledger->claim([
            'idempotency_key' => 'fch:' . $orderId . ':payment_start:1',
            'module' => 'payments',
            'provider' => 'barion',
            'kind' => 'payment_start',
            'mode' => 'test',
            'account_id' => 'default',
            'order_id' => $orderId,
            'currency' => 'HUF',
            'amount_minor' => FluentCartMoney::fromCartCents($cents, 'HUF'),
            'business_reference' => (string) $orderId,
        ]);
        $ledger->transition((int) $claim['row']->id, ExternalOperation::IN_PROGRESS, [], ExternalOperation::NOT_STARTED);
        $ledger->transition((int) $claim['row']->id, ExternalOperation::EXTERNAL_CONFIRMED, ['external_id' => 'T38-' . $orderId], ExternalOperation::IN_PROGRESS);
    }
    return $txn;
};

$freshCharge = static function (int $orderId) {
    return \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', $orderId)
        ->where('transaction_type', 'charge')
        ->orderBy('id', 'desc')
        ->first();
};

$refundViaFc = static function (int $orderId, int $cents, ?string $token, string $reason = 't38') {
    $txn = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', $orderId)
        ->where('transaction_type', 'charge')
        ->orderBy('id', 'desc')
        ->first();
    if ($txn === null) {
        return new \WP_Error('no_charge', 'no charge');
    }
    $args = ['reason' => $reason];
    if ($token !== null) {
        $args['fch_refund_intent'] = $token;
    }
    $result = (new \FluentCart\App\Services\Payments\Refund())->processRefund($txn, $cents, $args);
    if (is_array($result) && ($result['vendor_refund_id'] ?? null) instanceof \WP_Error) {
        return $result['vendor_refund_id'];
    }
    return $result;
};

$refundTxns = static function (int $orderId): array {
    $collection = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', $orderId)
        ->where('transaction_type', 'refund')
        ->orderBy('id', 'asc')
        ->get();
    return $collection instanceof \Traversable ? array_values(iterator_to_array($collection)) : (array) $collection;
};

$refundOps = static function (int $orderId): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' ORDER BY id ASC",
        $orderId
    ));
    return is_array($rows) ? $rows : [];
};

$docs = static fn(int $orderId): array => (new InvoiceDocumentRepository())->listForOrder($orderId);

$docByRole = static function (int $orderId, string $role) use ($docs): ?object {
    foreach ($docs($orderId) as $row) {
        if ((string) $row->doc_role === $role) {
            return $row;
        }
    }
    return null;
};

$correctionRows = static function (int $orderId) use ($docs): array {
    return array_values(array_filter($docs($orderId), static fn($row): bool => in_array((string) $row->doc_role, InvoiceCorrectionService::KINDS, true)));
};

$opById = static fn(int $id): ?object => (new LedgerRepository())->getById($id);

$counters = static fn(): array => $GLOBALS['t38'];
$delta = static function (array $before): array {
    $after = $GLOBALS['t38'];
    $keys = ['sz_create', 'sz_storno', 'sz_delproforma', 'sz_pdf', 'sz_query', 'bi_create', 'bi_cancel', 'bi_modify', 'bi_get', 'bi_pdf', 'bi_delete', 'barion_refund', 'barion_state'];
    $diff = [];
    foreach ($keys as $k) {
        $diff[$k] = (int) ($after[$k] ?? 0) - (int) ($before[$k] ?? 0);
    }
    return $diff;
};

$resetTransient = static function (): void {
    foreach (['sz_create_timeout', 'sz_storno_timeout', 'sz_pdf_timeout', 'barion_reject'] as $flag) {
        $GLOBALS['t38'][$flag] = false;
    }
};

$xmlValue = static function (string $body, string $tag): string {
    return preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/', $body, $m)
        ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'))
        : '';
};

$xmlResponse = static function (string $inner): array {
    return MockTransport::response(
        200,
        '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">' . $inner . '</xmlszamlavalasz>'
    );
};

$installMocks = static function (bool $withBarion = false) use ($xmlValue): MockTransport {
    // A failed case must never leave an installed mock behind (stacked mocks would double-count calls).
    foreach ((array) ($GLOBALS['t38_mocks'] ?? []) as $old) {
        if ($old instanceof MockTransport) {
            $old->uninstall();
        }
    }
    $GLOBALS['t38_mocks'] = [];
    $szRouter = static function ($url, $args) use ($xmlValue) {
        $body = (string) ($args['body'] ?? '');
        $g = &$GLOBALS['t38'];
        if (str_contains($body, 'action-szamla_agent_st')) {
            $g['sz_storno']++;
            $key = $xmlValue($body, 'szamlaKulsoAzon');
            $g['sz_doc_seq']++;
            $number = 'ST-T38-' . $g['sz_doc_seq'];
            if ($key !== '') {
                $g['sz_created'][$key] = $number;
            }
            if ($g['sz_storno_timeout']) {
                return MockTransport::timeout();
            }
            return MockTransport::response(
                200,
                '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">'
                . '<sikeres>true</sikeres><szamlaszam>' . $number . '</szamlaszam><szamlabrutto>3790</szamlabrutto></xmlszamlavalasz>'
            );
        }
        if (str_contains($body, 'action-szamla_agent_dijbekero_torlese')) {
            $g['sz_delproforma']++;
            return MockTransport::response(
                200,
                '<?xml version="1.0" encoding="UTF-8"?><xmlszamladbkdelvalasz xmlns="http://www.szamlazz.hu/xmlszamladbkdelvalasz"><sikeres>true</sikeres></xmlszamladbkdelvalasz>'
            );
        }
        if (str_contains($body, 'action-xmlagentxmlfile')) {
            $g['sz_create']++;
            $g['sz_last_create_body'] = $body;
            $key = $xmlValue($body, 'szamlaKulsoAzon');
            $g['sz_doc_seq']++;
            $number = 'FCH-T38-' . $g['sz_doc_seq'];
            if ($key !== '') {
                $g['sz_created'][$key] = $number;
            }
            if ($g['sz_create_timeout']) {
                return MockTransport::timeout();
            }
            return MockTransport::response(
                200,
                '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">'
                . '<sikeres>true</sikeres><szamlaszam>' . $number . '</szamlaszam><szamlabrutto>3790</szamlabrutto></xmlszamlavalasz>'
            );
        }
        if (str_contains($body, 'xmlszamlapdf')) {
            $g['sz_pdf']++;
            if ($g['sz_pdf_timeout']) {
                return MockTransport::timeout();
            }
            return MockTransport::response(200, "%PDF-1.4\nT38 szamlazz pdf\n%%EOF\n");
        }
        if (str_contains($body, 'xmlszamlaxml')) {
            $g['sz_query']++;
            $key = $xmlValue($body, 'szamlaKulsoAzon');
            if ($key !== '' && isset($g['sz_created'][$key])) {
                $number = $g['sz_created'][$key];
                return MockTransport::response(
                    200,
                    '<?xml version="1.0" encoding="UTF-8"?><szamla xmlns="http://www.szamlazz.hu/szamla">'
                    . '<szamlaszam>' . $number . '</szamlaszam><osszegek><totalnetto>3790</totalnetto><totalbrutto>3790</totalbrutto></osszegek></szamla>'
                );
            }
            return MockTransport::response(
                200,
                '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">'
                . '<sikeres>false</sikeres><hibakod>7</hibakod><hibauzenet>nincs ilyen bizonylat</hibauzenet></xmlszamlavalasz>'
            );
        }
        return MockTransport::timeout();
    };

    $biRouter = static function ($url, $args) {
        $g = &$GLOBALS['t38'];
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $body = json_decode((string) ($args['body'] ?? ''), true);
        $body = is_array($body) ? $body : [];

        if ($method === 'POST' && preg_match('#/documents$#', $path)) {
            $g['bi_create']++;
            $id = 90000 + $g['bi_create'];
            $number = 'BILL-T38-' . $g['bi_create'];
            $g['bi_docs'][$id] = [
                'id' => $id,
                'invoice_number' => $number,
                'type' => (string) ($body['type'] ?? 'invoice'),
                'cancelled' => false,
                'gross_total' => 3790,
                'net_total' => 3790,
                'vat_amount' => 0,
                'currency' => 'HUF',
                'conversion_rate' => 1,
                'related_documents' => [],
            ];
            return MockTransport::json(200, $g['bi_docs'][$id]);
        }
        if ($method === 'POST' && str_contains($path, '/create-modification-document')) {
            $g['bi_modify']++;
            if (preg_match('#/documents/(\d+)/#', $path, $m)) {
                $origId = (int) $m[1];
            } else {
                $origId = 0;
            }
            $id = 95000 + $g['bi_modify'];
            $number = 'MOD-T38-' . $g['bi_modify'];
            $doc = [
                'id' => $id,
                'invoice_number' => $number,
                'type' => 'modification',
                'cancelled' => false,
                'gross_total' => 3790,
                'net_total' => 3790,
                'vat_amount' => 0,
                'currency' => 'HUF',
                'conversion_rate' => 1,
                'correction_type' => 'modification',
                'related_documents' => [],
            ];
            $g['bi_docs'][$id] = $doc;
            if ($origId > 0 && isset($g['bi_docs'][$origId])) {
                $g['bi_docs'][$origId]['related_documents'][] = [
                    'id' => $id,
                    'invoice_number' => $number,
                    'type' => 'modification',
                    'correction_type' => 'modification',
                ];
            }
            return MockTransport::json(200, $doc);
        }
        if ($method === 'PUT' && str_contains($path, '/cancel')) {
            $g['bi_cancel']++;
            if (preg_match('#/documents/(\d+)/#', $path, $m)) {
                $id = (int) $m[1];
            } else {
                $id = 0;
            }
            if (!isset($g['bi_docs'][$id])) {
                return MockTransport::json(404, ['message' => 'not found']);
            }
            $g['bi_docs'][$id]['cancelled'] = true;
            return MockTransport::json(200, $g['bi_docs'][$id]);
        }
        if ($method === 'DELETE' && preg_match('#/documents/(\d+)$#', $path, $m)) {
            $g['bi_delete']++;
            unset($g['bi_docs'][(int) $m[1]]);
            return MockTransport::response(204, '');
        }
        if ($method === 'GET' && str_contains($path, '/download')) {
            $g['bi_pdf']++;
            return MockTransport::response(200, "%PDF-1.4\nT38 billingo pdf\n%%EOF\n");
        }
        if ($method === 'GET' && preg_match('#/documents/(\d+)$#', $path, $m)) {
            $g['bi_get']++;
            $id = (int) $m[1];
            if (!isset($g['bi_docs'][$id])) {
                return MockTransport::json(404, ['message' => 'not found']);
            }
            return MockTransport::json(200, $g['bi_docs'][$id]);
        }
        return MockTransport::timeout();
    };

    $mock = MockTransport::make()
        ->on('szamlazz.hu/szamla', $szRouter)
        ->on('api.billingo.hu', $biRouter);
    if ($withBarion) {
        $mock->on('/v2/Payment/Refund', static function ($url, $args) {
            $g = &$GLOBALS['t38'];
            $g['barion_refund']++;
            if ($g['barion_reject']) {
                return MockTransport::json(400, ['Errors' => [['ErrorCode' => 'PaymentStatusNotValid']]]);
            }
            $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
            $amount = (int) ($body['TransactionsToRefund'][0]['AmountToRefund'] ?? 0);
            return MockTransport::json(200, [
                'PaymentId' => 'T38-PAY',
                'RefundedTransactions' => [['TransactionId' => 'T38-RF-1', 'POSTransactionId' => 'POS-1', 'Amount' => $amount]],
            ]);
        });
        $mock->on('/paymentstate', static function () {
            $GLOBALS['t38']['barion_state']++;
            return MockTransport::json(200, [
                'PaymentId' => 'T38-PAY',
                'Status' => 'Succeeded',
                'Currency' => 'HUF',
                'Transactions' => [[
                    'TransactionId' => 'T38-TXN-1', 'POSTransactionId' => 'POS-1', 'TransactionType' => 'CardPayment',
                    'Status' => 'Succeeded', 'Total' => 3790, 'Currency' => 'HUF',
                ]],
            ]);
        });
        $mock->on('api.test.barion.com', MockTransport::timeout());
    }
    $mock->install();
    $GLOBALS['t38_mocks'][] = $mock;
    return $mock;
};

// ---------------------------------------------------------------- Case 1: Számlázz storno + replay
$case = ['name' => 'szamlazz_storno_success_and_replay'];
try {
    $oid = 998001;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    $issued = (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');
    $case['issued'] = ($issued['status'] ?? '') === 'issued' && $invoice !== null && (string) $invoice->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $snapshot = json_decode((string) ($invoice->vat_snapshot ?? ''), true);
    $case['snapshot_has_lines_buyer'] = is_array($snapshot) && !empty($snapshot['lines']) && isset($snapshot['buyer']);

    $request = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 teszt sztornó']);
    $corrections = $correctionRows($oid);
    $correction = $corrections[0] ?? null;
    $case['storno_confirmed'] = ($request['status'] ?? '') === 'confirmed';
    $case['storno_row_linked'] = $correction !== null
        && (int) $correction->storno_of_document_id === (int) $invoice->id
        && (string) $correction->provider === 'szamlazz'
        && (string) $correction->doc_role === 'storno_invoice'
        && (string) $correction->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['original_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoice->id)->state === ExternalOperation::SUPERSEDED;
    $case['pdf_stored'] = $correction !== null && !empty($correction->pdf_ref);
    $case['one_storno_dispatch'] = $delta($base)['sz_storno'] === 1;

    // Replay: same intent again -> no second provider call, same correction row.
    $replay = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 teszt sztornó']);
    $case['replay_no_dispatch'] = ($replay['status'] ?? '') === 'already_confirmed' && $delta($base)['sz_storno'] === 1;
    $case['still_one_row'] = count($correctionRows($oid)) === 1;

    $case['debug'] = ['issued' => $issued, 'request' => $request, 'replay' => $replay, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['issued'], $case['snapshot_has_lines_buyer'], $case['storno_confirmed'], $case['storno_row_linked'], $case['original_superseded'], $case['pdf_stored'], $case['one_storno_dispatch'], $case['replay_no_dispatch'], $case['still_one_row']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 2: late paid/label after storno -> no new invoice
$case = ['name' => 'late_event_after_storno_no_new_invoice'];
try {
    $oid = 998001; // continues case 1: the invoice of this order is SUPERSEDED
    $base = $counters();
    $before = count($docs($oid));
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'billingo'); // merchant switched after the storno

    // Direct late trigger + the real event path (job key dedupes; both must be inert).
    $sz = (new SzamlazzInvoiceService())->issue($oid, 1);
    $bi = (new BillingoInvoiceService())->issue($oid, 1);
    (new \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzModule())->onOrderPaidDone(['order' => (object) ['id' => $oid]]);
    (new \FluentCartHungary\Modules\Billing\Billingo\BillingoModule())->onOrderPaidDone(['order' => (object) ['id' => $oid]]);
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fch_jobs SET available_at = UTC_TIMESTAMP() WHERE state = 'PENDING' AND job_key LIKE %s", '%' . $oid . '%'));
    for ($i = 0; $i < 4; $i++) {
        if (JobRunner::instance()->runDue(50) === 0) {
            break;
        }
    }
    $case['sz_skip'] = in_array($sz['status'] ?? '', ['not_retryable', 'skipped'], true);
    $case['bi_skip'] = ($bi['status'] ?? '') === 'skipped' && ($bi['reason'] ?? '') === 'prior_document_superseded';
    $case['no_new_create'] = $delta($base)['sz_create'] === 0 && $delta($base)['bi_create'] === 0;
    $case['no_new_docs'] = count($docs($oid)) === $before;
    $case['debug'] = ['sz' => $sz, 'bi' => $bi, 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $case['ok'] = !in_array(false, [$case['sz_skip'], $case['bi_skip'], $case['no_new_create'], $case['no_new_docs']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 3: provider switch -> correction follows the original binding
$case = ['name' => 'provider_switch_original_binding'];
try {
    $oid = 998002;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    $settings->set('billing', 'selected_invoicer', 'billingo'); // switch AFTER the invoice exists
    $request = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 provider váltás']);
    $correction = $correctionRows($oid)[0] ?? null;
    $case['confirmed'] = ($request['status'] ?? '') === 'confirmed';
    $case['went_to_szamlazz'] = $delta($base)['sz_storno'] === 1 && $delta($base)['bi_cancel'] === 0;
    $case['binding_preserved'] = $correction !== null
        && (string) $correction->provider === 'szamlazz'
        && (string) $correction->mode === (string) $invoice->mode
        && (string) $correction->account_id === (string) $invoice->account_id;
    $case['debug'] = ['request' => $request, 'correction' => $correction === null ? null : [(string) $correction->provider, (string) $correction->mode, (string) $correction->account_id], 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $case['ok'] = !in_array(false, [$case['confirmed'], $case['went_to_szamlazz'], $case['binding_preserved']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 4: lost storno response -> read-back, no re-dispatch
$case = ['name' => 'lost_response_readback'];
try {
    $oid = 998003;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    $GLOBALS['t38']['sz_storno_timeout'] = true; // provider creates it, the response is lost
    $request = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 válaszelvesztés']);
    $correction = $correctionRows($oid)[0] ?? null;
    $case['uncertain'] = ($request['status'] ?? '') === 'uncertain';
    $case['row_uncertain'] = $correction !== null && (string) $correction->state === ExternalOperation::UNCERTAIN;
    $case['original_untouched'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoice->id)->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['only_one_dispatch'] = $delta($base)['sz_storno'] === 1;

    // Operator read-back: query by OUR external key proves the storno; nothing is re-sent.
    $GLOBALS['t38']['sz_storno_timeout'] = false;
    $state = (new InvoiceCorrectionService())->reconcileNow((int) $correction->operation_id);
    $fresh = (new InvoiceDocumentRepository())->getById((int) $correction->id);
    $case['reconciled_confirmed'] = $state === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $fresh->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['original_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoice->id)->state === ExternalOperation::SUPERSEDED;
    $case['no_second_dispatch'] = $delta($base)['sz_storno'] === 1 && $delta($base)['sz_query'] === 1;
    $case['debug'] = ['request' => $request, 'readback_state' => $state, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['uncertain'], $case['row_uncertain'], $case['original_untouched'], $case['only_one_dispatch'], $case['reconciled_confirmed'], $case['original_superseded'], $case['no_second_dispatch']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 5: PDF failure -> retry only the PDF
$case = ['name' => 'pdf_failure_retry_only'];
try {
    $oid = 998004;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    $GLOBALS['t38']['sz_pdf_timeout'] = true;
    $request = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 PDF hiba']);
    $correction = $correctionRows($oid)[0] ?? null;
    $case['confirmed_without_pdf'] = ($request['status'] ?? '') === 'confirmed'
        && $correction !== null
        && (string) $correction->state === ExternalOperation::EXTERNAL_CONFIRMED
        && empty($correction->pdf_ref);

    $GLOBALS['t38']['sz_pdf_timeout'] = false;
    $stornoBefore = $GLOBALS['t38']['sz_storno'];
    $retry = (new SzamlazzCorrectionService())->retryPdf((int) $correction->id);
    $fresh = (new InvoiceDocumentRepository())->getById((int) $correction->id);
    $case['pdf_stored'] = ($retry['status'] ?? '') === 'stored' && !empty($fresh->pdf_ref);
    $case['no_new_document'] = $GLOBALS['t38']['sz_storno'] === $stornoBefore;
    $case['debug'] = ['request' => $request, 'retry' => $retry];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['confirmed_without_pdf'], $case['pdf_stored'], $case['no_new_document']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 6: Számlázz corrected invoice (helyesbítő)
$case = ['name' => 'szamlazz_corrected_document'];
try {
    $oid = 998012;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    $request = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::CORRECTED, ['reason' => 'T38 helyesbítő (vevőadat)']);
    $correction = $correctionRows($oid)[0] ?? null;
    $case['confirmed'] = ($request['status'] ?? '') === 'confirmed';
    $case['corrective_xml'] = str_contains($GLOBALS['t38']['sz_last_create_body'], '<helyesbitoszamla>true</helyesbitoszamla>')
        && str_contains($GLOBALS['t38']['sz_last_create_body'], '<helyesbitettSzamlaszam>' . (string) $invoice->document_number . '</helyesbitettSzamlaszam>');
    $case['row_linked'] = $correction !== null
        && (string) $correction->doc_role === 'corrected_invoice'
        && (int) $correction->storno_of_document_id === (int) $invoice->id
        && (string) $correction->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['original_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoice->id)->state === ExternalOperation::SUPERSEDED;
    $case['one_create'] = $delta($base)['sz_create'] === 2; // original + corrective
    $case['debug'] = ['request' => $request, 'has_corrective_tag' => str_contains($GLOBALS['t38']['sz_last_create_body'], 'helyesbitoszamla')];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['confirmed'], $case['corrective_xml'], $case['row_linked'], $case['original_superseded'], $case['one_create']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 7: Billingo modification + cancel
$case = ['name' => 'billingo_modification_and_cancel'];
try {
    $oidMod = 998005;
    $oidCancel = 998006;
    $base = $counters();
    $createOrder($oidMod, 379000, 'barion', true);
    $createOrder($oidCancel, 379000, 'barion', true);
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'billingo');

    (new BillingoInvoiceService())->issue($oidMod, 1);
    $invoiceMod = $docByRole($oidMod, 'invoice');
    $requestMod = (new InvoiceCorrectionService())->request((int) $invoiceMod->id, InvoiceCorrectionService::CORRECTED, ['reason' => 'T38 módosító']);
    $correctionMod = $correctionRows($oidMod)[0] ?? null;
    $case['modification_confirmed'] = ($requestMod['status'] ?? '') === 'confirmed'
        && $correctionMod !== null
        && (string) $correctionMod->doc_role === 'corrected_invoice'
        && (string) $correctionMod->state === ExternalOperation::EXTERNAL_CONFIRMED
        && str_starts_with((string) $correctionMod->document_number, 'MOD-T38-');
    $case['modification_call'] = $delta($base)['bi_modify'] === 1;
    $case['modification_original_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoiceMod->id)->state === ExternalOperation::SUPERSEDED;

    (new BillingoInvoiceService())->issue($oidCancel, 1);
    $invoiceCancel = $docByRole($oidCancel, 'invoice');
    $requestCancel = (new InvoiceCorrectionService())->request((int) $invoiceCancel->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 sztornó']);
    $correctionCancel = $correctionRows($oidCancel)[0] ?? null;
    $case['cancel_confirmed'] = ($requestCancel['status'] ?? '') === 'confirmed'
        && $correctionCancel !== null
        && (string) $correctionCancel->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['cancel_call'] = $delta($base)['bi_cancel'] === 1;
    $case['cancel_original_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoiceCancel->id)->state === ExternalOperation::SUPERSEDED;
    $case['debug'] = ['mod' => $requestMod, 'cancel' => $requestCancel, 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $case['ok'] = !in_array(false, [$case['modification_confirmed'], $case['modification_call'], $case['modification_original_superseded'], $case['cancel_confirmed'], $case['cancel_call'], $case['cancel_original_superseded']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 8: Billingo draft delete + capability blocks
$case = ['name' => 'billingo_draft_delete_and_blocks'];
try {
    $oid = 998007;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'billingo');

    (new BillingoInvoiceService())->issue($oid, 1, 'draft');
    $draft = $docByRole($oid, 'draft');
    $case['draft_issued'] = $draft !== null && (string) $draft->state === ExternalOperation::EXTERNAL_CONFIRMED;

    // Documented-capability fence: a draft has no corrected/modification route here.
    $blocked = (new InvoiceCorrectionService())->request((int) $draft->id, InvoiceCorrectionService::CORRECTED, ['reason' => 'T38 nem támogatott']);
    $case['corrected_blocked'] = ($blocked['status'] ?? '') === 'blocked' && str_starts_with((string) ($blocked['reason'] ?? ''), 'document_type_not_supported');

    $request = (new InvoiceCorrectionService())->request((int) $draft->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 piszkozat törlés']);
    $correction = $correctionRows($oid)[0] ?? null;
    $case['delete_confirmed'] = ($request['status'] ?? '') === 'confirmed'
        && $correction !== null
        && (string) $correction->provider_doc_type === 'draft_deleted'
        && (string) $correction->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['delete_call'] = $delta($base)['bi_delete'] === 1 && $delta($base)['bi_cancel'] === 0;
    $case['draft_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $draft->id)->state === ExternalOperation::SUPERSEDED;
    $case['debug'] = ['blocked' => $blocked, 'request' => $request, 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $case['ok'] = !in_array(false, [$case['draft_issued'], $case['corrected_blocked'], $case['delete_confirmed'], $case['delete_call'], $case['draft_superseded']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 9: COD before_dispatch unpaid -> storno, no gateway refund
$case = ['name' => 'cod_before_dispatch_no_refund'];
try {
    $oid = 998008;
    $base = $counters();
    $createOrder($oid, 379000, 'offline_payment', false);
    $mock = $installMocks(true);
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $settings->set('billing', 'cod_invoice_event', 'before_dispatch');

    $issued = (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');
    $case['cod_invoiced_unpaid'] = ($issued['status'] ?? '') === 'issued' && $invoice !== null
        && (string) $invoice->state === ExternalOperation::EXTERNAL_CONFIRMED;

    $request = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 COD lemondás']);
    $case['storno_confirmed'] = ($request['status'] ?? '') === 'confirmed';
    $case['no_refund_dispatch'] = $delta($base)['barion_refund'] === 0;
    $case['no_refund_ops'] = count($refundOps($oid)) === 0;
    $order = \FluentCart\App\Models\Order::find($oid);
    $case['still_unpaid'] = $order !== null && (string) $order->payment_status === 'pending';
    $case['debug'] = ['issued' => $issued, 'request' => $request, 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'cod_invoice_event', 'payment');
    $case['ok'] = !in_array(false, [$case['cod_invoiced_unpaid'], $case['storno_confirmed'], $case['no_refund_dispatch'], $case['no_refund_ops'], $case['still_unpaid']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 10: full refund -> auto storno; refund success + correction failure -> resume only the correction
$case = ['name' => 'full_refund_auto_storno_component_recovery'];
try {
    $oid = 998009;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks(true);
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $settings->set('billing', 'refund_correction_policy', 'storno_on_full_refund');
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    // Force the correction component to fail locally: remove the (original-binding) agent key.
    $creds = new CredentialStore();
    $creds->delete('szamlazz', 'test', 'default', 'agent_key');

    $refundResult = $refundViaFc($oid, 379000, 't38-c10');
    $refundOpsRow = $refundOps($oid);
    $corrections = $correctionRows($oid);
    $correction = $corrections[0] ?? null;
    $case['refund_confirmed'] = is_array($refundResult);
    $case['refund_op_confirmed'] = count($refundOpsRow) === 1 && (string) $refundOpsRow[0]->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['correction_local_error'] = $correction !== null
        && (string) $correction->state === ExternalOperation::NOT_STARTED
        && (string) $correction->storno_of_document_id === (string) $invoice->id;
    $case['no_storno_yet'] = $delta($base)['sz_storno'] === 0;
    $txns = $refundTxns($oid);
    $case['fc_txn_refunded'] = count($txns) === 1 && (string) $txns[0]->status === 'refunded';

    // Recovery: the operator fixes the credential and resumes the SAME correction; the refund is not repeated.
    $creds->set('szamlazz', 'test', 'default', 'agent_key', 'T38-SZ-AGENT-KEY');
    $resume = (new InvoiceCorrectionService())->resume((int) $correction->id);
    $fresh = (new InvoiceDocumentRepository())->getById((int) $correction->id);
    $case['resume_confirmed'] = ($resume['status'] ?? '') === 'confirmed' && (string) $fresh->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['exactly_one_storno'] = $delta($base)['sz_storno'] === 1;
    $case['refund_not_repeated'] = $delta($base)['barion_refund'] === 1 && count($refundOps($oid)) === 1
        && (string) $refundOps($oid)[0]->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['original_superseded'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoice->id)->state === ExternalOperation::SUPERSEDED;
    $case['debug'] = ['refund' => is_wp_error($refundResult) ? $refundResult->get_error_code() : 'array', 'resume' => $resume, 'delta' => $delta($base), 'corrections' => array_map(static fn($c) => [(int) $c->id, (string) $c->state], $corrections)];
    $mock->uninstall();
    $settings->set('billing', 'refund_correction_policy', 'off');
    $case['ok'] = !in_array(false, [$case['refund_confirmed'], $case['refund_op_confirmed'], $case['correction_local_error'], $case['no_storno_yet'], $case['fc_txn_refunded'], $case['resume_confirmed'], $case['exactly_one_storno'], $case['refund_not_repeated'], $case['original_superseded']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 11: partial refund never auto-stornos
$case = ['name' => 'partial_refund_no_auto_storno'];
try {
    $oid = 998010;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks(true);
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $settings->set('billing', 'refund_correction_policy', 'storno_on_full_refund');
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    $refundResult = $refundViaFc($oid, 100000, 't38-c11');
    $case['refund_confirmed'] = is_array($refundResult);
    $case['no_correction'] = count($correctionRows($oid)) === 0;
    $case['no_storno_dispatch'] = $delta($base)['sz_storno'] === 0;
    $case['original_confirmed'] = (string) (new InvoiceDocumentRepository())->getById((int) $invoice->id)->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['debug'] = ['refund' => is_wp_error($refundResult) ? $refundResult->get_error_code() : 'array', 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'refund_correction_policy', 'off');
    $case['ok'] = !in_array(false, [$case['refund_confirmed'], $case['no_correction'], $case['no_storno_dispatch'], $case['original_confirmed']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case 12: confirmed correction + failed refund -> no repeated document op
$case = ['name' => 'correction_confirmed_failed_refund_no_repeat'];
try {
    $oid = 998011;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks(true);
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $settings->set('billing', 'refund_correction_policy', 'storno_on_full_refund');
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = $docByRole($oid, 'invoice');

    $manual = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T38 előzetes sztornó']);
    $correction = $correctionRows($oid)[0] ?? null;
    $case['correction_confirmed'] = ($manual['status'] ?? '') === 'confirmed';

    $GLOBALS['t38']['barion_reject'] = true; // the refund fails AFTER the correction is already proved
    $refundResult = $refundViaFc($oid, 379000, 't38-c12');
    $ops = $refundOps($oid);
    $txns = $refundTxns($oid);
    $case['refund_rejected'] = is_wp_error($refundResult)
        && count($ops) === 1
        && (string) $ops[0]->state === ExternalOperation::PROVEN_REJECTED
        && count($txns) === 1
        && (string) $txns[0]->status === 'failed';
    $case['correction_unchanged'] = (string) (new InvoiceDocumentRepository())->getById((int) $correction->id)->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['no_document_repeat'] = $delta($base)['sz_storno'] === 1 && count($correctionRows($oid)) === 1;
    $case['debug'] = ['refund' => is_wp_error($refundResult) ? $refundResult->get_error_code() : 'array', 'delta' => $delta($base)];
    $mock->uninstall();
    $settings->set('billing', 'refund_correction_policy', 'off');
    $case['ok'] = !in_array(false, [$case['correction_confirmed'], $case['refund_rejected'], $case['correction_unchanged'], $case['no_document_repeat']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- summary
$out['ok'] = $out['checks']['modules_booted']
    && !in_array(false, $out['checks']['correction_seams'], true)
    && $out['errors'] === [];
echo wp_json_encode($out) . "\n";
