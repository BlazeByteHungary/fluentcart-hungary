<?php
/**
 * W1 Barion flow integration probe — AUTHOR ONLY, DO NOT RUN as part of this task.
 *
 * Run by the integrator on the lab/sandbox WordPress with FluentCart + this plugin active:
 *   wp eval-file fluentcart-hungary/tests/integration/php/barion_flow.php
 *
 * Optional env:
 *   FCH_BARION_TEST_ORDER_ID  a real FluentCart order id to exercise the actual settlement path
 *                             (StatusHelper::syncOrderStatuses). Without it the mirror transition is
 *                             still asserted; settlement reports order_not_found.
 *
 * Contract checks (05_IMPLEMENTATION_WAVES §W1.6; 04_BARION_CONTRACT §3/§5/§6):
 *   1. Mocked Start success  -> payment attempt row + IN_PROGRESS + redirect URL.
 *   2. Verified callback     -> attempt EXTERNAL_CONFIRMED via the status API (never the bare callback).
 *   3. Mocked Start timeout  -> ledger UNCERTAIN (never auto re-dispatched) + reconcile job row.
 *   4. Reconciler read-back  -> proves success from GetPaymentState, attempt EXTERNAL_CONFIRMED.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Database\Schema;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Payments\Barion\BarionClient;
use FluentCartHungary\Modules\Payments\Barion\BarionGateway;
use FluentCartHungary\Modules\Payments\Barion\BarionIpnHandler;
use FluentCartHungary\Modules\Payments\Barion\BarionReconciler;
use FluentCartHungary\Modules\Payments\Barion\BarionStatus;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['step1' => null, 'step2' => null, 'step3' => null, 'step4' => null, 'errors' => []];

$orderId1 = (int) getenv('FCH_BARION_TEST_ORDER_ID');
if ($orderId1 <= 0) {
    $orderId1 = 990001;
}
$orderId2 = $orderId1 + 1;
$orderId3 = $orderId1 + 2;
$orderId4 = $orderId1 + 3; // FC-boundary step (real FluentCart order, amounts in FC cents)

// Re-runnable: clean the synthetic rows for these test orders (and the fixture's webhook dedup key).
global $wpdb;
if (is_object($wpdb) && isset($wpdb->prefix)) {
    foreach ([$orderId1, $orderId2, $orderId3, $orderId4] as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_payment_attempts WHERE order_id = %d", $oid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}fch_webhook_events WHERE provider = %s AND dedup_key = %s",
        'barion',
        (string) MockTransport::fixture('barion/paymentstate_success.json')['PaymentId']
    ));
}

$amount = 12345;
$currency = 'HUF';
$fixturePaymentId = (string) MockTransport::fixture('barion/paymentstate_success.json')['PaymentId'];
$fixtureRequestId = (string) MockTransport::fixture('barion/paymentstate_success.json')['PaymentRequestId'];

if (!ReconcilerRegistry::has('barion')) {
    ReconcilerRegistry::register('barion', [new BarionReconciler(), 'reconcile']);
}

// Non-secret test settings so the gateway considers itself configured.
$moduleSettings = new ModuleSettings();
$moduleSettings->set('barion', 'enabled', true);
$moduleSettings->set('barion', 'payee_email', 'shop@example.test');
$moduleSettings->set('barion', 'account_id', 'default');

$attempts = new PaymentAttemptRepository();
$ledger = new LedgerRepository();

// ---------------------------------------------------------------- Step 1: mocked Start success
$successFixture = MockTransport::fixture('barion/paymentstate_success.json');
$mock = MockTransport::make()
    ->on(BarionClient::START_PATH, MockTransport::json(200, [
        'PaymentId' => $fixturePaymentId,
        'Status' => 'Prepared',
        'GatewayUrl' => 'https://secure.test.barion.com/Pay?id=' . $fixturePaymentId,
        'Errors' => [],
    ]))
    ->on('/v4/payment/', MockTransport::json(200, $successFixture))
    ->install();

try {
    $gateway = new BarionGateway(new BarionClient('test', 'test-pos-key'));
    $start = $gateway->startPaymentAttempt(
        $orderId1,
        'SYNTH' . $orderId1,
        $amount,
        $currency,
        'https://shop.example.test/checkout/success',
        'https://shop.example.test/?fluent-cart=fct_payment_listener_ipn&method=barion'
    );
    $idem1 = BarionStatus::idempotencyKey($orderId1, 0);
    $attempt1 = $attempts->getByKey($idem1);
    $ledger1 = $ledger->getByKey($idem1);
    $out['step1'] = [
        'status' => $start['status'] ?? '',
        'redirect_to' => $start['redirect_to'] ?? '',
        'attempt_created' => $attempt1 !== null,
        'attempt_state' => $attempt1 ? (string) $attempt1->state : '',
        'attempt_external_payment_id' => $attempt1 ? (string) $attempt1->external_payment_id : '',
        'attempt_redirect_url' => $attempt1 ? (string) $attempt1->redirect_url : '',
        'ledger_state' => $ledger1 ? (string) $ledger1->state : '',
    ];
    $ok1 = ($start['status'] ?? '') === 'success'
        && $attempt1 !== null
        && (string) $attempt1->state === ExternalOperation::IN_PROGRESS
        && str_contains((string) $attempt1->redirect_url, 'secure.test.barion.com');
    $out['step1']['ok'] = $ok1;
    if (!$ok1) {
        $out['errors'][] = 'step1_failed';
    }
} catch (\Throwable $e) {
    $out['step1'] = ['ok' => false];
    $out['errors'][] = 'step1_exception:' . $e->getMessage();
}

// ---------------------------------------------------------------- Step 2: verified callback (IPN)
try {
    // The status-API payload must carry the attempt's real PaymentRequestId (reference check, 04_BARION §7).
    $mock->uninstall();
    $attemptForState = $attempts->getByKey(BarionStatus::idempotencyKey($orderId1, 0));
    $successState = MockTransport::fixture('barion/paymentstate_success.json');
    $successState['PaymentRequestId'] = (string) ($attemptForState->external_request_id ?? '');
    $successState['Total'] = $amount;
    $successState['Currency'] = $currency;
    $mockVerify = MockTransport::make()
        ->on('/v4/payment/', MockTransport::json(200, $successState))
        ->install();

    $_REQUEST['paymentId'] = $fixturePaymentId;
    $_GET['paymentId'] = $fixturePaymentId;
    $ipn = (new BarionIpnHandler(new BarionClient('test', 'test-pos-key')))->handle();
    $mockVerify->uninstall();
    $attempt1After = $attempts->getByKey(BarionStatus::idempotencyKey($orderId1, 0));
    $out['step2'] = [
        'handler_status' => $ipn['status'] ?? '',
        'settled' => $ipn['settled'] ?? '',
        'attempt_state' => $attempt1After ? (string) $attempt1After->state : '',
        'verification_source' => $attempt1After ? (string) $attempt1After->verification_source : '',
        'charged_amount_minor' => $attempt1After ? (int) $attempt1After->charged_amount_minor : 0,
    ];
    $ok2 = ($ipn['status'] ?? '') === 'confirmed'
        && $attempt1After !== null
        && (string) $attempt1After->state === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $attempt1After->verification_source === 'status_api';
    $out['step2']['ok'] = $ok2;
    if (!$ok2) {
        $out['errors'][] = 'step2_failed';
    }
} catch (\Throwable $e) {
    $out['step2'] = ['ok' => false];
    $out['errors'][] = 'step2_exception:' . $e->getMessage();
}

// ---------------------------------------------------------------- Step 3: mocked Start timeout -> UNCERTAIN + job
$mock->uninstall();
$mockTimeout = MockTransport::make()
    ->on(BarionClient::START_PATH, MockTransport::timeout())
    ->install();
try {
    $gatewayTimeout = new BarionGateway(new BarionClient('test', 'test-pos-key'));
    $startTimeout = $gatewayTimeout->startPaymentAttempt(
        $orderId2,
        'SYNTH' . $orderId2,
        5000,
        $currency,
        'https://shop.example.test/checkout/success',
        'https://shop.example.test/?fluent-cart=fct_payment_listener_ipn&method=barion'
    );
    $idem2 = BarionStatus::idempotencyKey($orderId2, 0);
    $attempt2 = $attempts->getByKey($idem2);
    $ledger2 = $ledger->getByKey($idem2);
    $ledgerId2 = $ledger2 ? (int) $ledger2->id : 0;

    global $wpdb;
    $jobsTable = Schema::table('fch_jobs');
    $reconcileJob = $ledgerId2 > 0
        ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$jobsTable}` WHERE job_key = %s", 'fch:reconcile:' . $ledgerId2))
        : '0';

    $out['step3'] = [
        'start_status' => $startTimeout['status'] ?? '',
        'ledger_state' => $ledger2 ? (string) $ledger2->state : '',
        'reconcile_jobs' => (int) $reconcileJob,
    ];
    $ok3 = ($startTimeout['status'] ?? '') === 'failed'
        && $ledger2 !== null
        && (string) $ledger2->state === ExternalOperation::UNCERTAIN
        && (int) $reconcileJob === 1;
    $out['step3']['ok'] = $ok3;
    if (!$ok3) {
        $out['errors'][] = 'step3_failed';
    }
} catch (\Throwable $e) {
    $out['step3'] = ['ok' => false];
    $out['errors'][] = 'step3_exception:' . $e->getMessage();
}

// ---------------------------------------------------------------- Step 4: reconciler read-back proves success
$mockTimeout->uninstall();
$reconPaymentId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$reconRequestId = 'fch-' . $orderId3 . '-1-test';
$reconState = [
    'PaymentId' => $reconPaymentId,
    'PaymentRequestId' => $reconRequestId,
    'Status' => 'Succeeded',
    'PaymentType' => 'Immediate',
    'Total' => 12345,
    'Currency' => 'HUF',
    'TraceId' => 'trace-recon-1',
    'Transactions' => [],
    'Errors' => [],
];
$mockRecon = MockTransport::make()
    ->on('/v4/payment/', MockTransport::json(200, $reconState))
    ->install();

try {
    $idem3 = BarionStatus::idempotencyKey($orderId3, 0);
    $attempts->getOrCreate([
        'order_id' => $orderId3,
        'order_uuid' => 'SYNTH' . $orderId3,
        'provider' => 'barion',
        'mode' => 'test',
        'account_id' => 'default',
        'attempt_no' => 1,
        'idempotency_key' => $idem3,
        'amount_minor' => 12345,
        'currency' => 'HUF',
        'external_payment_id' => $reconPaymentId,
        'external_request_id' => $reconRequestId,
        'payment_type' => 'Immediate',
    ]);

    $claimed = $ledger->claim([
        'idempotency_key' => $idem3,
        'order_id' => $orderId3,
        'module' => 'payments',
        'provider' => 'barion',
        'kind' => 'payment_start',
        'mode' => 'test',
        'account_id' => 'default',
        'currency' => 'HUF',
        'amount_minor' => 12345,
    ]);
    $ledgerId3 = (int) $claimed['row']->id;
    $ledger->claimForWork($ledgerId3, 'test-reconciler', 60);
    $ledger->transition($ledgerId3, ExternalOperation::UNCERTAIN, [
        'external_id' => $reconPaymentId,
        'uncertain_reason' => 'integration_test_lost_response',
    ], ExternalOperation::IN_PROGRESS);
    $ledgerRow3 = $ledger->getById($ledgerId3);

    $result = (new BarionReconciler(new BarionClient('test', 'test-pos-key')))->reconcile($ledgerRow3);
    $attempt3 = $attempts->getByKey($idem3);

    $out['step4'] = [
        'result_status' => $result->status,
        'attempt_state' => $attempt3 ? (string) $attempt3->state : '',
        'attempt_charged_minor' => $attempt3 ? (int) $attempt3->charged_amount_minor : 0,
    ];
    $ok4 = $result->status === \FluentCartHungary\Core\Ledger\ProviderCallResult::SUCCESS
        && $attempt3 !== null
        && (string) $attempt3->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $out['step4']['ok'] = $ok4;
    if (!$ok4) {
        $out['errors'][] = 'step4_failed';
    }
} catch (\Throwable $e) {
    $out['step4'] = ['ok' => false];
    $out['errors'][] = 'step4_exception:' . $e->getMessage();
}

$mockRecon->uninstall();

// ---------------------------------------------------------------- Step 5: FC boundary (real order, FC cents)
// A real FluentCart order row stores amount×100 even for HUF (Helper::toCent). The gateway must convert
// to ISO minor units before building the provider payload (Total "4240" for a 4 240 Ft order), and a
// repeated start while an attempt is active must not produce a second external create call.
try {
    $fcOrder = barion_flow_create_order($orderId4, 424000);
    if ($fcOrder === null) {
        $out['step5'] = ['ok' => false, 'skipped' => 'no_fluentcart_order'];
        $out['errors'][] = 'step5_no_order';
    } else {
        $boundaryMock = MockTransport::make()
            ->on(BarionClient::START_PATH, MockTransport::json(200, [
                'PaymentId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'Status' => 'Prepared',
                'GatewayUrl' => 'https://secure.test.barion.com/Pay?id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'Errors' => [],
            ]))
            ->install();

        $paymentInstance = new \FluentCart\App\Services\Payments\PaymentInstance($fcOrder);
        $gateway = new BarionGateway(new BarionClient('test', 'test-pos-key'));
        $first = $gateway->makePaymentFromPaymentInstance($paymentInstance);
        $attempt5 = $attempts->getByKey(BarionStatus::idempotencyKey($orderId4, 0));
        $startCallsAfterFirst = $boundaryMock->callCount(BarionClient::START_PATH);
        $startBody = (string) ($boundaryMock->lastCall(BarionClient::START_PATH)['body'] ?? '');
        $payload = json_decode($startBody, true);
        $totalInPayload = (string) ($payload['Transactions'][0]['Total'] ?? '');

        // Duplicate submit (double click / POST replay): must not create a second external payment start.
        $second = $gateway->makePaymentFromPaymentInstance($paymentInstance);
        $startCallsAfterSecond = $boundaryMock->callCount(BarionClient::START_PATH);
        $boundaryMock->uninstall();

        $out['step5'] = [
            'order_total_cents' => (int) $fcOrder->total_amount,
            'attempt_amount_minor' => $attempt5 ? (int) $attempt5->amount_minor : null,
            'payload_total' => $totalInPayload,
            'provider_start_calls_first' => $startCallsAfterFirst,
            'provider_start_calls_after_duplicate' => $startCallsAfterSecond,
            'first_status' => $first['status'] ?? '',
            'second_status' => $second['status'] ?? '',
        ];
        $ok5 = $attempt5 !== null
            && (int) $attempt5->amount_minor === 4240
            && $totalInPayload === '4240'
            && $startCallsAfterFirst === 1
            && $startCallsAfterSecond === 1;
        $out['step5']['ok'] = $ok5;
        if (!$ok5) {
            $out['errors'][] = 'step5_failed';
        }
    }
} catch (\Throwable $e) {
    $out['step5'] = ['ok' => false];
    $out['errors'][] = 'step5_exception:' . $e->getMessage();
}

$out['ok'] = empty($out['errors'])
    && ($out['step1']['ok'] ?? false)
    && ($out['step2']['ok'] ?? false)
    && ($out['step3']['ok'] ?? false)
    && ($out['step4']['ok'] ?? false)
    && ($out['step5']['ok'] ?? false);

echo function_exists('wp_json_encode') ? wp_json_encode($out) : json_encode($out);
echo "\n";

/**
 * Best-effort real FluentCart order fixture (FC stores amount×100 even for HUF). Returns null when Core is
 * absent or creation fails.
 */
function barion_flow_create_order(int $orderId, int $totalCents): ?object
{
    $orderClass = 'FluentCart\\App\\Models\\Order';
    $itemClass = 'FluentCart\\App\\Models\\OrderItem';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return null;
    }
    try {
        $existing = $orderClass::find($orderId);
        if ($existing !== null) {
            return $existing;
        }
        $order = $orderClass::create([
            'status' => 'pending',
            'payment_status' => 'pending',
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
        if ($order === null) {
            return null;
        }
        $itemClass::create([
            'order_id' => (int) $order->id,
            'title' => 'Lab tétel',
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
        return $order;
    } catch (\Throwable $e) {
        return null;
    }
}
