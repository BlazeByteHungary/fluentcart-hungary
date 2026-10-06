<?php
/**
 * T40 — W4 crossed-lifecycle verification (mock-only, real plugin boot).
 *
 * Consolidated cases mandated by the W4 logistics/close-out goal that are NOT already covered one-to-one
 * by T37 (refund_flow), T38 (invoice_correction_flow) and T39 (logistics_lifecycle):
 *   A. partial -> partial -> full refund cascade with the correct remaining balance, then an over-refund
 *      request rejected with zero provider calls;
 *   B. correction already PROVED + refund UNCERTAIN -> no second document and no new refund dispatch
 *      (cross-ref: T38 #10 covers the reverse component recovery);
 *   C. unpaid COD order with a separately trackable invoice lifecycle and shipment lifecycle:
 *      storno + cancellation with ZERO gateway money movement;
 *   D. late paid/label event after the document chain and shipment were closed -> no silent new invoice /
 *      label / refund history loss.
 *
 * Every provider host is mocked with a catch-all timeout and per-call counters. Prints one JSON line.
 */

use FluentCartHungary\Core\Billing\InvoiceCorrectionService;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\ShipmentCancellationService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaClaimService;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaPacketService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'cases' => [], 'errors' => []];
if (!defined('FCH_CREDENTIALS_KEY')) {
    define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
}
global $wpdb;

foreach (range(998301, 998310) as $oid) {
    foreach ([
        "DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d",
    ] as $sql) {
        $wpdb->query($wpdb->prepare($sql, $oid));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s", '%' . $oid . '%'));
}
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_transactions WHERE order_id BETWEEN 998301 AND 998310");

$settings = new ModuleSettings();
$settings->setMode('test');
$settings->set('billing', 'selected_invoicer', 'szamlazz');
$settings->set('billing', 'cod_invoice_event', 'before_dispatch');
$settings->set('billing', 'refund_correction_policy', 'off');
$settings->set('szamlazz', 'account_id', 'default');
$settings->set('szamlazz', 'amount_basis', 'auto');
$settings->set('packeta', 'account_id', 'default');
$settings->set('packeta', 'eshop', 't40-lab');
$creds = new CredentialStore();
$creds->set('szamlazz', 'test', 'default', 'agent_key', 'T40-SZ-KEY');
$creds->set('packeta', 'test', 'default', 'api_password', 'T40-PACKETA-KEY');
$creds->set('barion', 'test', 'default', 'pos_key', 'T40-BARION-POS-KEY');

$GLOBALS['t40'] = [
    'sz_create' => 0, 'sz_storno' => 0, 'sz_pdf' => 0, 'sz_seq' => 600,
    'pk_create' => 0, 'pk_label' => 0, 'pk_status' => 0, 'pk_cancel' => 0, 'pk_seq' => 700,
    'barion_refund' => 0, 'barion_state' => 0,
    'sz_storno_timeout' => false, 'barion_refund_timeout' => false,
    'pk_cancelled' => false,
];

$createOrder = static function (int $orderId, int $cents, string $method = 'barion', bool $paid = true, bool $withAddress = false, bool $withChargeLedger = true): void {
    $orderClass = 'FluentCart\App\Models\Order';
    $itemClass = 'FluentCart\App\Models\OrderItem';
(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94105, ['carrier' => 'packeta', 'service' => 'home']); // 3/3 routing gate binding
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
        'config' => ['shipping_method_id' => 94105, 'shipping_method_title' => 'Lab'],
    ]);
    global $wpdb;
    $actual = (int) $order->id;
    if ($actual !== $orderId) {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_order_items SET order_id = %d WHERE order_id = %d", $orderId, $actual));
    }
    $itemClass::create([
        'order_id' => $orderId, 'title' => 'T40 tétel', 'quantity' => 1, 'unit_price' => $cents,
        'subtotal' => $cents, 'tax_amount' => 0, 'discount_total' => 0, 'line_total' => $cents,
        'rate' => 0, 'payment_type' => 'onetime', 'fulfillment_type' => 'physical',
    ]);
    if ($withAddress && class_exists('FluentCart\App\Models\OrderAddress')) {
        'FluentCart\App\Models\OrderAddress'::create([
            'order_id' => $orderId, 'type' => 'shipping', 'name' => 'T40 Vevő', 'address_1' => 'Kossuth tér 1',
            'city' => 'Budapest', 'state' => '', 'postcode' => '1055', 'country' => 'HU',
            'meta' => ['phone' => '+36302223334', 'email' => 't40@example.test', 'other_data' => ['phone' => '+36302223334', 'email' => 't40@example.test']],
        ]);
    }
    if (!$paid) {
        return;
    }
    'FluentCart\App\Models\OrderTransaction'::create([
        'order_id' => $orderId, 'transaction_type' => 'charge', 'status' => 'succeeded', 'total' => $cents,
        'currency' => 'HUF', 'payment_method' => $method, 'payment_mode' => 'test',
        'vendor_charge_id' => 'T40-' . $orderId, 'meta' => [],
    ]);
    if ($method === 'barion' && $withChargeLedger) {
        $ledger = new LedgerRepository();
        $claim = $ledger->claim([
            'idempotency_key' => 'fch:' . $orderId . ':payment_start:1', 'module' => 'payments', 'provider' => 'barion',
            'kind' => 'payment_start', 'mode' => 'test', 'account_id' => 'default', 'order_id' => $orderId,
            'currency' => 'HUF', 'amount_minor' => (int) round($cents / 100), 'business_reference' => (string) $orderId,
        ]);
        $ledger->transition((int) $claim['row']->id, ExternalOperation::IN_PROGRESS, [], ExternalOperation::NOT_STARTED);
        $ledger->transition((int) $claim['row']->id, ExternalOperation::EXTERNAL_CONFIRMED, ['external_id' => 'T40-' . $orderId], ExternalOperation::IN_PROGRESS);
    }
};

$installMocks = static function (): MockTransport {
    foreach ((array) ($GLOBALS['t40_mocks'] ?? []) as $old) {
        if ($old instanceof MockTransport) {
            $old->uninstall();
        }
    }
    $GLOBALS['t40_mocks'] = [];

    $sz = static function ($url, $args) {
        $g = &$GLOBALS['t40'];
        $body = (string) ($args['body'] ?? '');
        $val = static function (string $body, string $tag): string {
            return preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/', $body, $m) ? trim($m[1]) : '';
        };
        if (str_contains($body, 'action-szamla_agent_st')) {
            $g['sz_storno']++;
            $g['sz_seq']++;
            if ($g['sz_storno_timeout']) {
                return MockTransport::timeout();
            }
            return MockTransport::response(200, '<xmlszamlavalasz><sikeres>true</sikeres><szamlaszam>ST-T40-' . $g['sz_seq'] . '</szamlaszam></xmlszamlavalasz>');
        }
        if (str_contains($body, 'action-xmlagentxmlfile')) {
            $g['sz_create']++;
            $g['sz_seq']++;
            return MockTransport::response(200, '<xmlszamlavalasz><sikeres>true</sikeres><szamlaszam>FCH-T40-' . $g['sz_seq'] . '</szamlaszam><szamlabrutto>3790</szamlabrutto></xmlszamlavalasz>');
        }
        if (str_contains($body, 'xmlszamlapdf')) {
            $g['sz_pdf']++;
            return MockTransport::response(200, "%PDF-1.4\nT40 pdf\n%%EOF\n");
        }
        if (str_contains($body, 'xmlszamlaxml')) {
            return MockTransport::response(200, '<xmlszamlavalasz><sikeres>false</sikeres><hibakod>7</hibakod></xmlszamlavalasz>');
        }
        return MockTransport::timeout();
    };

    $pk = static function ($url, $args) {
        $g = &$GLOBALS['t40'];
        $body = (string) ($args['body'] ?? '');
        $val = static function (string $body, string $tag): string {
            return preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/', $body, $m) ? trim($m[1]) : '';
        };
        if (str_contains($body, '<createPacket>')) {
            $g['pk_create']++;
            $g['pk_seq']++;
            $id = 'T40PK' . $g['pk_seq'];
            return MockTransport::response(200, '<response><status>ok</status><result><id>' . $id . '</id><barcode>' . $id . '</barcode><number>'
                . $val($body, 'number') . '</number><barcodeText>' . $id . '</barcodeText></result></response>');
        }
        if (str_contains($body, '<packetLabelPdf>')) {
            $g['pk_label']++;
            return MockTransport::response(200, '<response><status>ok</status><result><packetLabelPdfResult>' . base64_encode('%PDF-1.4 T40 label') . '</packetLabelPdfResult></result></response>');
        }
        if (str_contains($body, '<packetStatus>')) {
            $g['pk_status']++;
            return MockTransport::response(200, '<response><status>ok</status><result><statusCode>' . ($g['pk_cancelled'] ? 11 : 5) . '</statusCode></result></response>');
        }
        if (str_contains($body, '<cancelPacket>')) {
            $g['pk_cancel']++;
            $g['pk_cancelled'] = true;
            return MockTransport::response(200, '<response><status>ok</status><result><cancelPacketResult>true</cancelPacketResult></result></response>');
        }
        return MockTransport::timeout();
    };

    $barion = static function ($url, $args) {
        $g = &$GLOBALS['t40'];
        if (str_contains((string) $url, '/v2/Payment/Refund')) {
            $g['barion_refund']++;
            if ($g['barion_refund_timeout']) {
                return MockTransport::timeout();
            }
            $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
            return MockTransport::json(200, [
                'PaymentId' => 'T40-PAY',
                'RefundedTransactions' => [[
                    'TransactionId' => 'T40-RF',
                    'POSTransactionId' => 'POS-1',
                    'Amount' => (int) ($body['TransactionsToRefund'][0]['AmountToRefund'] ?? 0),
                ]],
            ]);
        }
        if (str_contains((string) $url, '/paymentstate')) {
            $g['barion_state']++;
            return MockTransport::json(200, [
                'PaymentId' => 'T40-PAY', 'Status' => 'Succeeded', 'Currency' => 'HUF',
                'Transactions' => [[
                    'TransactionId' => 'T40-TXN-1', 'POSTransactionId' => 'POS-1', 'TransactionType' => 'CardPayment',
                    'Status' => 'Succeeded', 'Total' => 3790, 'Currency' => 'HUF',
                ]],
            ]);
        }
        return MockTransport::timeout();
    };

    $mock = MockTransport::make()
        ->on('szamlazz.hu/szamla', $sz)
        ->on('zasilkovna.cz', $pk)
        ->on('api.test.barion.com', $barion);
    // Barion live/test hosts are both mocked; add a production-host catch-all so nothing can escape.
    $mock->on('api.barion.com', MockTransport::timeout());
    $mock->install();
    $GLOBALS['t40_mocks'][] = $mock;
    return $mock;
};

$refundViaFc = static function (int $orderId, int $cents, ?string $token) {
    $txn = 'FluentCart\App\Models\OrderTransaction'::query()
        ->where('order_id', $orderId)->where('transaction_type', 'charge')->orderBy('id', 'desc')->first();
    if ($txn === null) {
        return new \WP_Error('no_charge', 'no charge');
    }
    $args = ['reason' => 't40'];
    if ($token !== null) {
        $args['fch_refund_intent'] = $token;
    }
    $refundClass = 'FluentCart\\App\\Services\\Payments\\Refund';
    $result = (new $refundClass())->processRefund($txn, $cents, $args);
    if (is_array($result) && ($result['vendor_refund_id'] ?? null) instanceof \WP_Error) {
        return $result['vendor_refund_id'];
    }
    return $result;
};

$refundOps = static function (int $orderId): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' ORDER BY id ASC",
        $orderId
    ));
    return is_array($rows) ? $rows : [];
};

$chargeTxnId = static function (int $orderId): int {
    $txn = 'FluentCart\App\Models\OrderTransaction'::query()
        ->where('order_id', $orderId)->where('transaction_type', 'charge')->orderBy('id', 'desc')->first();
    return $txn === null ? 0 : (int) $txn->id;
};

$refundSpec = static function (int $orderId, int $amountMinor, ?string $intent) use ($chargeTxnId): array {
    return [
        'order_id' => $orderId,
        'parent_txn_id' => $chargeTxnId($orderId),
        'amount_minor' => $amountMinor,
        'currency' => 'HUF',
        'provider' => 'barion',
        'mode' => 'test',
        'account_id' => 'default',
        'intent_token' => (string) ($intent ?? ''),
    ];
};

$counterDelta = static function (array $before): array {
    $after = $GLOBALS['t40'];
    $diff = [];
    foreach (['sz_create', 'sz_storno', 'sz_pdf', 'pk_create', 'pk_label', 'pk_status', 'pk_cancel', 'barion_refund', 'barion_state'] as $k) {
        $diff[$k] = (int) ($after[$k] ?? 0) - (int) ($before[$k] ?? 0);
    }
    return $diff;
};

// ---------------------------------------------------------------- A: partial -> partial -> full cascade
$case = ['name' => 'partial_partial_full_cascade'];
try {
    $oid = 998301;
    $base = $GLOBALS['t40'];
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    $refunds = new \FluentCartHungary\Core\Payments\RefundService();
    $r1 = $refunds->request($refundSpec($oid, 1000, 't40-a1'));
    $r2 = $refunds->request($refundSpec($oid, 1000, 't40-a2'));
    $r3 = $refunds->request($refundSpec($oid, 1790, 't40-a3'));
    $over = $refunds->request($refundSpec($oid, 1000, 't40-a4'));
    $ops = $refundOps($oid);
    $case['three_confirmed'] = count($ops) === 3
        && count(array_filter($ops, static fn($o): bool => (string) $o->state === ExternalOperation::EXTERNAL_CONFIRMED)) === 3;
    $case['three_dispatches'] = $counterDelta($base)['barion_refund'] === 3;
    $case['over_refund_rejected'] = !is_array($over)
        || (string) ($over['outcome'] ?? ($over['status'] ?? '')) === 'rejected'
        || in_array((string) ($over['reason'] ?? ''), ['EXCEEDS_REFUNDABLE', 'NOTHING_REFUNDABLE'], true);
    $case['no_extra_call'] = $counterDelta($base)['barion_refund'] === 3;
    $case['debug'] = ['r1' => $r1, 'r3' => $r3, 'over' => $over, 'delta' => $counterDelta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['three_confirmed'], $case['three_dispatches'], $case['over_refund_rejected'], $case['no_extra_call']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- B: correction proved + refund uncertain -> no repeats
$case = ['name' => 'correction_proved_refund_uncertain_no_repeat'];
try {
    $oid = 998302;
    $base = $GLOBALS['t40'];
    $createOrder($oid, 379000, 'barion', true);
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = null;
    foreach ((new \FluentCartHungary\Core\Ledger\InvoiceDocumentRepository())->listForOrder($oid) as $row) {
        if ((string) $row->doc_role === 'invoice') {
            $invoice = $row;
        }
    }
    $storno = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T40 B']);
    $case['correction_confirmed'] = ($storno['status'] ?? '') === 'confirmed';

    $GLOBALS['t40']['barion_refund_timeout'] = true; // refund uncertain; paymentstate shows no refund
    $refund = $refundViaFc($oid, 379000, 't40-b');
    $ops = $refundOps($oid);
    $case['refund_uncertain'] = count($ops) === 1 && (string) $ops[0]->state === ExternalOperation::UNCERTAIN;
    $case['one_refund_call'] = $counterDelta($base)['barion_refund'] === 1;

    // Retried intent: replay, no second dispatch; the document side is untouched.
    $retry = (new \FluentCartHungary\Core\Payments\RefundService())->request($refundSpec($oid, 379000, 't40-b'));
    $case['retry_replays'] = !is_array($retry) || !isset($retry['status']) || in_array((string) ($retry['status'] ?? ''), ['in_flight', 'uncertain'], true);
    $case['still_one_refund_call'] = $counterDelta($base)['barion_refund'] === 1;
    $case['one_storno_only'] = $counterDelta($base)['sz_storno'] === 1
        && count(array_filter(
            (new \FluentCartHungary\Core\Ledger\InvoiceDocumentRepository())->listForOrder($oid),
            static fn($r): bool => (string) $r->doc_role === 'storno_invoice'
        )) === 1;
    $case['debug'] = ['storno' => $storno, 'refund' => is_wp_error($refund) ? $refund->get_error_code() : $refund, 'retry' => $retry, 'delta' => $counterDelta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['correction_confirmed'], $case['refund_uncertain'], $case['one_refund_call'], $case['retry_replays'], $case['still_one_refund_call'], $case['one_storno_only']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- C: unpaid COD, separate lifecycles, zero money
$case = ['name' => 'unpaid_cod_separate_lifecycles'];
$mock = null;
try {
    $oid = 998303;
    $base = $GLOBALS['t40'];
    $createOrder($oid, 379000, 'offline_payment', false, true);
    $mock = $installMocks();
    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
    $settings->set('billing', 'cod_invoice_event', 'before_dispatch');

    $issued = (new SzamlazzInvoiceService())->issue($oid, 1);
    $invoice = null;
    foreach ((new \FluentCartHungary\Core\Ledger\InvoiceDocumentRepository())->listForOrder($oid) as $row) {
        if ((string) $row->doc_role === 'invoice') {
            $invoice = $row;
        }
    }
    $case['invoice_issued_unpaid'] = ($issued['status'] ?? '') === 'issued' && $invoice !== null;

    $packet = (new PacketaPacketService())->createForOrder($oid, 1);
    $shipment = (new \FluentCartHungary\Core\Ledger\ShipmentRepository())->listForOrder($oid)[0] ?? null;
    $case['shipment_created'] = in_array((string) ($packet['status'] ?? ''), ['created', 'confirmed'], true)
        && $shipment !== null && (string) $shipment->state === ExternalOperation::EXTERNAL_CONFIRMED;

    $storno = (new InvoiceCorrectionService())->request((int) $invoice->id, InvoiceCorrectionService::STORNO, ['reason' => 'T40 C lemondás']);
    $cancel = (new ShipmentCancellationService())->request((int) $shipment->id);
    $case['invoice_storno_confirmed'] = ($storno['status'] ?? '') === 'confirmed';
    $case['shipment_cancel_confirmed'] = in_array((string) ($cancel['status'] ?? ''), ['cancelled_externally', 'cancelled_local'], true);

    $order = 'FluentCart\App\Models\Order'::find($oid);
    $case['no_money_movement'] = $counterDelta($base)['barion_refund'] === 0 && $counterDelta($base)['barion_state'] === 0
        && count($refundOps($oid)) === 0;
    $case['order_still_unpaid'] = is_object($order) && (string) $order->payment_status === 'pending';
    $case['debug'] = ['issued' => $issued, 'packet' => $packet, 'storno' => $storno, 'cancel' => $cancel, 'delta' => $counterDelta($base)];
    $case['ok'] = !in_array(false, [$case['invoice_issued_unpaid'], $case['shipment_created'], $case['invoice_storno_confirmed'], $case['shipment_cancel_confirmed'], $case['no_money_movement'], $case['order_still_unpaid']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- D: late paid/label after the closed chain
$case = ['name' => 'late_events_after_closed_chain'];
try {
    $oid = 998303;
    $base = $GLOBALS['t40'];
    $before = $counterDelta($base);
    $docsBefore = count((new \FluentCartHungary\Core\Ledger\InvoiceDocumentRepository())->listForOrder($oid));
    $shipmentsBefore = count((new \FluentCartHungary\Core\Ledger\ShipmentRepository())->listForOrder($oid));

    $settings = new ModuleSettings();
    $settings->set('billing', 'selected_invoicer', 'billingo');
    $szLate = (new SzamlazzInvoiceService())->issue($oid, 1);
    $billingoLate = (new \FluentCartHungary\Modules\Billing\Billingo\BillingoInvoiceService())->issue($oid, 1);
    (new \FluentCartHungary\Modules\Shipping\Packeta\PacketaModule())->onOrderPaidDone(['order' => (object) ['id' => $oid]]);
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fch_jobs SET available_at = UTC_TIMESTAMP() WHERE state = 'PENDING' AND job_key LIKE %s", '%' . $oid . '%'));
    for ($i = 0; $i < 4; $i++) {
        if (\FluentCartHungary\Core\Jobs\JobRunner::instance()->runDue(50) === 0) {
            break;
        }
    }
    $delta = $counterDelta($base);
    $case['no_new_create'] = $delta['sz_create'] === $before['sz_create'] && $delta['pk_create'] === $before['pk_create'];
    $case['no_new_storno'] = $delta['sz_storno'] === $before['sz_storno'];
    $case['no_new_rows'] = count((new \FluentCartHungary\Core\Ledger\InvoiceDocumentRepository())->listForOrder($oid)) === $docsBefore
        && count((new \FluentCartHungary\Core\Ledger\ShipmentRepository())->listForOrder($oid)) === $shipmentsBefore;
    $case['no_refund_history_loss'] = count($refundOps($oid)) === 0;
    $case['late_statuses'] = [($szLate['status'] ?? ''), ($billingoLate['status'] ?? '')];
    $case['debug'] = ['before' => $before, 'after' => $delta, 'szLate' => $szLate, 'billingoLate' => $billingoLate];
    $case['ok'] = !in_array(false, [$case['no_new_create'], $case['no_new_storno'], $case['no_new_rows'], $case['no_refund_history_loss']], true);
    $settings->set('billing', 'selected_invoicer', 'szamlazz');
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}
if (isset($mock) && $mock instanceof MockTransport) {
    $mock->uninstall();
}

$out['ok'] = $out['errors'] === [];
echo wp_json_encode($out) . "\n";
