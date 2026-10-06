<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Payments\Simplepay\SimplepaySignature;
use FluentCartHungary\Modules\Payments\Simplepay\SimplepayStatus;

/**
 * SimplePay contract vectors and pure logic (04_OTP_SIMPLE_CONTRACT.md §2-§5;
 * SimplePay_2.x_Payment_HU_260901.pdf §3.1-§3.18).
 */

// Official documentation vector: doc §3.2 gives the body, the DEMO merchant key published in
// SimplePay_Demo_Merchant_HU_250701.pdf (public test credential) and the expected Signature.
$docBody = <<<'JSON'
{"salt":"f2dbffcef9a3ab94b618ddb59f0da637","merchant":"PUBLICTESTHUF","orderRef":"1853354717597262343977","currency":"HUF","customerEmail":"sdk_test@simplepay.com","language":"HU","sdkVersion":"SimplePay_PHP_SDK_2.1.5_250731:819df13a2304433007f4e3159610f623","methods":["CARD"],"total":"25","timeout":"2025-10-06T07:00:34+02:00","url":"https:\/\/sdk.simplepay.hu\/back.php"}
JSON;
$docKey = 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6';
$docSignature = 'ioi43JEN1utnzPTdJGjuU7we8zgyWh7s0NILYgN0PYLej3OnyjFohbI3YZ747J0v';

fch_test('OTP-SIG matches the official documentation vector', function () use ($docBody, $docKey, $docSignature): void {
    assert_same($docSignature, SimplepaySignature::sign($docBody, $docKey));
    assert_true(SimplepaySignature::verify($docBody, $docSignature, $docKey));
});

fch_test('OTP-SIG rejects tampered body/signature/key and empty key', function () use ($docBody, $docKey, $docSignature): void {
    assert_true(!SimplepaySignature::verify($docBody . ' ', $docSignature, $docKey));
    assert_true(!SimplepaySignature::verify(str_replace('"25"', '"250"', $docBody), $docSignature, $docKey));
    assert_true(!SimplepaySignature::verify($docBody, $docSignature, $docKey . 'x'));
    assert_true(!SimplepaySignature::verify($docBody, 'not-base64!', $docKey));
    assert_true(!SimplepaySignature::verify($docBody, $docSignature, ''));
    assert_true(!SimplepaySignature::verify($docBody, '', $docKey));
});

fch_test('OTP-SIG salt is 32 chars and random', function (): void {
    $a = SimplepaySignature::salt();
    $b = SimplepaySignature::salt();
    assert_same(32, strlen($a));
    assert_true((bool) preg_match('/^[0-9a-f]{32}$/', $a));
    assert_true($a !== $b);
});

fch_test('OTP-SIG sdkVersion follows the documented name:md5 shape', function (): void {
    $version = SimplepaySignature::sdkVersion();
    assert_true((bool) preg_match('/^FluentCartHungary_SimplePay_[^:]+:[0-9a-f]{32}$/', $version), $version);
});

fch_test('OTP-BACK r/s validation uses the decoded JSON string as signature base', function () use ($docKey): void {
    $payload = ['r' => 0, 't' => 99844942, 'e' => 'SUCCESS', 'm' => 'PUBLICTESTHUF', 'o' => '101010515680292482600'];
    $json = json_encode($payload);
    $r = base64_encode($json);
    $s = SimplepaySignature::sign($json, $docKey);

    $decoded = SimplepaySignature::decodeBack($r);
    assert_same($json, $decoded);
    assert_same($json, SimplepaySignature::verifyBack($r, $s, $docKey));
    assert_same(null, SimplepaySignature::verifyBack($r, $s, $docKey . 'x'));

    // '+' arriving as space (form decoding) must not break base64.
    $rWithSpace = str_replace('+', ' ', $r);
    assert_same($json, SimplepaySignature::verifyBack($rWithSpace, $s, $docKey));

    // Re-serializing the JSON would change the byte string and must fail if the signature is over the original.
    $reserialized = json_encode(json_decode($json, true));
    if ($reserialized !== $json) {
        $wrongSig = SimplepaySignature::sign($reserialized, $docKey);
        assert_same(null, SimplepaySignature::verifyBack($r, $wrongSig, $docKey));
    }

    assert_same(null, SimplepaySignature::verifyBack('not-base64', $s, $docKey));
    assert_same(null, SimplepaySignature::decodeBack(base64_encode('not json')));
});

fch_test('OTP-STATUS maps statuses to actions (FINISHED only fulfils)', function (): void {
    assert_same(SimplepayStatus::ACTION_CONFIRM, SimplepayStatus::actionFor('FINISHED'));
    assert_same(SimplepayStatus::ACTION_REJECT, SimplepayStatus::actionFor('NOTAUTHORIZED'));
    assert_same(SimplepayStatus::ACTION_REJECT, SimplepayStatus::actionFor('CANCELLED'));
    assert_same(SimplepayStatus::ACTION_REJECT, SimplepayStatus::actionFor('TIMEOUT'));
    assert_same(SimplepayStatus::ACTION_REJECT, SimplepayStatus::actionFor('REVERSED'));
    assert_same(SimplepayStatus::ACTION_PENDING, SimplepayStatus::actionFor('INIT'));
    assert_same(SimplepayStatus::ACTION_PENDING, SimplepayStatus::actionFor('INPAYMENT'));
    assert_same(SimplepayStatus::ACTION_PENDING, SimplepayStatus::actionFor('INFRAUD'));
    // Two-step hold: never settles a one-step order.
    assert_same(SimplepayStatus::ACTION_PENDING, SimplepayStatus::actionFor('AUTHORIZED'));
    assert_same(SimplepayStatus::ACTION_IGNORE, SimplepayStatus::actionFor('SOMETHING_NEW'));

    assert_true(SimplepayStatus::isFulfillable('FINISHED'));
    assert_true(!SimplepayStatus::isFulfillable('AUTHORIZED'));
    assert_true(SimplepayStatus::isFinalFailure('REVERSED'));
    assert_true(!SimplepayStatus::isFinalFailure('AUTHORIZED'));
    assert_true(SimplepayStatus::isPending('AUTHORIZED'));
});

fch_test('OTP-AMOUNT provider totals convert to minor units per currency', function (): void {
    assert_same(4240, SimplepayStatus::providerAmountToMinor(4240, 'HUF'));
    assert_same(4240, SimplepayStatus::providerAmountToMinor('4240', 'HUF'));
    assert_same(4240, SimplepayStatus::providerAmountToMinor(4240.0, 'HUF'));
    assert_same(1234, SimplepayStatus::providerAmountToMinor(12.34, 'EUR'));
    assert_same(1234, SimplepayStatus::providerAmountToMinor('12.34', 'EUR'));
    assert_same(1200, SimplepayStatus::providerAmountToMinor(12.0, 'EUR'));
    assert_same(null, SimplepayStatus::providerAmountToMinor('abc', 'HUF'));
    assert_same(null, SimplepayStatus::providerAmountToMinor('', 'HUF'));
    assert_same(null, SimplepayStatus::providerAmountToMinor(12.345, 'EUR'));
});

fch_test('OTP-QUERY validation binds merchant/total/reference', function (): void {
    $expect = [
        'merchant' => 'PUBLICTESTHUF',
        'order_ref' => 'fch-990001-1-abcdef01',
        'transaction_id' => '99844942',
        'amount_minor' => 4240,
        'currency' => 'HUF',
    ];
    $tx = [
        'merchant' => 'PUBLICTESTHUF',
        'orderRef' => 'fch-990001-1-abcdef01',
        'transactionId' => 99844942,
        'total' => 4240,
        'status' => 'FINISHED',
        'resultCode' => 'OK',
        'remainingTotal' => 0,
        'method' => 'CARD',
    ];
    $ok = SimplepayStatus::validateQueryTransaction($tx, $expect);
    assert_true($ok['ok'], (string) $ok['reason']);

    $bad = $tx;
    $bad['merchant'] = 'OTHERMERCHANT';
    assert_same('merchant_mismatch', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    $bad = $tx;
    $bad['total'] = 4241;
    assert_same('amount_mismatch', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    $bad = $tx;
    $bad['orderRef'] = 'fch-990001-2-abcdef02';
    assert_same('order_ref_mismatch', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    $bad = $tx;
    $bad['transactionId'] = 99844943;
    assert_same('transaction_id_mismatch', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    $bad = $tx;
    unset($bad['status']);
    assert_same('status_missing', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    $bad = $tx;
    unset($bad['total']);
    assert_same('amount_missing', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    $bad = $tx;
    $bad['total'] = 'x';
    assert_same('amount_unparsable', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);

    // currency present and different -> mismatch; absent -> ok (documented sample has no currency).
    $bad = $tx;
    $bad['currency'] = 'EUR';
    assert_same('currency_mismatch', SimplepayStatus::validateQueryTransaction($bad, $expect)['reason']);
});

fch_test('OTP-QUERY selection prefers the FINISHED transaction', function (): void {
    $response = ['transactions' => [
        ['transactionId' => 1, 'orderRef' => 'ref-a', 'status' => 'INIT'],
        ['transactionId' => 2, 'orderRef' => 'ref-a', 'status' => 'FINISHED'],
        ['transactionId' => 3, 'orderRef' => 'ref-b', 'status' => 'FINISHED'],
    ]];
    $tx = SimplepayStatus::selectQueryTransaction($response, '', 'ref-a');
    assert_same(2, (int) $tx['transactionId']);
    $tx = SimplepayStatus::selectQueryTransaction($response, '3', 'nope');
    assert_same(3, (int) $tx['transactionId']);
    assert_same(null, SimplepayStatus::selectQueryTransaction($response, '', 'ref-c'));
    assert_same(null, SimplepayStatus::selectQueryTransaction(['transactions' => 'x'], '', 'ref-a'));
});

fch_test('OTP-KEYS idempotency key and orderRef are provider/attempt scoped', function (): void {
    assert_same('fch:990001:simplepay:payment_start:0', SimplepayStatus::idempotencyKey(990001, 0));
    $ref = SimplepayStatus::orderRef(990001, 1, 'AbC123ef');
    assert_same('fch-990001-1-abc123ef', $ref);
    assert_true((bool) preg_match('/^fch-990001-2-[0-9a-f]{8}$/', SimplepayStatus::orderRef(990001, 2)));
    assert_true(!SimplepayStatus::isSupportedCurrency('GBP'));
    assert_true(SimplepayStatus::isSupportedCurrency('huf'));
});
