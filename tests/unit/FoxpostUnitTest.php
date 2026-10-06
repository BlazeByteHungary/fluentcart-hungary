<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostClient;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostLabelService;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostParcelService;
use FluentCartHungary\Modules\Shipping\Foxpost\FoxpostTracking;

/**
 * Pure unit tests for the Foxpost W2 module (04_FOXPOST_CONTRACT.md §2 auth, §4 op 1/9, §5 limits, §6/§7).
 * No WordPress, no HTTP: only pure static/instance helpers are exercised (client header shaping, payload
 * mapping, retry allowance, FieldError parsing, tracking mapping, label geometry).
 */

/** @return array<string,mixed> APM snapshot by default */
function foxpost_snapshot(array $overrides = []): array
{
    $base = [
        'order_id' => 992001,
        'idempotency_key' => 'fch:992001:foxpost:label_create:1',
        'mode' => 'test',
        'delivery_mode' => 'APM',
        'destination' => 'hu35',
        'unique_barcode' => 'FCHU992001',
        'ref_code' => 'FCH-992001',
        'recipient' => [
            'name' => 'Teszt Elek',
            'phone' => '+36301234567',
            'email' => 'teszt@example.hu',
            'country' => 'HU',
            'city' => 'Budapest',
            'zip' => '1051',
            'address' => 'Fő utca 1.',
        ],
        'cod_minor' => 0,
        'currency' => 'HUF',
        'size' => 'M',
        'comment' => '',
        'fragile' => false,
        'delivery_note' => '',
        'phone_validation' => true,
    ];
    return array_replace_recursive($base, $overrides);
}

/** @return list<string> */
function foxpost_errors(array $snapshot): array
{
    return FoxpostParcelService::validate($snapshot);
}

fch_test('Foxpost client: HTTP Basic + api-key header shaping and environment hosts', function (): void {
    $client = new FoxpostClient('test', 'user', 'pass', 'KEY');
    $headers = $client->authHeaders();
    assert_same('Basic ' . base64_encode('user:pass'), $headers['Authorization']);
    assert_same('KEY', $headers['api-key']);
    assert_same(FoxpostClient::TEST_BASE, $client->baseUrl());
    assert_true($client->hasCredentials());

    $live = new FoxpostClient('live', 'user', 'pass', 'KEY');
    assert_same(FoxpostClient::LIVE_BASE, $live->baseUrl());
    assert_same('live', $live->mode());

    $unknown = new FoxpostClient('something', 'user', 'pass', 'KEY');
    assert_same(FoxpostClient::TEST_BASE, $unknown->baseUrl(), 'non-live mode must default to the test host');

    $partial = new FoxpostClient('test', '', '', '');
    assert_same([], $partial->authHeaders(), 'an unconfigured client must not emit an empty Basic header');
    assert_true(!$partial->hasCredentials());
});

fch_test('Foxpost parcel: APM payload uses destination (no HD address fields)', function (): void {
    $built = FoxpostParcelService::build(foxpost_snapshot());
    assert_true($built['ok'], implode(',', $built['errors']));
    $payload = $built['payload'];

    assert_same('hu35', $payload['destination']);
    assert_same('FCHU992001', $payload['uniqueBarcode']);
    assert_same('HU', $payload['recipientCountry']);
    assert_same('M', $payload['size']);
    assert_true(!isset($payload['recipientCity']), 'APM payload must not carry recipientCity');
    assert_true(!isset($payload['recipientZip']), 'APM payload must not carry recipientZip');
    assert_true(!isset($payload['recipientAddress']), 'APM payload must not carry recipientAddress');
});

fch_test('Foxpost parcel: HD payload uses recipient address (no destination/uniqueBarcode)', function (): void {
    $snapshot = foxpost_snapshot([
        'delivery_mode' => 'HD',
        'destination' => '',
        'unique_barcode' => '',
    ]);
    $built = FoxpostParcelService::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));
    $payload = $built['payload'];

    assert_same('Budapest', $payload['recipientCity']);
    assert_same('1051', $payload['recipientZip']);
    assert_same('Fő utca 1.', $payload['recipientAddress']);
    assert_true(!isset($payload['destination']), 'HD payload must not carry destination');
    assert_true(!isset($payload['uniqueBarcode']), 'HD payload must not carry uniqueBarcode');
});

fch_test('Foxpost parcel: uniqueBarcode semantics drive the retry allowance (04_FOXPOST §7)', function (): void {
    assert_same(2, FoxpostParcelService::maxAttempts(foxpost_snapshot()), 'APM + uniqueBarcode is conditionally retry-safe');
    assert_same(1, FoxpostParcelService::maxAttempts(foxpost_snapshot(['unique_barcode' => ''])), 'APM without uniqueBarcode is not retry-safe');
    assert_same(1, FoxpostParcelService::maxAttempts(foxpost_snapshot(['delivery_mode' => 'HD', 'destination' => '', 'unique_barcode' => 'FCHU1'])), 'HD has no client unique key');

    $tooLong = foxpost_errors(foxpost_snapshot(['unique_barcode' => str_repeat('A', 21)]));
    assert_true(in_array('INVALID_FORMAT', $tooLong, true), 'uniqueBarcode max 20 (04_FOXPOST §5)');
});

fch_test('Foxpost parcel: COD integer range 0..1 000 000 with INVALID_COD', function (): void {
    $atMax = FoxpostParcelService::build(foxpost_snapshot(['cod_minor' => 1000000]));
    assert_true($atMax['ok'], implode(',', $atMax['errors']));
    assert_same(1000000, $atMax['payload']['cod']);

    assert_true(in_array('INVALID_COD', foxpost_errors(foxpost_snapshot(['cod_minor' => 1000001])), true));
    assert_true(in_array('INVALID_COD', foxpost_errors(foxpost_snapshot(['cod_minor' => -1])), true));
});

fch_test('Foxpost parcel: local validation covers recipient fields, size and HD zip', function (): void {
    assert_true(in_array('MISSING_RECIPIENT_NAME', foxpost_errors(foxpost_snapshot(['recipient' => ['name' => '']])), true));
    assert_true(in_array('INVALID_PHONE', foxpost_errors(foxpost_snapshot(['recipient' => ['phone' => '06301234567']])), true));
    assert_true(in_array('MISSING_RECIPIENT_EMAIL', foxpost_errors(foxpost_snapshot(['recipient' => ['email' => '']])), true));
    assert_true(in_array('INVALID_EMAIL', foxpost_errors(foxpost_snapshot(['recipient' => ['email' => 'not-an-email']])), true));
    assert_true(in_array('INVALID_APM_ID', foxpost_errors(foxpost_snapshot(['destination' => ''])), true));
    assert_true(in_array('INVALID_SIZE', foxpost_errors(foxpost_snapshot(['size' => 'XXL'])), true));

    $hd = foxpost_snapshot(['delivery_mode' => 'HD', 'destination' => '', 'unique_barcode' => '', 'recipient' => ['zip' => '999']]);
    assert_true(in_array('INVALID_ZIP', foxpost_errors($hd), true));
});

fch_test('Foxpost parcel: phone validation can be disabled by agreement (guide §6.1)', function (): void {
    $snapshot = foxpost_snapshot(['recipient' => ['phone' => '06301234567'], 'phone_validation' => false]);
    assert_true(!in_array('INVALID_PHONE', foxpost_errors($snapshot), true));
});

fch_test('Foxpost response: FieldError parsing (array and JSON-string forms)', function (): void {
    $parsed = FoxpostParcelService::parseFieldErrors([
        'errors' => [
            ['field' => 'cod', 'message' => 'INVALID_COD'],
            ['field' => 'recipientZip', 'message' => 'INVALID_ZIP'],
        ],
    ]);
    assert_same(2, count($parsed));
    assert_same('cod', $parsed[0]['field']);
    assert_same('INVALID_COD', $parsed[0]['message']);
    assert_same('recipientZip', $parsed[1]['field']);

    $fromString = FoxpostParcelService::parseFieldErrors([
        'errors' => '[{"field":"uniqueBarcode","message":"DUPLICATED_UNIQUE_BARCODE"}]',
    ]);
    assert_same(1, count($fromString));
    assert_same('DUPLICATED_UNIQUE_BARCODE', $fromString[0]['message']);

    assert_same([], FoxpostParcelService::parseFieldErrors([]));
});

fch_test('Foxpost tracking: status mapping with terminal precedence', function (): void {
    assert_same('delivered', FoxpostTracking::mapStatuses(['HDRECEIVE']));
    assert_same('delivered', FoxpostTracking::mapStatuses(['RECEIVE']));
    assert_same('shipped', FoxpostTracking::mapStatuses(['OPERIN']));
    assert_same('shipped', FoxpostTracking::mapStatuses(['SORTIN', 'HDCOURIER']));
    assert_same('unshippable', FoxpostTracking::mapStatuses(['BACKTOSENDER']));
    assert_same('unshippable', FoxpostTracking::mapStatuses(['OVERTIMEOUT']));
    assert_same('', FoxpostTracking::mapStatuses(['CREATE']), 'CREATE alone proves no transport progress');
    assert_same('', FoxpostTracking::mapStatuses(['NOT_A_REAL_STATUS']));
    assert_same('delivered', FoxpostTracking::mapStatuses(['BACKTOSENDER', 'RECEIVE']), 'delivered beats a return');
});

fch_test('Foxpost tracking: payload shapes (Tracking traces / Statuses / bare list)', function (): void {
    $tracking = new FoxpostTracking();
    assert_same('shipped', $tracking->mapTrackingToShippingStatus(['traces' => [['status' => 'OPERIN'], ['status' => 'CREATE']]]));
    assert_same('delivered', $tracking->mapTrackingToShippingStatus(['traces' => [['status' => 'HDRECEIVE']]]));
    assert_same('shipped', $tracking->mapTrackingToShippingStatus(['statuses' => [['status' => 'SORTIN']]]));
    assert_same('shipped', $tracking->mapTrackingToShippingStatus([['barcode' => 'CLFOX1', 'statuses' => [['status' => 'HDDEPO']]]]));
    assert_same('', $tracking->mapTrackingToShippingStatus([]));
});

fch_test('Foxpost tracking: monotonic shipping-status guard (never regress)', function (): void {
    assert_true(FoxpostTracking::isForwardTransition('unshipped', 'shipped'));
    assert_true(FoxpostTracking::isForwardTransition('shipped', 'delivered'));
    assert_true(FoxpostTracking::isForwardTransition('unshipped', 'unshippable'));
    assert_true(!FoxpostTracking::isForwardTransition('delivered', 'shipped'));
    assert_true(!FoxpostTracking::isForwardTransition('delivered', 'unshippable'));
    assert_true(!FoxpostTracking::isForwardTransition('unshippable', 'delivered'));
    assert_true(!FoxpostTracking::isForwardTransition('shipped', 'shipped'));
});

fch_test('Foxpost label: page-size handling, startPos (A7 only) and isPortrait gating', function (): void {
    assert_true(FoxpostLabelService::pageSizeSupported('A6'));
    assert_true(FoxpostLabelService::pageSizeSupported('a7'));
    assert_true(FoxpostLabelService::pageSizeSupported('_85x85'));
    assert_true(FoxpostLabelService::pageSizeSupported('A5'), 'A5 is in the machine spec pageSize enum');
    assert_true(!FoxpostLabelService::pageSizeSupported('B5'));
    assert_same('A6', FoxpostLabelService::normalizePageSize('b5'), 'unknown sizes fall back to A6');

    assert_same(['startPos' => 3], FoxpostLabelService::buildLabelQuery('A7', 3));
    assert_same([], FoxpostLabelService::buildLabelQuery('A6', 3), 'startPos is A7 only (guide §6.2)');
    assert_same([], FoxpostLabelService::buildLabelQuery('A7', 8), 'startPos must be 1..7');
    assert_same(['isPortrait' => 'true'], FoxpostLabelService::buildLabelQuery('A6', null, true), 'isPortrait only via the test-spec flag');
    assert_same([], FoxpostLabelService::buildLabelQuery('A6', null, false));
});
