<?php
/**
 * W3 SimplePay flow integration probe — AUTHOR ONLY, DO NOT RUN as part of this task.
 *
 * Run by the integrator on the lab/sandbox WordPress with FluentCart + this plugin active:
 *   wp eval-file fluentcart-hungary/tests/integration/php/simplepay_flow.php
 *
 * Mock-only: the `fch/http/pre_request` seam intercepts every call (no network). The inbound IPN/return
 * payloads are generated with a synthetic test secret and the REAL SimplePay v2 field structure
 * (04_OTP_SIMPLE_CONTRACT.md §2-§5; SimplePay_2.x_Payment_HU_260901.pdf).
 *
 * Contract checks:
 *   1. Signed /v2/start success -> attempt IN_PROGRESS + redirect paymentUrl + ledger IN_PROGRESS.
 *   2. Signed IPN + server-side query FINISHED -> attempt EXTERNAL_CONFIRMED (signed_ipn), order settle
 *      attempted from the authoritative total; reply is a valid signed confirm including receiveDate.
 *   3. Tampered IPN body -> "UNSUCCESSFUL VALIDATION", no state change.
 *   4. Start timeout -> ledger UNCERTAIN + fch:reconcile job (never re-dispatched).
 *   5. Reconciler query read-back proves FINISHED -> confirmed (no second create call).
 *   6. Query amount/merchant mismatch -> uncertain, attempt NOT confirmed.
 *   7. transactionCancel (INIT only, OTP-021) -> signed response verified, status CANCELLED.
 *   8. FC boundary: a real FluentCart order (amount×100) -> payload total "4240" (string), duplicate
 *      submit produces exactly one provider start call.
 *   9. Event order: return-before-IPN and IPN-before-return both end confirmed exactly once.
 *  10. Late callback on a rejected attempt -> late_conflict, no second settlement.
 *  11. Repeat checkout guard: old INIT transaction is cancelled and a second attempt is created; an
 *      already-confirmed order refuses a new start.
 *  12. Parallel replay: two identical IPN deliveries -> one settlement, still one confirmation.
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Database\Schema;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayAttemptService;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayClient;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayConfirmJob;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayGateway;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayIpnHandler;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayReconciler;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayReturnHandler;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepaySignature;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayStatus;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['errors' => []];

// Hard guarantee: no real network. Every mock below adds this catch-all as its LAST rule, so any
// SimplePay URL without an explicit rule (e.g. the pre-start guard query) becomes a transport timeout.
$NO_NETWORK = [];
$noNetwork = static function (MockTransport $mock) use (&$NO_NETWORK): MockTransport {
    return $mock->on('simplepay.hu', MockTransport::timeout());
};

$SECRET = 'test-secret-key-w3';
$MERCHANT = 'TESTMERCHANTHUF';
$START_PATH = SimplepayClient::START_PATH;
$QUERY_PATH = SimplepayClient::QUERY_PATH;
$CANCEL_PATH = SimplepayClient::CANCEL_PATH;

/** @var callable(array<string,mixed>):array<string,mixed> $signed Signed mock JSON response. */
$signed = static function (array $body) use ($SECRET): array {
    $raw = SimplepaySignature::encode($body);
    return MockTransport::response(200, $raw, ['Content-Type' => 'application/json', 'Signature' => SimplepaySignature::sign($raw, $SECRET)]);
};

$creds = ['HUF' => ['merchant' => $MERCHANT, 'secret' => $SECRET]];
$client = static fn(): SimplepayClient => new SimplepayClient('test', 'default', $creds);
$gateway = static fn(): SimplepayGateway => new SimplepayGateway(new SimplepayClient('test', 'default', $creds));

// ---------------------------------------------------------------- fixtures / cleanup
$orderIds = range(990101, 990112);
global $wpdb;
if (is_object($wpdb) && isset($wpdb->prefix) && ($wpdb->prefix ?? '') !== '') {
    foreach ($orderIds as $oid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_payment_attempts WHERE order_id = %d", $oid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d", $oid));
    }
    $wpdb->query("DELETE FROM {$wpdb->prefix}fch_webhook_events WHERE provider = 'simplepay'");
    $wpdb->query("DELETE FROM {$wpdb->prefix}fch_jobs WHERE hook IN ('fch_simplepay_confirm')");
}

// Non-secret test settings so the gateway considers itself configured (secrets via injected client).
$settings = new ModuleSettings();
$settings->setMode('test');
$settings->set('simplepay', 'enabled', true);
$settings->set('simplepay', 'account_id', 'default');
$settings->set('simplepay', 'cancel_init_on_retry', true);
$settings->set('simplepay', 'fallback_email', 'shop@example.test');
$store = new CredentialStore();
$store->set('simplepay', 'test', 'default', 'merchant_huf', $MERCHANT);
$store->set('simplepay', 'test', 'default', 'secret_key_huf', $SECRET);

if (!ReconcilerRegistry::has(SimplepayStatus::PROVIDER)) {
    ReconcilerRegistry::register(SimplepayStatus::PROVIDER, [new SimplepayReconciler(), 'reconcile']);
}

$attempts = new PaymentAttemptRepository();
$ledger = new LedgerRepository();
$attemptService = new SimplepayAttemptService($attempts);

/** Build a query transaction entry in the documented shape (no currency field). */
$txFor = static function (string $orderRef, string $txId, string $status, int $total, string $merchant = ''): array {
    return [
        'salt' => 'q',
        'merchant' => $merchant !== '' ? $merchant : 'TESTMERCHANTHUF',
        'orderRef' => $orderRef,
        'transactionId' => $txId,
        'total' => $total,
        'status' => $status,
        'resultCode' => $status === 'FINISHED' ? 'OK' : '',
        'remainingTotal' => 0,
        'paymentDate' => '2026-10-06T10:00:00+02:00',
        'method' => 'CARD',
    ];
};

$signedIpn = static function (array $fields) use ($SECRET): array {
    $raw = SimplepaySignature::encode($fields);
    return ['body' => $raw, 'signature' => SimplepaySignature::sign($raw, $SECRET)];
};

// ================================================================ Step 1: signed start success
$mock = MockTransport::make()
    ->on($START_PATH, $signed([
        'salt' => SimplepaySignature::salt(),
        'merchant' => $MERCHANT,
        'orderRef' => 'echoed',
        'currency' => 'HUF',
        'transactionId' => 99844942,
        'timeout' => '2026-10-07T10:00:00+02:00',
        'total' => 12345,
        'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL1',
    ]))
    ->install();
try {
    $start = $gateway()->startPaymentAttempt(990101, 'SYNTH990101', 12345, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://shop.example.test/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $attempt1 = $attempts->getByKey(SimplepayStatus::idempotencyKey(990101, 0));
    $ledger1 = $ledger->getByKey(SimplepayStatus::idempotencyKey(990101, 0));
    $out['step1'] = [
        'status' => $start['status'] ?? '',
        'redirect' => $start['redirect_to'] ?? '',
        'attempt_state' => $attempt1 ? (string) $attempt1->state : '',
        'tx' => $attempt1 ? (string) $attempt1->external_payment_id : '',
        'ref' => $attempt1 ? (string) $attempt1->external_request_id : '',
        'merchant' => $attempt1 ? (string) $attempt1->external_ref2 : '',
        'ledger' => $ledger1 ? (string) $ledger1->state : '',
    ];
    $out['step1']['ok'] = ($start['status'] ?? '') === 'success'
        && str_contains((string) ($start['redirect_to'] ?? ''), 'TESTPAYURL1')
        && $attempt1 !== null && (string) $attempt1->state === ExternalOperation::IN_PROGRESS
        && (string) $attempt1->external_payment_id === '99844942'
        && (string) $attempt1->external_ref2 === $MERCHANT
        && str_starts_with((string) $attempt1->external_request_id, 'fch-990101-1-');
    if (!$out['step1']['ok']) {
        $out['errors'][] = 'step1';
    }
} catch (\Throwable $e) {
    $out['step1'] = ['ok' => false];
    $out['errors'][] = 'step1_exception:' . $e->getMessage();
}
$mock->uninstall();

// ================================================================ Step 2: signed IPN + query FINISHED
$ref1 = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990101, 0))->external_request_id;
$queryMock = MockTransport::make()
    ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref1, '99844942', 'FINISHED', 12345)], 'totalCount' => 1]))
    ->install();
try {
    $ipnFields = [
        'salt' => SimplepaySignature::salt(),
        'orderRef' => $ref1,
        'method' => 'CARD',
        'merchant' => $MERCHANT,
        'finishDate' => '2026-10-06T10:05:00+0200',
        'paymentDate' => '2026-10-06T10:01:00+0200',
        'transactionId' => 99844942,
        'status' => 'FINISHED',
    ];
    $ipn = $signedIpn($ipnFields);
    $result = (new SimplepayIpnHandler($client(), null, $attemptService))->handle($ipn['body'], ['Signature' => $ipn['signature']]);
    $attempt1After = $attempts->getByKey(SimplepayStatus::idempotencyKey(990101, 0));
    $confirmValid = isset($result['confirm_body'], $result['confirm_signature'])
        && SimplepaySignature::verify((string) $result['confirm_body'], (string) $result['confirm_signature'], $SECRET)
        && str_contains((string) $result['confirm_body'], 'receiveDate');
    $out['step2'] = [
        'handler' => $result['status'] ?? '',
        'settled' => $result['settled'] ?? '',
        'attempt_state' => $attempt1After ? (string) $attempt1After->state : '',
        'source' => $attempt1After ? (string) $attempt1After->verification_source : '',
        'charged' => $attempt1After ? (int) $attempt1After->charged_amount_minor : 0,
        'confirm_valid' => $confirmValid,
    ];
    $out['step2']['ok'] = ($result['status'] ?? '') === 'confirmed'
        && $confirmValid
        && $attempt1After !== null
        && (string) $attempt1After->state === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $attempt1After->verification_source === 'signed_ipn'
        && (int) $attempt1After->charged_amount_minor === 12345;
    if (!$out['step2']['ok']) {
        $out['errors'][] = 'step2';
    }
} catch (\Throwable $e) {
    $out['step2'] = ['ok' => false];
    $out['errors'][] = 'step2_exception:' . $e->getMessage();
}
$queryMock->uninstall();

// ================================================================ Step 3: tampered IPN
try {
    $tampered = $signedIpn($ipnFields);
    $raw = str_replace('"total"', '"nope"', $tampered['body']);
    $raw = str_replace('"FINISHED"', '"FINISHED"', $raw) . ' '; // byte-level tamper
    $result = (new SimplepayIpnHandler($client(), null, $attemptService))->handle($raw, ['Signature' => $tampered['signature']]);
    $attempt1After = $attempts->getByKey(SimplepayStatus::idempotencyKey(990101, 0));
    $out['step3'] = [
        'handler' => $result['status'] ?? '',
        'body' => (string) ($result['confirm_body'] ?? ''),
        'attempt_state' => $attempt1After ? (string) $attempt1After->state : '',
    ];
    $out['step3']['ok'] = ($result['status'] ?? '') === 'invalid' && $out['step3']['body'] === 'UNSUCCESSFUL VALIDATION'
        && ($attempt1After === null || (string) $attempt1After->state === ExternalOperation::EXTERNAL_CONFIRMED);
    if (!$out['step3']['ok']) {
        $out['errors'][] = 'step3';
    }
} catch (\Throwable $e) {
    $out['step3'] = ['ok' => false];
    $out['errors'][] = 'step3_exception:' . $e->getMessage();
}

// ================================================================ Step 4: start timeout -> UNCERTAIN + job
$timeoutMock = MockTransport::make()->on($START_PATH, MockTransport::timeout())->install();
try {
    $startTimeout = $gateway()->startPaymentAttempt(990102, 'SYNTH990102', 5000, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://sandbox.simplepay.hu/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $attempt2 = $attempts->getByKey(SimplepayStatus::idempotencyKey(990102, 0));
    $ledger2 = $ledger->getByKey(SimplepayStatus::idempotencyKey(990102, 0));
    $ledgerId2 = $ledger2 ? (int) $ledger2->id : 0;
    $jobsTable = Schema::table('fch_jobs');
    $reconcileJob = $ledgerId2 > 0
        ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$jobsTable}` WHERE job_key = %s", 'fch:reconcile:' . $ledgerId2))
        : '0';
    $out['step4'] = [
        'start_status' => $startTimeout['status'] ?? '',
        'ledger' => $ledger2 ? (string) $ledger2->state : '',
        'reconcile_jobs' => (int) $reconcileJob,
    ];
    $out['step4']['ok'] = ($startTimeout['status'] ?? '') === 'failed'
        && $ledger2 !== null && (string) $ledger2->state === ExternalOperation::UNCERTAIN
        && (int) $reconcileJob === 1;
    if (!$out['step4']['ok']) {
        $out['errors'][] = 'step4';
    }
} catch (\Throwable $e) {
    $out['step4'] = ['ok' => false];
    $out['errors'][] = 'step4_exception:' . $e->getMessage();
}
$timeoutMock->uninstall();

// ================================================================ Step 5: reconciler read-back proves success
try {
    $idem2 = SimplepayStatus::idempotencyKey(990102, 0);
    $attempt2 = $attempts->getByKey($idem2);
    $ref2 = (string) $attempt2->external_request_id;
    $reconMock = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref2, '99844943', 'FINISHED', 5000)], 'totalCount' => 1]))
        ->install();
    $ledgerRow2 = $ledger->getByKey($idem2);
    // UNCERTAIN -> AWAITING_RECONCILIATION hop happens inside the reconciler.
    $result = (new SimplepayReconciler(new SimplepayClient('test', 'default', $creds)))->reconcile($ledgerRow2);
    $attempt2After = $attempts->getByKey($idem2);
    $reconMock->uninstall();
    $out['step5'] = [
        'result' => $result->status,
        'attempt_state' => $attempt2After ? (string) $attempt2After->state : '',
        'start_calls_after_reconcile' => $reconMock->callCount($START_PATH),
    ];
    $out['step5']['ok'] = $result->status === \FluentCartHungary\Core\Ledger\ProviderCallResult::SUCCESS
        && $attempt2After !== null && (string) $attempt2After->state === ExternalOperation::EXTERNAL_CONFIRMED
        && $reconMock->callCount($START_PATH) === 0;
    if (!$out['step5']['ok']) {
        $out['errors'][] = 'step5';
    }
} catch (\Throwable $e) {
    $out['step5'] = ['ok' => false];
    $out['errors'][] = 'step5_exception:' . $e->getMessage();
}

// ================================================================ Step 6: query mismatch -> uncertain, no confirm
try {
    $idem3 = SimplepayStatus::idempotencyKey(990103, 0);
    $ref3 = SimplepayStatus::orderRef(990103, 1, 'feed0003');
    $attempts->getOrCreate([
        'order_id' => 990103,
        'order_uuid' => 'SYNTH990103',
        'provider' => 'simplepay',
        'mode' => 'test',
        'account_id' => 'default',
        'attempt_no' => 1,
        'idempotency_key' => $idem3,
        'amount_minor' => 7000,
        'currency' => 'HUF',
        'external_payment_id' => '99844944',
        'external_request_id' => $ref3,
        'external_ref2' => $MERCHANT,
    ]);
    $claimed = $ledger->claim([
        'idempotency_key' => $idem3,
        'order_id' => 990103,
        'module' => 'payments',
        'provider' => 'simplepay',
        'kind' => 'payment_start',
        'mode' => 'test',
        'account_id' => 'default',
        'currency' => 'HUF',
        'amount_minor' => 7000,
    ]);
    $ledgerId3 = (int) $claimed['row']->id;
    $ledger->claimForWork($ledgerId3, 'test-reconciler', 60);
    $ledger->transition($ledgerId3, ExternalOperation::UNCERTAIN, [
        'external_id' => '99844944',
        'business_reference' => $ref3,
        'uncertain_reason' => 'integration_test_lost_response',
    ], ExternalOperation::IN_PROGRESS);

    $mismatchMock = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref3, '99844944', 'FINISHED', 7001)], 'totalCount' => 1]))
        ->install();
    $result = (new SimplepayReconciler(new SimplepayClient('test', 'default', $creds)))->reconcile($ledger->getById($ledgerId3));
    $attempt3 = $attempts->getByKey($idem3);
    $mismatchMock->uninstall();
    $out['step6'] = [
        'result' => $result->status,
        'code' => $result->code,
        'attempt_state' => $attempt3 ? (string) $attempt3->state : '',
    ];
    $out['step6']['ok'] = $result->status === \FluentCartHungary\Core\Ledger\ProviderCallResult::UNCERTAIN
        && str_contains((string) $result->code, 'AMOUNT_MISMATCH')
        && $attempt3 !== null && (string) $attempt3->state !== ExternalOperation::EXTERNAL_CONFIRMED;
    if (!$out['step6']['ok']) {
        $out['errors'][] = 'step6';
    }
} catch (\Throwable $e) {
    $out['step6'] = ['ok' => false];
    $out['errors'][] = 'step6_exception:' . $e->getMessage();
}

// ================================================================ Step 7: transactionCancel (INIT only)
$cancelMock = MockTransport::make()
    ->on($CANCEL_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactionId' => '99844942', 'orderRef' => $ref1, 'status' => 'CANCELLED']))
    ->install();
try {
    $response = $client()->cancelTransaction('99844942', null, 'HUF');
    $body = $response->json();
    $out['step7'] = [
        'status' => (int) $response->status,
        'cancelled' => (string) ($body['status'] ?? ''),
        'signed' => SimplepayClient::isSignedResponse($response, $SECRET),
        'calls' => $cancelMock->callCount($CANCEL_PATH),
    ];
    $out['step7']['ok'] = $response->isSuccess() && $out['step7']['cancelled'] === 'CANCELLED'
        && $out['step7']['signed'] && $cancelMock->callCount($CANCEL_PATH) === 1;
    if (!$out['step7']['ok']) {
        $out['errors'][] = 'step7';
    }
} catch (\Throwable $e) {
    $out['step7'] = ['ok' => false];
    $out['errors'][] = 'step7_exception:' . $e->getMessage();
}
$cancelMock->uninstall();

// ================================================================ Step 8: FC boundary (real order, FC cents)
try {
    $fcOrder = simplepay_flow_create_order(990108, 424000);
    if ($fcOrder === null) {
        $out['step8'] = ['ok' => false, 'skipped' => 'no_fluentcart_order'];
        $out['errors'][] = 'step8_no_order';
    } else {
        $startOk8 = $signed([
            'salt' => SimplepaySignature::salt(),
            'merchant' => $MERCHANT,
            'orderRef' => 'x',
            'currency' => 'HUF',
            'transactionId' => 99844950,
            'timeout' => '2026-10-07T10:00:00+02:00',
            'total' => 4240,
            'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL8',
        ]);
        $boundaryMock = MockTransport::make()
            // first guard query: payer is INPAYMENT -> a repeat submit must be refused, no second charge
            ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [
                $txFor(SimplepayStatus::orderRef(990108, 1, 'feed0008'), '99844950', 'INPAYMENT', 4240),
            ], 'totalCount' => 1]), 1)
            // then the old transaction is still INIT -> cancel + new attempt
            ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [
                $txFor(SimplepayStatus::orderRef(990108, 1, 'feed0008'), '99844950', 'INIT', 4240),
            ], 'totalCount' => 1]))
            ->on($CANCEL_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactionId' => '99844950', 'orderRef' => 'x', 'status' => 'CANCELLED']))
            ->on($START_PATH, $startOk8);
        $noNetwork($boundaryMock)->install();

        $paymentInstance = new \FluentCart\App\Services\Payments\PaymentInstance($fcOrder);
        $first = $gateway()->makePaymentFromPaymentInstance($paymentInstance);
        $attempt8 = $attempts->getByKey(SimplepayStatus::idempotencyKey(990108, 0));
        $callsAfterFirst = $boundaryMock->callCount($START_PATH);
        $startBody = (string) ($boundaryMock->lastCall($START_PATH)['body'] ?? '');
        $payload = json_decode($startBody, true);

        // Second submit: the guard sees the first transaction INPAYMENT (payer possibly in flight) ->
        // refused without any new provider start (no double charge).
        $second = $gateway()->makePaymentFromPaymentInstance($paymentInstance);
        $callsAfterSecond = $boundaryMock->callCount($START_PATH);

        // Third submit: the open transaction is still INIT -> documented cancel + attempt 2.
        $third = $gateway()->makePaymentFromPaymentInstance($paymentInstance);
        $callsAfterThird = $boundaryMock->callCount($START_PATH);
        $cancelCalls = $boundaryMock->callCount($CANCEL_PATH);
        $attempt8c = $attempts->getByKey(SimplepayStatus::idempotencyKey(990108, 1));
        $boundaryMock->uninstall();

        $out['step8'] = [
            'order_total_cents' => (int) $fcOrder->total_amount,
            'attempt_amount_minor' => $attempt8 ? (int) $attempt8->amount_minor : null,
            'payload_total_type' => gettype($payload['total'] ?? null),
            'payload_total' => (string) ($payload['total'] ?? ''),
            'calls_first' => $callsAfterFirst,
            'calls_after_second' => $callsAfterSecond,
            'calls_after_third' => $callsAfterThird,
            'cancel_calls' => $cancelCalls,
            'attempt2_tx' => $attempt8c ? (string) $attempt8c->external_payment_id : '',
            'first_status' => $first['status'] ?? '',
            'second_status' => $second['status'] ?? '',
            'second_message' => $second['message'] ?? '',
            'third_status' => $third['status'] ?? '',
            'auth_method' => (string) ($payload['threeDSReqAuthMethod'] ?? ''),
            'methods' => implode(',', (array) ($payload['methods'] ?? [])),
        ];
        $out['step8']['ok'] = $attempt8 !== null
            && (int) $attempt8->amount_minor === 4240
            && ($payload['total'] ?? null) === '4240'
            && $callsAfterFirst === 1
            && $callsAfterSecond === 1
            && $callsAfterThird === 2
            && $cancelCalls === 1
            && ($first['status'] ?? '') === 'success'
            && ($second['status'] ?? '') === 'failed'
            && ($second['message'] ?? '') === 'simplepay_guard_attempt_in_progress'
            && ($third['status'] ?? '') === 'success'
            && $attempt8c !== null;
        if (!$out['step8']['ok']) {
            $out['errors'][] = 'step8';
        }
    }
} catch (\Throwable $e) {
    $out['step8'] = ['ok' => false];
    $out['errors'][] = 'step8_exception:' . $e->getMessage();
}

// ================================================================ Step 9: event order return<->IPN
try {
    // 9a: return FIRST for order 990104 (INIT attempt), then IPN confirms.
    $startMock = MockTransport::make()
        ->on($START_PATH, $signed([
            'salt' => 's', 'merchant' => $MERCHANT, 'orderRef' => 'x', 'currency' => 'HUF',
            'transactionId' => 99844960, 'timeout' => '2026-10-07T10:00:00+02:00', 'total' => 6000,
            'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL9',
        ]))
        ->install();
    $gateway()->startPaymentAttempt(990104, 'SYNTH990104', 6000, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://sandbox.simplepay.hu/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $startMock->uninstall();
    $ref9 = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990104, 0))->external_request_id;
    $txId9 = '99844960';
    $payload9 = ['r' => 0, 't' => $txId9, 'e' => 'SUCCESS', 'm' => $MERCHANT, 'o' => $ref9];
    $r9 = base64_encode(SimplepaySignature::encode($payload9));
    $s9 = SimplepaySignature::sign(SimplepaySignature::encode($payload9), $SECRET);
    $enqueued = [];
    $returnHandler = new SimplepayReturnHandler(new SimplepayClient('test', 'default', $creds), null, function (int $attemptId) use (&$enqueued): void {
        $enqueued[] = $attemptId;
    });
    $returnResult = $returnHandler->handle(['r' => $r9, 's' => $s9]);
    $enqueuedAfterA = count($enqueued);
    $stateAfterReturn = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990104, 0))->state;

    $query9 = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref9, $txId9, 'FINISHED', 6000)], 'totalCount' => 1]))
        ->install();
    $ipn9 = $signedIpn(['salt' => 's', 'orderRef' => $ref9, 'method' => 'CARD', 'merchant' => $MERCHANT, 'finishDate' => '2026-10-06T11:00:00+0200', 'paymentDate' => '2026-10-06T10:59:00+0200', 'transactionId' => $txId9, 'status' => 'FINISHED']);
    $ipnResult9 = (new SimplepayIpnHandler($client(), null, $attemptService))->handle($ipn9['body'], ['Signature' => $ipn9['signature']]);
    $query9->uninstall();
    $stateAfterIpn = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990104, 0))->state;

    // 9b: IPN FIRST for order 990105, then the browser return only schedules reconciliation.
    $startMock5 = MockTransport::make()
        ->on($START_PATH, $signed([
            'salt' => 's', 'merchant' => $MERCHANT, 'orderRef' => 'x', 'currency' => 'HUF',
            'transactionId' => 99844961, 'timeout' => '2026-10-07T10:00:00+02:00', 'total' => 6100,
            'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL10',
        ]))
        ->install();
    $gateway()->startPaymentAttempt(990105, 'SYNTH990105', 6100, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://sandbox.simplepay.hu/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $startMock5->uninstall();
    $ref10 = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990105, 0))->external_request_id;
    $txId10 = '99844961';
    $query10 = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref10, $txId10, 'FINISHED', 6100)], 'totalCount' => 1]))
        ->install();
    $ipn10 = $signedIpn(['salt' => 's', 'orderRef' => $ref10, 'method' => 'CARD', 'merchant' => $MERCHANT, 'finishDate' => '2026-10-06T11:10:00+0200', 'paymentDate' => '2026-10-06T11:09:00+0200', 'transactionId' => $txId10, 'status' => 'FINISHED']);
    $ipnResult10 = (new SimplepayIpnHandler($client(), null, $attemptService))->handle($ipn10['body'], ['Signature' => $ipn10['signature']]);
    $query10->uninstall();
    $payload10 = ['r' => 0, 't' => $txId10, 'e' => 'SUCCESS', 'm' => $MERCHANT, 'o' => $ref10];
    $r10 = base64_encode(SimplepaySignature::encode($payload10));
    $s10 = SimplepaySignature::sign(SimplepaySignature::encode($payload10), $SECRET);
    $returnResult10 = $returnHandler->handle(['r' => $r10, 's' => $s10]);
    $state10 = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990105, 0))->state;

    $out['step9'] = [
        'return_status' => $returnResult['status'] ?? '',
        'return_enqueued' => $enqueuedAfterA,
        'return_enqueued_total' => count($enqueued),
        'state_after_return' => $stateAfterReturn,
        'ipn_status' => $ipnResult9['status'] ?? '',
        'state_after_ipn' => $stateAfterIpn,
        'second_ipn_status' => $ipnResult10['status'] ?? '',
        'second_return_status' => $returnResult10['status'] ?? '',
        'second_state' => $state10,
    ];
    $out['step9']['ok'] = ($returnResult['status'] ?? '') === 'scheduled'
        && $enqueuedAfterA === 1
        && count($enqueued) === 2
        && $stateAfterReturn === ExternalOperation::IN_PROGRESS
        && ($ipnResult9['status'] ?? '') === 'confirmed'
        && $stateAfterIpn === ExternalOperation::EXTERNAL_CONFIRMED
        && ($ipnResult10['status'] ?? '') === 'confirmed'
        && ($returnResult10['status'] ?? '') === 'scheduled'
        && $state10 === ExternalOperation::EXTERNAL_CONFIRMED;
    if (!$out['step9']['ok']) {
        $out['errors'][] = 'step9';
    }
} catch (\Throwable $e) {
    $out['step9'] = ['ok' => false];
    $out['errors'][] = 'step9_exception:' . $e->getMessage();
}

// ================================================================ Step 10: late callback on rejected attempt
try {
    $idem11 = SimplepayStatus::idempotencyKey(990106, 0);
    $ref11 = SimplepayStatus::orderRef(990106, 1, 'feed0011');
    $attempts->getOrCreate([
        'order_id' => 990106, 'order_uuid' => 'SYNTH990106', 'provider' => 'simplepay', 'mode' => 'test',
        'account_id' => 'default', 'attempt_no' => 1, 'idempotency_key' => $idem11, 'amount_minor' => 8000,
        'currency' => 'HUF', 'external_payment_id' => '99844962', 'external_request_id' => $ref11,
        'external_ref2' => $MERCHANT,
    ]);
    $attempt11 = $attempts->getByKey($idem11);
    $attempts->markStarted((int) $attempt11->id, []);
    $attempts->markRejected((int) $attempt11->id, ['verification_source' => 'query', 'raw_state' => 'CANCELLED']);

    $lateMock = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref11, '99844962', 'FINISHED', 8000)], 'totalCount' => 1]))
        ->install();
    $ipn11 = $signedIpn(['salt' => 's', 'orderRef' => $ref11, 'method' => 'CARD', 'merchant' => $MERCHANT, 'finishDate' => '2026-10-06T12:00:00+0200', 'paymentDate' => '2026-10-06T11:59:00+0200', 'transactionId' => '99844962', 'status' => 'FINISHED']);
    $lateResult = (new SimplepayIpnHandler($client(), null, $attemptService))->handle($ipn11['body'], ['Signature' => $ipn11['signature']]);
    $lateMock->uninstall();
    $state11 = (string) $attempts->getByKey($idem11)->state;
    $out['step10'] = [
        'handler' => $lateResult['status'] ?? '',
        'state' => $state11,
        'settled' => $lateResult['settled'] ?? '',
    ];
    $out['step10']['ok'] = ($lateResult['status'] ?? '') === 'late_conflict'
        && $state11 === ExternalOperation::PROVEN_REJECTED
        && ($lateResult['settled'] ?? '') !== 'settled';
    if (!$out['step10']['ok']) {
        $out['errors'][] = 'step10';
    }
} catch (\Throwable $e) {
    $out['step10'] = ['ok' => false];
    $out['errors'][] = 'step10_exception:' . $e->getMessage();
}

// ================================================================ Step 11: repeat checkout guard
try {
    // 11a: order 990107 has one open INIT transaction; a second start must cancel it and create attempt 2.
    $start11 = MockTransport::make()
        ->on($START_PATH, $signed([
            'salt' => 's', 'merchant' => $MERCHANT, 'orderRef' => 'x', 'currency' => 'HUF',
            'transactionId' => 99844970, 'timeout' => '2026-10-07T10:00:00+02:00', 'total' => 9000,
            'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL11',
        ]))
        ->install();
    $first11 = $gateway()->startPaymentAttempt(990107, 'SYNTH990107', 9000, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://sandbox.simplepay.hu/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $start11->uninstall();
    $attempt11a = $attempts->getByKey(SimplepayStatus::idempotencyKey(990107, 0));
    $ref11a = (string) $attempt11a->external_request_id;

    $guardMock = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref11a, '99844970', 'INIT', 9000)], 'totalCount' => 1]))
        ->on($CANCEL_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactionId' => '99844970', 'orderRef' => $ref11a, 'status' => 'CANCELLED']))
        ->on($START_PATH, $signed([
            'salt' => 's', 'merchant' => $MERCHANT, 'orderRef' => 'x', 'currency' => 'HUF',
            'transactionId' => 99844971, 'timeout' => '2026-10-07T10:00:00+02:00', 'total' => 9000,
            'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL11B',
        ]))
        ->install();
    $second11 = $gateway()->startPaymentAttempt(990107, 'SYNTH990107', 9000, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://sandbox.simplepay.hu/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $cancelCalls = $guardMock->callCount($CANCEL_PATH);
    $startCalls = $guardMock->callCount($START_PATH);
    $attempt11b = $attempts->getByKey(SimplepayStatus::idempotencyKey(990107, 1));
    $guardMock->uninstall();

    // 11b: order 990101 is confirmed -> a new start must be refused without any provider call.
    $paidMock = MockTransport::make()->on($START_PATH, $signed(['salt' => 's']))->install();
    $paidStart = $gateway()->startPaymentAttempt(990101, 'SYNTH990101', 12345, 'HUF', 'https://shop.example.test/?fch_simplepay_return=1', 'https://sandbox.simplepay.hu/?fluent-cart=fct_payment_listener_ipn&method=simplepay');
    $paidCalls = $paidMock->callCount($START_PATH);
    $paidMock->uninstall();

    $out['step11'] = [
        'second_status' => $second11['status'] ?? '',
        'cancel_calls' => $cancelCalls,
        'start_calls' => $startCalls,
        'attempt2' => $attempt11b ? (string) $attempt11b->external_payment_id : '',
        'paid_status' => $paidStart['status'] ?? '',
        'paid_message' => $paidStart['message'] ?? '',
        'paid_start_calls' => $paidCalls,
    ];
    $out['step11']['ok'] = ($second11['status'] ?? '') === 'success'
        && $cancelCalls === 1 && $startCalls === 1
        && $attempt11b !== null && (string) $attempt11b->external_payment_id === '99844971'
        && ($paidStart['status'] ?? '') === 'failed'
        && ($paidStart['message'] ?? '') === 'simplepay_order_already_paid'
        && $paidCalls === 0;
    if (!$out['step11']['ok']) {
        $out['errors'][] = 'step11';
    }
} catch (\Throwable $e) {
    $out['step11'] = ['ok' => false];
    $out['errors'][] = 'step11_exception:' . $e->getMessage();
}

// ================================================================ Step 12: parallel replay -> one settlement
try {
    $idem12 = SimplepayStatus::idempotencyKey(990109, 0);
    $ref12 = SimplepayStatus::orderRef(990109, 1, 'feed0012');
    $attempts->getOrCreate([
        'order_id' => 990109, 'order_uuid' => 'SYNTH990109', 'provider' => 'simplepay', 'mode' => 'test',
        'account_id' => 'default', 'attempt_no' => 1, 'idempotency_key' => $idem12, 'amount_minor' => 10000,
        'currency' => 'HUF', 'external_payment_id' => '99844980', 'external_request_id' => $ref12,
        'external_ref2' => $MERCHANT,
    ]);
    $replayMock = MockTransport::make()
        ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref12, '99844980', 'FINISHED', 10000)], 'totalCount' => 1]))
        ->install();
    $ipn12 = $signedIpn(['salt' => 's', 'orderRef' => $ref12, 'method' => 'CARD', 'merchant' => $MERCHANT, 'finishDate' => '2026-10-06T13:00:00+0200', 'paymentDate' => '2026-10-06T12:59:00+0200', 'transactionId' => '99844980', 'status' => 'FINISHED']);
    $handler = new SimplepayIpnHandler($client(), null, $attemptService);
    $replay1 = $handler->handle($ipn12['body'], ['Signature' => $ipn12['signature']]);
    $replay2 = $handler->handle($ipn12['body'], ['Signature' => $ipn12['signature']]);
    $replayMock->uninstall();
    $attempt12 = $attempts->getByKey($idem12);
    $webhookCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fch_webhook_events WHERE provider = 'simplepay' AND external_ref = %s", '99844980'));
    $out['step12'] = [
        'first' => $replay1['status'] ?? '',
        'second' => $replay2['status'] ?? '',
        'state' => $attempt12 ? (string) $attempt12->state : '',
        'charged' => $attempt12 ? (int) $attempt12->charged_amount_minor : 0,
        'webhook_rows' => $webhookCount,
    ];
    $out['step12']['ok'] = ($replay1['status'] ?? '') === 'confirmed'
        && ($replay2['status'] ?? '') === 'confirmed'
        && $attempt12 !== null && (string) $attempt12->state === ExternalOperation::EXTERNAL_CONFIRMED
        && (int) $attempt12->charged_amount_minor === 10000
        && $webhookCount === 1;
    if (!$out['step12']['ok']) {
        $out['errors'][] = 'step12';
    }
} catch (\Throwable $e) {
    $out['step12'] = ['ok' => false];
    $out['errors'][] = 'step12_exception:' . $e->getMessage();
}

// ================================================================ Step 13: real FC order settles once
try {
    $fcOrder13 = simplepay_flow_create_order(990110, 424000);
    if ($fcOrder13 === null) {
        $out['step13'] = ['ok' => false, 'skipped' => 'no_fluentcart_order'];
        $out['errors'][] = 'step13_no_order';
    } else {
        // FluentCart normally creates the charge transaction during checkout; mirror that here.
        $txnClass = 'FluentCart\\App\\Models\\OrderTransaction';
        $existingTxn = $txnClass::query()->where('order_id', 990110)
            ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE)->first();
        if (!$existingTxn) {
            $txnClass::create([
                'order_id' => 990110,
                'order_type' => 'payment',
                'payment_method' => 'simplepay',
                'payment_mode' => 'test',
                'currency' => 'HUF',
                'transaction_type' => \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE,
                'status' => \FluentCart\App\Helpers\Status::TRANSACTION_PENDING,
                'total' => 424000,
                'uuid' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : 'txn-990110',
            ]);
        }
        $startMock13 = MockTransport::make()
            ->on($START_PATH, $signed([
                'salt' => 's', 'merchant' => $MERCHANT, 'orderRef' => 'x', 'currency' => 'HUF',
                'transactionId' => 99844990, 'timeout' => '2026-10-07T10:00:00+02:00', 'total' => 4240,
                'paymentUrl' => 'https://sandbox.simplepay.hu/pay/pay/pspHU/TESTPAYURL13',
            ]))
            ->install();
        $start13 = $gateway()->makePaymentFromPaymentInstance(new \FluentCart\App\Services\Payments\PaymentInstance($fcOrder13));
        $startMock13->uninstall();
        $ref13 = (string) $attempts->getByKey(SimplepayStatus::idempotencyKey(990110, 0))->external_request_id;

        $queryMock13 = MockTransport::make()
            ->on($QUERY_PATH, $signed(['salt' => 's', 'merchant' => $MERCHANT, 'transactions' => [$txFor($ref13, '99844990', 'FINISHED', 4240)], 'totalCount' => 1]))
            ->install();
        $ipn13 = $signedIpn(['salt' => 's', 'orderRef' => $ref13, 'method' => 'CARD', 'merchant' => $MERCHANT, 'finishDate' => '2026-10-06T14:00:00+0200', 'paymentDate' => '2026-10-06T13:59:00+0200', 'transactionId' => '99844990', 'status' => 'FINISHED']);
        $ipnResult13 = (new SimplepayIpnHandler($client(), null, $attemptService))->handle($ipn13['body'], ['Signature' => $ipn13['signature']]);
        $queryMock13->uninstall();
        $order13 = \FluentCart\App\Models\Order::query()->find(990110);
        $out['step13'] = [
            'start' => $start13['status'] ?? '',
            'ipn' => $ipnResult13['status'] ?? '',
            'settled' => $ipnResult13['settled'] ?? '',
            'payment_status' => $order13 ? (string) $order13->payment_status : '',
        ];
        $out['step13']['ok'] = ($start13['status'] ?? '') === 'success'
            && ($ipnResult13['status'] ?? '') === 'confirmed'
            && ($ipnResult13['settled'] ?? '') === 'settled'
            && $order13 !== null && (string) $order13->payment_status === 'paid';
        if (!$out['step13']['ok']) {
            $out['errors'][] = 'step13';
        }
    }
} catch (\Throwable $e) {
    $out['step13'] = ['ok' => false];
    $out['errors'][] = 'step13_exception:' . $e->getMessage();
}

// ================================================================ result
$out['ok'] = empty($out['errors']);
foreach (['step1', 'step2', 'step3', 'step4', 'step5', 'step6', 'step7', 'step8', 'step9', 'step10', 'step11', 'step12', 'step13'] as $step) {
    $out['ok'] = $out['ok'] && (($out[$step]['ok'] ?? false) === true);
}

echo function_exists('wp_json_encode') ? wp_json_encode($out) : json_encode($out);
echo "\n";

/**
 * Best-effort real FluentCart order fixture (FC stores amount×100 even for HUF).
 */
function simplepay_flow_create_order(int $orderId, int $totalCents): ?object
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
        if ($actual !== $orderId && is_object($wpdb)) {
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
