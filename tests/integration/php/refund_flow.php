<?php
/**
 * T37 — refund lifecycle across FluentCart + Barion/SimplePay (W4_EXECUTION_PLAN §6).
 *
 * Precondition (run.sh): payments/barion, payments/simplepay and billing/billingo are enabled in
 * `fch_modules` BEFORE this process boots, so the gateway adapters, dispatchers and the availability
 * filter are the ones registered by the real plugin boot. All provider hosts are mocked with a
 * catch-all timeout — no real network is possible. Nothing here touches a production provider.
 *
 * Cases (mandatory list): partial sequence + bound, same-intent replay, parallel different intents,
 * lost response -> read-back, UNCERTAIN fencing, negative read-back then late result, validation
 * rejects, EUR 19.99 split, late paid callback after refund, FC mirror parking/promotion/rollback,
 * offline manual path, and SimplePay cancel-is-not-refund.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Payments\RefundService;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'cases' => [], 'errors' => []];
if (!defined('FCH_CREDENTIALS_KEY')) {
    define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
}
global $wpdb;

// ---------------------------------------------------------------- boot precondition
$moduleStates = [];
foreach (['payments/barion', 'payments/simplepay', 'billing/billingo'] as $id) {
    $moduleStates[$id] = \FluentCartHungary\Core\ModuleRegistry::instance()->state($id);
}
$out['boot_states'] = $moduleStates;
$out['checks']['modules_booted'] = !in_array(false, array_map(static fn($s): bool => in_array($s, ['active', 'degraded'], true), $moduleStates), true);

// ---------------------------------------------------------------- config + cleanup
$orderIds = range(997000, 997020);
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
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_transactions WHERE order_id BETWEEN 997000 AND 997020");

update_option('fch_queue_driver', 'db', false);
$settings = new ModuleSettings();
$settings->setMode('test');
$settings->set('barion', 'payee_email', 't37-lab@example.test');
$settings->set('simplepay', 'account_id', 'default');
$creds = new CredentialStore();
$creds->set('barion', 'test', 'default', 'pos_key', 'T37-BARION-POS-KEY');
$creds->set('simplepay', 'test', 'default', 'merchant_huf', 'T37-SP-MERCHANT-HUF');
$creds->set('simplepay', 'test', 'default', 'secret_key_huf', str_repeat('h', 64));
$creds->set('simplepay', 'test', 'default', 'merchant_eur', 'T37-SP-MERCHANT-EUR');
$creds->set('simplepay', 'test', 'default', 'secret_key_eur', str_repeat('e', 64));
$settings->set('billingo', 'block_id', 1);
$settings->set('billingo', 'partner_mode', 'create');
$settings->set('billingo', 'amount_basis', 'gross');
$settings->set('billing', 'selected_invoicer', 'billingo');
$creds->set('billingo', 'test', 'default', 'api_key', 't37-billingo-key');

$GLOBALS['t37'] = [
    'barion_refund' => 0,
    'barion_state' => 0,
    'sp_refund' => 0,
    'sp_query' => 0,
    'sp_cancel' => 0,
    'billingo_doc' => 0,
    'sp_refund_bodies' => [],
    'barion_refund_markers' => [],
];

// ---------------------------------------------------------------- helpers
$noNetwork = static function (MockTransport $mock): MockTransport {
    return $mock->on('http', MockTransport::timeout());
};

$createOrder = static function (int $orderId, int $cents, string $provider, string $currency, string $vendorId, bool $confirmedCharge = true, string $chargeStatus = 'succeeded'): ?object {
    $orderClass = 'FluentCart\App\Models\Order';
    $itemClass = 'FluentCart\App\Models\OrderItem';
    if (!class_exists($orderClass)) {
        return null;
    }
    global $wpdb;
    $order = $orderClass::create([
        'status' => 'processing',
        'payment_status' => $confirmedCharge ? 'paid' : 'pending',
        'payment_method' => $provider,
        'type' => 'payment',
        'fulfillment_type' => 'physical',
        'customer_id' => 0,
        'currency' => $currency,
        'subtotal' => $cents,
        'tax_total' => 0,
        'total_amount' => $cents,
        'total_paid' => $confirmedCharge ? $cents : 0,
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
        'title' => 'T37 refund tétel',
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
    $txnClass = 'FluentCart\App\Models\OrderTransaction';
    $txn = $txnClass::create([
        'order_id' => $orderId,
        'transaction_type' => 'charge',
        'status' => $chargeStatus,
        'total' => $cents,
        'currency' => $currency,
        'payment_method' => $provider,
        'payment_mode' => 'test',
        'vendor_charge_id' => $vendorId,
        'meta' => [],
    ]);
    if ($confirmedCharge) {
        $minor = FluentCartMoney::fromCartCents($cents, $currency);
        $ledger = new LedgerRepository();
        $claim = $ledger->claim([
            'idempotency_key' => 'fch:' . $orderId . ':payment_start:1',
            'module' => 'payments',
            'provider' => $provider,
            'kind' => 'payment_start',
            'mode' => 'test',
            'account_id' => 'default',
            'order_id' => $orderId,
            'currency' => $currency,
            'amount_minor' => $minor,
            'business_reference' => (string) $orderId,
        ]);
        $ledger->transition((int) $claim['row']->id, ExternalOperation::IN_PROGRESS, [], ExternalOperation::NOT_STARTED);
        $ledger->transition((int) $claim['row']->id, ExternalOperation::EXTERNAL_CONFIRMED, ['external_id' => $vendorId], ExternalOperation::IN_PROGRESS);
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

$refundTxns = static function (int $orderId): array {
    $collection = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', $orderId)
        ->where('transaction_type', 'refund')
        ->orderBy('id', 'asc')
        ->get();
    if ($collection instanceof \Traversable) {
        return iterator_to_array($collection);
    }
    return (array) $collection;
};

$refundViaFc = static function (int $orderId, int $cents, ?string $token, string $reason = 't37') {
    $txn = $GLOBALS['t37_fresh']($orderId);
    if ($txn === null) {
        return new \WP_Error('no_charge', 'no charge');
    }
    $args = ['reason' => $reason];
    if ($token !== null) {
        $args['fch_refund_intent'] = $token;
    }
    $result = (new \FluentCart\App\Services\Payments\Refund())->processRefund($txn, $cents, $args);
    // FluentCart wraps a gateway WP_Error inside its success-shaped array (Refund.php tail); unwrap it
    // so callers can rely on the documented string-or-WP_Error contract.
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

$availability = static function (int $orderId): array {
    $service = new RefundService();
    $txn = \FluentCart\App\Models\OrderTransaction::query()->where('order_id', $orderId)
        ->where('transaction_type', 'charge')->orderBy('id', 'desc')->first();
    return $txn === null ? [] : $service->availabilityForTransaction($txn);
};

$runJobs = static function (int $orderId): void {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}fch_jobs SET available_at = UTC_TIMESTAMP() WHERE state = 'PENDING' AND job_key LIKE %s",
        '%' . $orderId . '%'
    ));
    for ($i = 0; $i < 4; $i++) {
        if (JobRunner::instance()->runDue(50) === 0) {
            break;
        }
    }
};

$barionStateResponse = static function (array $transactions, string $status = 'Succeeded'): callable {
    return static function () use ($transactions, $status) {
        $GLOBALS['t37']['barion_state']++;
        return MockTransport::response(200, wp_json_encode(['PaymentId' => 'PAY-1', 'Status' => $status, 'Currency' => 'HUF', 'Transactions' => $transactions]), ['Content-Type' => 'application/json']);
    };
};

$barionRefundResponse = static function (string $refundTxnId): callable {
    return static function ($url, $args) use ($refundTxnId) {
        $GLOBALS['t37']['barion_refund']++;
        $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
        $GLOBALS['t37']['barion_refund_markers'][] = (string) ($body['TransactionsToRefund'][0]['Comment'] ?? '');
        return MockTransport::response(200, wp_json_encode([
            'PaymentId' => 'PAY-1',
            'RefundedTransactions' => [['TransactionId' => $refundTxnId, 'POSTransactionId' => 'POS-1']],
        ]), ['Content-Type' => 'application/json']);
    };
};

$chargeTransaction = static function (string $currency = 'HUF', string $total = '3790', string $txnId = 'BTXN-1'): array {
    return [[
        'TransactionId' => $txnId,
        'POSTransactionId' => 'POS-1',
        'TransactionType' => 'CardPayment',
        'Status' => 'Succeeded',
        'Total' => $total,
        'Currency' => $currency,
    ]];
};

$GLOBALS['t37_fresh'] = $freshCharge;

$counters = static fn(): array => $GLOBALS['t37'];
$delta = static function (array $before): array {
    $after = $GLOBALS['t37'];
    $diff = [];
    foreach (['barion_refund', 'barion_state', 'sp_refund', 'sp_query', 'sp_cancel', 'billingo_doc'] as $k) {
        $diff[$k] = (int) $after[$k] - (int) ($before[$k] ?? 0);
    }
    return $diff;
};
$txnMeta = static fn(int $orderId): array => array_map(static function ($t) {
    return [(string) $t->status, (string) ($t->vendor_charge_id ?? ''), (int) $t->total];
}, (array) $refundTxns($orderId));
$opMeta = static fn(int $orderId): array => array_map(static function ($o) {
    return [(int) $o->id, (string) $o->state, (string) ($o->external_id ?? ''), (int) $o->amount_minor, (string) ($o->request_fingerprint ?? '')];
}, $refundOps($orderId));

// ================================================================ Case 1: HUF partial bound
$case = ['name' => 'huf_partial_sequence'];
try {
    $oid = 997001;
    $base = $counters();
    $txn = $createOrder($oid, 379000, 'barion', 'HUF', 'PAY-1');
    $mock = MockTransport::make()
        ->on('/v2/Payment/Refund', $barionRefundResponse('RF-C1-1000'))
        ->on('/paymentstate', $barionStateResponse($chargeTransaction()))
        ->on('api.test.barion.com', MockTransport::timeout());
    $noNetwork($mock)->install();

    $res1 = $refundViaFc($oid, 100000, 't37-c1-a');
    $txns1 = $refundTxns($oid);
    $case['first_result_ok'] = is_array($res1) && ($res1['vendor_refund_id'] ?? '') === 'RF-C1-1000';
    $case['first_txn_refunded'] = count($txns1) === 1 && (string) $txns1[0]->status === 'refunded' && (string) $txns1[0]->vendor_charge_id === 'RF-C1-1000';
    $case['available_after_first'] = $availability($oid)['available_minor'] ?? -1;
    $case['balance_2790'] = $case['available_after_first'] === 2790;

    // Over the remainder: FluentCart's pre-check must refuse BEFORE a refund transaction is created.
    $res2 = $refundViaFc($oid, 279100, 't37-c1-b');
    $case['over_refund_rejected'] = is_wp_error($res2);
    $case['over_refund_no_txn'] = count($refundTxns($oid)) === 1;

    $res3 = $refundViaFc($oid, 279000, 't37-c1-c');
    $txns3 = $refundTxns($oid);
    $case['second_ok'] = is_array($res3);
    $case['two_txns'] = count($txns3) === 2;
    $case['available_zero'] = ($availability($oid)['available_minor'] ?? -1) === 0;
    $case['dispatches'] = $delta($base)['barion_refund'];
    $case['exactly_two_dispatches'] = $delta($base)['barion_refund'] === 2;
    $case['debug'] = ['res1' => is_wp_error($res1) ? $res1->get_error_code() : 'array', 'res2' => is_wp_error($res2) ? $res2->get_error_code() : 'array', 'res3' => is_wp_error($res3) ? $res3->get_error_code() : 'array', 'txns' => $txnMeta($oid), 'ops' => $opMeta($oid), 'parent_meta' => $freshCharge($oid)->meta];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['first_result_ok'], $case['first_txn_refunded'], $case['balance_2790'], $case['over_refund_rejected'], $case['over_refund_no_txn'], $case['second_ok'], $case['two_txns'], $case['available_zero'], $case['exactly_two_dispatches']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 2: same-intent replay -> one dispatch
$case = ['name' => 'same_intent_replay'];
try {
    $oid = 997002;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', 'HUF', 'PAY-2');
    $mock = MockTransport::make()
        ->on('/v2/Payment/Refund', $barionRefundResponse('RF-C2-1000'))
        ->on('/paymentstate', $barionStateResponse($chargeTransaction('HUF', '3790', 'BTXN-2')))
        ->on('api.test.barion.com', MockTransport::timeout());
    $noNetwork($mock)->install();

    $first = $refundViaFc($oid, 100000, 't37-c2-token');
    $second = $refundViaFc($oid, 100000, 't37-c2-token'); // replay of the identical intent
    $txns = $refundTxns($oid);
    $case['first_ok'] = is_array($first);
    $case['second_suppressed'] = is_wp_error($second) && $second->get_error_code() === 'fch_refund_duplicate_intent';
    $case['single_dispatch'] = $delta($base)['barion_refund'] === 1;
    $case['one_refunded_one_failed'] = count($txns) === 2
        && (string) $txns[0]->status === 'refunded'
        && (string) $txns[1]->status === 'failed';
    $case['debug'] = ['first' => is_wp_error($first) ? $first->get_error_code() : 'array', 'second' => is_wp_error($second) ? $second->get_error_code() : 'array', 'txns' => $txnMeta($oid), 'ops' => $opMeta($oid)];
    // The duplicate's local rollback restored the parent's refunded_total: available is again 2790.
    $case['available_restored'] = ($availability($oid)['available_minor'] ?? -1) === 2790;
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['first_ok'], $case['second_suppressed'], $case['single_dispatch'], $case['one_refunded_one_failed'], $case['available_restored']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 3: parallel different intents -> no over-refund
$case = ['name' => 'parallel_different_intents'];
try {
    $oid = 997003;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', 'HUF', 'PAY-3');
    $mock = MockTransport::make()
        ->on('/v2/Payment/Refund', $barionRefundResponse('RF-C3-3000'))
        ->on('/paymentstate', $barionStateResponse($chargeTransaction('HUF', '3790', 'BTXN-3')))
        ->on('api.test.barion.com', MockTransport::timeout());
    $noNetwork($mock)->install();

    $service = new RefundService();
    $first = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) ($freshCharge($oid)->id ?? 0), 'amount_minor' => 3000, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c3-a']);
    $second = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) ($freshCharge($oid)->id ?? 0), 'amount_minor' => 2000, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c3-b']);
    $third = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) ($freshCharge($oid)->id ?? 0), 'amount_minor' => 790, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c3-c']);
    $case['first_confirmed'] = ($first['outcome'] ?? '') === 'confirmed';
    $case['second_rejected'] = ($second['outcome'] ?? '') === 'rejected' && ($second['reason'] ?? '') === 'EXCEEDS_REFUNDABLE';
    $case['third_confirmed'] = ($third['outcome'] ?? '') === 'confirmed';
    $case['two_dispatches_only'] = $delta($base)['barion_refund'] === 2;
    $case['debug'] = ['outcomes' => [$first['outcome'] ?? '', $second['outcome'] ?? '', $third['outcome'] ?? ''], 'reasons' => [$first['reason'] ?? '', $second['reason'] ?? '', $third['reason'] ?? ''], 'ops' => $opMeta($oid)];
    $case['total_confirmed_3790'] = array_sum(array_map(static fn($r) => (string) $r->state === ExternalOperation::EXTERNAL_CONFIRMED ? (int) $r->amount_minor : 0, $refundOps($oid))) === 3790;
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['first_confirmed'], $case['second_rejected'], $case['third_confirmed'], $case['two_dispatches_only'], $case['total_confirmed_3790']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 4: lost response -> read-back proves the same refund
$case = ['name' => 'lost_response_readback'];
try {
    $oid = 997004;
    $base = $counters();
    $createOrder($oid, 300000, 'barion', 'HUF', 'PAY-4');
    $capturedMarker = '';
    $mock = MockTransport::make()
        ->on('/v2/Payment/Refund', static function ($url, $args) {
            $GLOBALS['t37']['barion_refund']++;
            $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
            $GLOBALS['t37_c4_marker'] = (string) ($body['TransactionsToRefund'][0]['Comment'] ?? '');
            return MockTransport::timeout(); // the provider executed it, the response was lost
        })
        ->on('/paymentstate', static function () {
            $GLOBALS['t37']['barion_state']++;
            $marker = (string) ($GLOBALS['t37_c4_marker'] ?? '');
            return MockTransport::response(200, wp_json_encode([
                'PaymentId' => 'PAY-4',
                'Status' => 'Succeeded',
                'Currency' => 'HUF',
                'Transactions' => [
                    ['TransactionId' => 'BTXN-4', 'TransactionType' => 'CardPayment', 'Status' => 'Succeeded', 'Total' => '3000'],
                    ['TransactionId' => 'RF-C4-1000', 'TransactionType' => 'CardPayment', 'Status' => 'Succeeded', 'Total' => '1000', 'Comment' => $marker],
                ],
            ]), ['Content-Type' => 'application/json']);
        })
        ->on('api.test.barion.com', MockTransport::timeout());
    $noNetwork($mock)->install();

    $result = $refundViaFc($oid, 100000, 't37-c4');
    $case['uncertain_returned'] = is_wp_error($result) && $result->get_error_code() === 'fch_refund_uncertain';
    $txns = $refundTxns($oid);
    $case['fc_txn_parked_pending'] = count($txns) === 1 && (string) $txns[0]->status === 'pending';
    $ops = $refundOps($oid);
    $case['ledger_uncertain'] = count($ops) === 1 && in_array((string) $ops[0]->state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true);
    $case['one_dispatch_only'] = $delta($base)['barion_refund'] === 1;

    $state = (new RefundService())->reconcileNow((int) $ops[0]->id);
    $opsAfter = $refundOps($oid);
    $txnsAfter = $refundTxns($oid);
    $case['reconciled_confirmed'] = $state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['same_refund_id'] = (string) $opsAfter[0]->external_id === 'RF-C4-1000';
    $case['fc_promoted'] = (string) $txnsAfter[0]->status === 'refunded' && (string) $txnsAfter[0]->vendor_charge_id === 'RF-C4-1000';
    $case['still_one_dispatch'] = $delta($base)['barion_refund'] === 1;
    $case['debug'] = ['result' => is_wp_error($result) ? $result->get_error_code() : 'array', 'marker' => (string) ($GLOBALS['t37_c4_marker'] ?? ''), 'txns' => $txnMeta($oid), 'ops' => $opMeta($oid)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['uncertain_returned'], $case['fc_txn_parked_pending'], $case['ledger_uncertain'], $case['one_dispatch_only'], $case['reconciled_confirmed'], $case['same_refund_id'], $case['fc_promoted'], $case['still_one_dispatch']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 5+6: uncertain fences; negative read-back then late result
$case = ['name' => 'uncertain_fence_and_late_result'];
try {
    $oid = 997005;
    $base = $counters();
    $createOrder($oid, 100000, 'barion', 'HUF', 'PAY-5');
    $GLOBALS['t37_c56_visible'] = false;
    $mock = MockTransport::make()
        ->on('/v2/Payment/Refund', static function ($url, $args) {
            $GLOBALS['t37']['barion_refund']++;
            $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
            $GLOBALS['t37_c56_marker'] = (string) ($body['TransactionsToRefund'][0]['Comment'] ?? '');
            return MockTransport::timeout();
        })
        ->on('/paymentstate', static function () {
            $GLOBALS['t37']['barion_state']++;
            $transactions = [['TransactionId' => 'BTXN-5', 'TransactionType' => 'CardPayment', 'Status' => 'Succeeded', 'Total' => '1000']];
            if (!empty($GLOBALS['t37_c56_visible'])) {
                $transactions[] = ['TransactionId' => 'RF-C56-1000', 'TransactionType' => 'CardPayment', 'Status' => 'Succeeded', 'Total' => '1000', 'Comment' => (string) ($GLOBALS['t37_c56_marker'] ?? '')];
            }
            return MockTransport::response(200, wp_json_encode(['PaymentId' => 'PAY-5', 'Status' => 'Succeeded', 'Currency' => 'HUF', 'Transactions' => $transactions]), ['Content-Type' => 'application/json']);
        })
        ->on('api.test.barion.com', MockTransport::timeout());
    $noNetwork($mock)->install();

    $first = $refundViaFc($oid, 100000, 't37-c56');
    $ops = $refundOps($oid);
    $case['uncertain'] = is_wp_error($first);
    $case['no_available_while_uncertain'] = ($availability($oid)['available_minor'] ?? -1) === 0;

    // Additional identical-intent request must not dispatch again; a different intent cannot over-refund.
    $service = new RefundService();
    $replay = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) $freshCharge($oid)->id, 'amount_minor' => 1000, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c56']);
    $other = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) $freshCharge($oid)->id, 'amount_minor' => 500, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c56-other']);
    $case['replay_no_dispatch'] = $delta($base)['barion_refund'] === 1;
    $case['other_rejected'] = ($other['outcome'] ?? '') === 'rejected';

    // Negative read-back: still uncertain (never a rejection).
    $state1 = $service->reconcileNow((int) $ops[0]->id);
    $ops1 = $refundOps($oid);
    $case['negative_stays_open'] = in_array($state1, [ExternalOperation::AWAITING_RECONCILIATION, ExternalOperation::UNCERTAIN], true);
    $case['identity_preserved'] = count($ops1) === 1 && (int) $ops1[0]->id === (int) $ops[0]->id;

    // Later the provider result appears: same identity, proven, no new dispatch/op.
    $GLOBALS['t37_c56_visible'] = true;
    $state2 = $service->reconcileNow((int) $ops[0]->id);
    $ops2 = $refundOps($oid);
    $txns2 = $refundTxns($oid);
    $case['late_result_confirmed'] = $state2 === ExternalOperation::EXTERNAL_CONFIRMED && (string) $ops2[0]->external_id === 'RF-C56-1000';
    $case['still_one_op_one_dispatch'] = count($ops2) === 1 && $delta($base)['barion_refund'] === 1;
    $case['late_promotion_fc'] = (string) $txns2[0]->status === 'refunded' && (string) $txns2[0]->vendor_charge_id === 'RF-C56-1000';
    $case['debug'] = ['first' => is_wp_error($first) ? $first->get_error_code() : 'array', 'replay' => $replay['outcome'] ?? '', 'other' => $other['outcome'] ?? '', 'state1' => $state1, 'state2' => $state2, 'ops' => $opMeta($oid), 'txns' => $txnMeta($oid)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['uncertain'], $case['no_available_while_uncertain'], $case['replay_no_dispatch'], $case['other_rejected'], $case['negative_stays_open'], $case['identity_preserved'], $case['late_result_confirmed'], $case['still_one_op_one_dispatch'], $case['late_promotion_fc']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 7: validation rejects
$case = ['name' => 'validation_rejects'];
try {
    $case['ok'] = true;
    $service = new RefundService();

    // Zero/negative: FluentCart refuses before any gateway call.
    $oid = 997006;
    $base = $counters();
    $createOrder($oid, 379000, 'barion', 'HUF', 'PAY-6');
    $mock = MockTransport::make()->on('api.test.barion.com', MockTransport::timeout());
    $noNetwork($mock)->install();
    $zero = $refundViaFc($oid, 0, 't37-c7-zero');
    $negative = (new \FluentCart\App\Services\Payments\Refund())->processRefund($freshCharge($oid), -100, ['fch_refund_intent' => 't37-c7-neg']);
    $case['zero_rejected'] = is_wp_error($zero);
    $case['negative_rejected'] = is_wp_error($negative);
    $case['no_txn_created'] = count($refundTxns($oid)) === 0;

    // Wrong currency: the binding mismatches, the request cannot consume the HUF charge.
    $wrongCurrency = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) $freshCharge($oid)->id, 'amount_minor' => 100, 'currency' => 'EUR', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c7-cur']);
    $case['currency_mismatch_rejected'] = ($wrongCurrency['outcome'] ?? '') === 'rejected';
    $case['no_dispatch_so_far'] = $delta($base)['barion_refund'] === 0;

    // Wrong account: no credentials for that slot -> local error, never a provider call.
    $wrongAccount = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) $freshCharge($oid)->id, 'amount_minor' => 100, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'no-such-account', 'intent_token' => 't37-c7-acc']);
    $case['wrong_account_local_error'] = ($wrongAccount['outcome'] ?? '') === 'local_error';
    $case['still_no_dispatch'] = $delta($base)['barion_refund'] === 0;
    $case['debug'] = ['zero' => is_wp_error($zero) ? $zero->get_error_code() : 'array', 'negative' => is_wp_error($negative) ? $negative->get_error_code() : 'array', 'currency' => $wrongCurrency['outcome'] ?? '', 'account' => $wrongAccount['outcome'] ?? '', 'unpaid' => $unpaid['outcome'] ?? '', 'unpaid_reason' => $unpaid['reason'] ?? '', 'ops' => $opMeta($oid)];

    // Uncharged payment: a charge transaction that never succeeded is not refundable.
    $oid2 = 997007;
    $createOrder($oid2, 200000, 'barion', 'HUF', 'PAY-7', false, 'pending');
    $unpaid = $service->request(['order_id' => $oid2, 'parent_txn_id' => (int) $freshCharge($oid2)->id, 'amount_minor' => 1000, 'currency' => 'HUF', 'provider' => 'barion', 'mode' => 'test', 'account_id' => 'default', 'intent_token' => 't37-c7-unpaid']);
    $case['unpaid_rejected'] = ($unpaid['outcome'] ?? '') === 'rejected';
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['zero_rejected'], $case['negative_rejected'], $case['no_txn_created'], $case['currency_mismatch_rejected'], $case['no_dispatch_so_far'], $case['wrong_account_local_error'], $case['still_no_dispatch'], $case['unpaid_rejected']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 8: EUR 19.99 -> 10.00 + 9.99 (SimplePay)
$case = ['name' => 'eur_split_simplepay'];
try {
    $oid = 997008;
    $base = $counters();
    $createOrder($oid, 1999, 'simplepay', 'EUR', 'SP-TXN-8');
    $refundSeq = 0;
    $mock = MockTransport::make()
        ->on('/v2/refund', static function ($url, $args) {
            $GLOBALS['t37']['sp_refund']++;
            $rawBody = (string) ($args['body'] ?? '');
            $body = json_decode($rawBody, true) ?: [];
            // Keep the exact decimal TEXT: json_decode turns "10.00" into float 10 and loses the format.
            $captured = '';
            if (preg_match('/"refundTotal"\s*:\s*"?([0-9.]+)"?/', $rawBody, $m)) {
                $captured = $m[1];
            }
            $GLOBALS['t37']['sp_refund_bodies'][] = $captured;
            $GLOBALS['t37_c8_seq'] = ($GLOBALS['t37_c8_seq'] ?? 0) + 1;
            $remaining = $GLOBALS['t37_c8_seq'] === 1 ? '9.99' : '0.00';
            return MockTransport::response(200, wp_json_encode([
                'merchant' => 'T37-SP-MERCHANT-EUR',
                'transactionId' => 555001,
                'currency' => 'EUR',
                'refundTotal' => (string) ($body['refundTotal'] ?? ''),
                'refundTransactionId' => 880000 + $GLOBALS['t37_c8_seq'],
                'remainingTotal' => $remaining,
            ]), ['Content-Type' => 'application/json', 'Signature' => 'mock']);
        })
        ->on('/v2/transactionCancel', static function () {
            $GLOBALS['t37']['sp_cancel']++;
            return MockTransport::response(200, '{}');
        })
        ->on('sandbox.simplepay.hu', MockTransport::timeout());
    $noNetwork($mock)->install();

    // EUR is exponent-2: FluentCart cents and domain minor units coincide (19,99 EUR = 1999 cents).
    $first = $refundViaFc($oid, 1000, 't37-c8-a');
    $second = $refundViaFc($oid, 999, 't37-c8-b');
    $third = $refundViaFc($oid, 1, 't37-c8-c');
    $case['first_ok'] = is_array($first) && ($first['vendor_refund_id'] ?? '') === '880001';
    $case['second_ok'] = is_array($second) && ($second['vendor_refund_id'] ?? '') === '880002';
    $case['third_rejected'] = is_wp_error($third);
    $case['payload_decimals'] = $GLOBALS['t37']['sp_refund_bodies'] === ['10.00', '9.99'];
    $case['available_zero'] = ($availability($oid)['available_minor'] ?? -1) === 0;
    $case['cancel_never_used'] = $GLOBALS['t37']['sp_cancel'] === 0;
    $case['dispatches_two'] = $delta($base)['sp_refund'] === 2;
    $case['debug'] = ['bodies' => $GLOBALS['t37']['sp_refund_bodies'], 'first' => is_wp_error($first) ? $first->get_error_code() : 'array', 'second' => is_wp_error($second) ? $second->get_error_code() : 'array', 'third' => is_wp_error($third) ? $third->get_error_code() : 'array', 'txns' => $txnMeta($oid)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['first_ok'], $case['second_ok'], $case['third_rejected'], $case['payload_decimals'], $case['available_zero'], $case['cancel_never_used'], $case['dispatches_two']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 9: late paid callback after refund -> no new invoice, refund intact
$case = ['name' => 'late_paid_callback'];
try {
    $oid = 997009;
    $base = $counters();
    $createOrder($oid, 424000, 'barion', 'HUF', 'PAY-9');
    $mock = MockTransport::make()
        ->on('/v2/Payment/Refund', $barionRefundResponse('RF-C9-1000'))
        ->on('/paymentstate', $barionStateResponse($chargeTransaction('HUF', '4240', 'BTXN-9')))
        ->on('/v3/partners', static fn($url, $args) => MockTransport::response(201, wp_json_encode(['id' => 951]), ['Content-Type' => 'application/json']))
        ->on('/download', static fn() => MockTransport::response(200, "%PDF-1.4\n% t37 billingo pdf\n", ['Content-Type' => 'application/pdf']))
        ->on('/v3/documents', static function ($url, $args) {
            // Count ONLY a real document creation; the PDF download also contains '/v3/documents'.
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
            if ($method !== 'POST' || $path !== '/v3/documents') {
                return MockTransport::response(200, "%PDF-1.4\n% t37 fallback pdf\n", ['Content-Type' => 'application/pdf']);
            }
            $GLOBALS['t37']['billingo_doc']++;
            $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
            $id = 7700 + $GLOBALS['t37']['billingo_doc'];
            return MockTransport::response(201, wp_json_encode([
                'id' => $id,
                'invoice_number' => 'T37-BIL-' . $id,
                'vendor_id' => (string) ($body['vendor_id'] ?? ''),
                'currency' => 'HUF',
                'gross_total' => 4240,
                'net_total' => 4240,
                'vat_amount' => 0,
            ]), ['Content-Type' => 'application/json']);
        })
        ->on('api.test.barion.com', MockTransport::timeout())
        ->on('api.billingo.hu', MockTransport::json(404, ['error' => 'mock_missing']));
    $noNetwork($mock)->install();

    // The paid callback issues the invoice (once)...
    $order = \FluentCart\App\Models\Order::query()->find($oid);
    do_action('fluent_cart/order_paid_done', ['order' => $order]);
    $runJobs($oid);
    $docsAfterPaid = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid));

    // ...then the refund happens...
    $refund = $refundViaFc($oid, 100000, 't37-c9');
    $ops = $refundOps($oid);

    // ...and a LATE duplicate paid callback must not issue anything new or disturb the refund state.
    $labelJobsBefore = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s AND hook = 'fch_create_label'",
        '%' . $oid . '%'
    ));
    do_action('fluent_cart/order_paid_done', ['order' => \FluentCart\App\Models\Order::query()->find($oid)]);
    $runJobs($oid);
    $docsAfterLate = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid));
    $opsAfter = $refundOps($oid);

    // A late duplicate paid callback must not enqueue another label attempt either (job-key dedup).
    $labelJobsAfterLate = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s AND hook = 'fch_create_label'",
        '%' . $oid . '%'
    ));
    $case['no_new_label_attempt'] = $labelJobsAfterLate === $labelJobsBefore;
    $case['invoice_once'] = $docsAfterPaid === 1 && $docsAfterLate === 1;
    $case['billingo_docs_one'] = $delta($base)['billingo_doc'] === 1;
    $case['debug'] = ['billingo_doc_before' => (int) ($base['billingo_doc'] ?? -1), 'billingo_doc_after' => (int) $GLOBALS['t37']['billingo_doc'], 'docs' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid)), 'doc_rows' => array_map(static fn($r) => [(int) $r->id, (string) $r->state, (string) ($r->document_number ?? '')], (array) $wpdb->get_results($wpdb->prepare("SELECT id, state, document_number FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d", $oid))), 'refund' => is_wp_error($refund) ? $refund->get_error_code() : 'array', 'txns' => $txnMeta($oid), 'ops' => $opMeta($oid)];
    $case['refund_intact'] = is_array($refund) && count($opsAfter) === count($ops) && (string) $opsAfter[0]->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['invoice_once'], $case['billingo_docs_one'], $case['refund_intact'], $case['no_new_label_attempt']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 10: offline/manual path is separate
$case = ['name' => 'manual_offline_path'];
try {
    $oid = 997010;
    $base = $counters();
    $createOrder($oid, 424000, 'offline_payment', 'HUF', 'COD-C10', true);
    $mock = MockTransport::make()->on('http', MockTransport::timeout());
    $mock->install();

    $filter = static fn($value) => ['status' => 'yes', 'source' => 'fch_manual'];
    add_filter('fluent_cart/order_refund_manually', $filter, 10, 2);
    $result = (new \FluentCart\App\Services\Payments\Refund())->processRefund($freshCharge($oid), 100000, ['reason' => 'manual cash return']);
    remove_filter('fluent_cart/order_refund_manually', $filter, 10);

    $service = new RefundService();
    $check = $service->validateManualRefund($oid, 100000, 'HUF');
    $ledger = $service->request(['order_id' => $oid, 'parent_txn_id' => (int) $freshCharge($oid)->id, 'amount_minor' => 1000, 'currency' => 'HUF', 'provider' => 'manual', 'mode' => 'test', 'account_id' => 'default', 'manual' => true, 'reason' => 'manual cash return']);

    $ops = $refundOps($oid);
    $txns = $refundTxns($oid);
    $case['manual_ok'] = is_array($result) && ($result['manual_refund']['status'] ?? '') === 'yes';
    $case['ledger_confirmed'] = ($ledger['outcome'] ?? '') === 'confirmed' && count($ops) === 1 && (string) $ops[0]->provider === 'manual' && (string) $ops[0]->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['fc_recorded'] = count($txns) === 1 && (string) $txns[0]->status === 'refunded';
    $case['no_provider_dispatch'] = $delta($base)['barion_refund'] === 0 && $delta($base)['sp_refund'] === 0;
    $case['debug'] = ['result' => is_wp_error($result) ? $result->get_error_code() : 'array', 'check' => $check, 'ledger' => $ledger, 'txns' => $txnMeta($oid), 'ops' => $opMeta($oid)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['manual_ok'], $case['ledger_confirmed'], $case['fc_recorded'], $case['no_provider_dispatch']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ================================================================ Case 10: SimplePay lost response -> refunds query read-back
$case = ['name' => 'simplepay_readback'];
try {
    $oid = 997011;
    $base = $counters();
    $createOrder($oid, 200000, 'simplepay', 'HUF', 'SP-TXN-11');
    $mock = MockTransport::make()
        ->on('/v2/refund', static function () {
            $GLOBALS['t37']['sp_refund']++;
            return MockTransport::timeout(); // executed provider-side, response lost
        })
        ->on('/v2/query', static function () {
            $GLOBALS['t37']['sp_query']++;
            return MockTransport::response(200, wp_json_encode([
                'merchant' => 'T37-SP-MERCHANT-HUF',
                'transactions' => [[
                    'transactionId' => 556001,
                    'orderRef' => '997011',
                    'status' => 'FINISHED',
                    'total' => '2000',
                    'refunds' => [[
                        'transactionId' => 990011,
                        'refundDate' => gmdate('Y-m-d H:i:s'),
                        'refundTotal' => '500',
                        'status' => 'FINISHED',
                    ]],
                ]],
            ]), ['Content-Type' => 'application/json', 'Signature' => 'mock']);
        })
        ->on('sandbox.simplepay.hu', MockTransport::timeout());
    $noNetwork($mock)->install();

    $first = $refundViaFc($oid, 50000, 't37-c10');
    $ops = $refundOps($oid);
    $txns = $refundTxns($oid);
    $case['uncertain'] = is_wp_error($first) && $first->get_error_code() === 'fch_refund_uncertain';
    $case['fc_parked'] = count($txns) === 1 && (string) $txns[0]->status === 'pending';
    $case['one_dispatch'] = $delta($base)['sp_refund'] === 1;

    $state = (new RefundService())->reconcileNow((int) $ops[0]->id);
    $opsAfter = $refundOps($oid);
    $txnsAfter = $refundTxns($oid);
    $case['confirmed'] = $state === ExternalOperation::EXTERNAL_CONFIRMED && (string) $opsAfter[0]->external_id === '990011';
    $case['no_second_dispatch'] = $delta($base)['sp_refund'] === 1 && $delta($base)['sp_query'] >= 1;
    $case['fc_promoted'] = (string) $txnsAfter[0]->status === 'refunded' && (string) $txnsAfter[0]->vendor_charge_id === '990011';
    $case['debug'] = ['state' => $state, 'ops' => $opMeta($oid), 'txns' => $txnMeta($oid)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['uncertain'], $case['fc_parked'], $case['one_dispatch'], $case['confirmed'], $case['no_second_dispatch'], $case['fc_promoted']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// cleanup shared selection so sibling suites keep the legacy behaviour
$settings->set('billing', 'selected_invoicer', '');

$allOk = count($out['cases']) >= 10;
foreach ($out['cases'] as $c) {
    if (($c['ok'] ?? false) !== true) {
        $allOk = false;
    }
}
$out['checks']['all_cases_ok'] = $allOk;
$out['ok'] = !in_array(false, $out['checks'], true);
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
