<?php
declare(strict_types=1);

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits;
use FluentCartHungary\Modules\Shipping\Gls\GlsClient;
use FluentCartHungary\Modules\Shipping\Gls\GlsMapper;
use FluentCartHungary\Modules\Shipping\Gls\GlsShipmentService;
use FluentCartHungary\Modules\Shipping\Gls\GlsTracking;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\GlsPoints;
use FluentCartHungary\Tests\Mock\MockTransport;

/**
 * Pure unit tests for the GLS module (04_GLS_CONTRACT.md §2 auth, §4 operations, §5 limits, §6.1 status
 * table, §7 timeout). No WordPress, no HTTP — only the pure mapper/client/tracking parts are exercised.
 */

/** @return array<string,mixed> valid domestic HOME snapshot */
function gls_snapshot_base(array $overrides = []): array
{
    $base = [
        'order_id' => 7,
        'idempotency_key' => 'fch:7:label_create:1',
        'client_reference' => 'FCH-7',
        'mode' => 'test',
        'webshop_engine' => 'FluentCart',
        'currency' => 'HUF',
        'delivery_mode' => 'HOME',
        'pickup_point' => [],
        'recipient' => [
            'name' => 'Teszt Elek',
            'phone' => '+36301234567',
            'email' => 'teszt@example.hu',
            'country' => 'HU',
            'address' => [
                'street' => 'Fo utca 1.',
                'city' => 'Budapest',
                'zip' => '1051',
                'house_number' => '1',
                'house_number_info' => '',
            ],
        ],
        'parcels' => [[
            'count' => 1,
            'weight_g' => 2000,
            'content' => 'Csomag',
            'package_type' => 1,
            'cod_minor' => 250000,
            'cod_reference' => 'FCH-7',
            'cod_currency' => 'HUF',
            'value_minor' => 0,
            'xxl' => false,
            'dims_mm' => null,
        ]],
        'services' => [],
        'type_of_printer' => 'A4_2x2',
        'print_position' => 1,
        'show_print_dialog' => false,
        'hide_phone_number' => false,
    ];

    return array_replace_recursive($base, $overrides);
}

/** @return list<string> */
function gls_errors(array $snapshot): array
{
    return GlsMapper::build($snapshot)['errors'];
}

// ---- auth shaping (§2.1) ---------------------------------------------------------------------------

fch_test('GLS client: SHA-512 password is a 64-byte array (byte-array auth shaping)', function (): void {
    $bytes = GlsClient::passwordBytes('secret');
    assert_same(64, count($bytes), 'sha-512 digest byte count');
    $expected = array_values(unpack('C*', hash('sha512', 'secret', true)));
    assert_same($expected, $bytes, 'byte array matches hash(sha512, raw)');

    foreach ($bytes as $byte) {
        assert_true(is_int($byte) && $byte >= 0 && $byte <= 255, 'byte in range');
    }
});

fch_test('GLS client: APIRequestBase carries Username + Password bytes (+ optional ClientNumberList)', function (): void {
    $auth = GlsClient::buildAuth('user@example.hu', 'pwd', [12345]);
    assert_same('user@example.hu', $auth['Username']);
    assert_same(64, count($auth['Password']));
    assert_same([12345], $auth['ClientNumberList']);

    $without = GlsClient::buildAuth('user', 'pwd');
    assert_true(!array_key_exists('ClientNumberList', $without), 'ClientNumberList omitted when unused');
});

fch_test('GLS client: REST endpoint template {base}/{service}.svc/json/{method}', function (): void {
    assert_same(
        'https://api.test.mygls.hu/ParcelService.svc/json/PrintLabels',
        GlsClient::endpoint('https://api.test.mygls.hu/', 'ParcelService.svc', 'PrintLabels')
    );
    assert_same(
        'https://api.mygls.hu/MasterDataService.svc/json/GetDeliveryPoints',
        GlsClient::endpoint('https://api.mygls.hu', 'MasterDataService.svc', 'GetDeliveryPoints')
    );
});

// ---- mapper payload (§3.1/§3.2/§3.4/§3.6/§5) --------------------------------------------------------

fch_test('GLS mapper: builds ParcelList with COD/currency/weight/pickup fields', function (): void {
    $built = GlsMapper::build(gls_snapshot_base());
    assert_true($built['ok'], 'valid snapshot rejected: ' . implode(',', $built['errors']));

    $payload = $built['payload'];
    assert_same('FluentCart', $payload['WebshopEngine']);
    assert_same('A4_2x2', $payload['PrintOptions']['TypeOfPrinter']);

    $parcel = $payload['ParcelList'][0];
    assert_same('FCH-7', $parcel['ClientReference']);
    assert_same(250000, $parcel['CODAmount']);
    assert_same('HUF', $parcel['CODCurrency']);
    assert_same(1, $parcel['Count']);
    assert_same(2.0, $parcel['ParcelPropertyList'][0]['Weight']);
    assert_same(1, $parcel['ParcelPropertyList'][0]['PackageType']);
    assert_same('1', $parcel['DeliveryAddress']['HouseNumber']);
    assert_same('1051', $parcel['DeliveryAddress']['ZipCode']);
    assert_same('HU', $parcel['DeliveryAddress']['CountryIsoCode']);
});

fch_test('GLS mapper: PSD adds ContactName/Phone/Email + PSDParameter + PSD service', function (): void {
    $snapshot = gls_snapshot_base([
        'delivery_mode' => 'PSD',
        'pickup_point' => [
            'point_id' => '12345',
            'zip' => '1051',
            'contact_name' => 'Teszt Elek',
            'contact_phone' => '+36301234567',
            'contact_email' => 'teszt@example.hu',
        ],
    ]);
    $built = GlsMapper::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));

    $parcel = $built['payload']['ParcelList'][0];
    assert_same('Teszt Elek', $parcel['DeliveryAddress']['ContactName']);
    assert_same('+36301234567', $parcel['DeliveryAddress']['ContactPhone']);
    assert_same('teszt@example.hu', $parcel['DeliveryAddress']['ContactEmail']);
    assert_same('1051-12345', $parcel['PSDParameter']['StringValue']);
    assert_same(12345, $parcel['PSDParameter']['IntegerValue']);
    assert_true(in_array('PSD', $parcel['ServiceList'], true), 'PSD service missing');
});

fch_test('GLS mapper: local validation errors before any dispatch', function (): void {
    assert_true(in_array('missing_webshop_engine', gls_errors(gls_snapshot_base(['webshop_engine' => ''])), true));
    assert_true(in_array('missing_idempotency_key', gls_errors(gls_snapshot_base(['idempotency_key' => ''])), true));

    assert_true(in_array('cod_negative', gls_errors(gls_snapshot_base(['parcels' => [['cod_minor' => -1]]])), true));
    assert_true(in_array('error_48_invalid_cod_currency', gls_errors(gls_snapshot_base(['parcels' => [['cod_minor' => 100, 'cod_currency' => 'HU']]])), true));

    assert_true(in_array('error_23_house_number_zero', gls_errors(gls_snapshot_base(['recipient' => ['address' => ['house_number' => '0']]])), true));
    assert_true(in_array('error_29_count_out_of_range', gls_errors(gls_snapshot_base(['parcels' => [['count' => 100]]])), true));
    assert_true(in_array('weight_over_limit_xxl', gls_errors(gls_snapshot_base(['parcels' => [['xxl' => true, 'weight_g' => 80001]]])), true));
});

fch_test('GLS mapper: INS count must be 1 (error 28) and needs a declared value', function (): void {
    $snapshot = gls_snapshot_base([
        'services' => ['INS'],
        'parcels' => [['count' => 2, 'value_minor' => 500000]],
    ]);
    $errors = gls_errors($snapshot);
    assert_true(in_array('error_28_ins_single_count', $errors, true));
});

fch_test('GLS mapper: FSS without FDS (error 30) and SRS/LRS country limits', function (): void {
    assert_true(in_array('error_30_fss_without_fds', gls_errors(gls_snapshot_base(['services' => ['FSS']])), true));
    assert_true(in_array('srs_country_not_allowed', gls_errors(gls_snapshot_base(['recipient' => ['country' => 'DE'], 'services' => ['SRS']])), true));
    assert_true(in_array('lrs_country_not_allowed', gls_errors(gls_snapshot_base(['recipient' => ['country' => 'SI'], 'services' => ['LRS']])), true));
});

fch_test('GLS mapper: PSD requires point id and contact details (§3.2)', function (): void {
    $snapshot = gls_snapshot_base([
        'delivery_mode' => 'PSD',
        'pickup_point' => [],
        'recipient' => ['phone' => '', 'email' => '', 'address' => []],
    ]);
    $errors = gls_errors($snapshot);
    assert_true(in_array('missing_psd_point_id', $errors, true));
    assert_true(in_array('missing_contact_phone_for_psd', $errors, true));
    assert_true(in_array('missing_contact_email_for_psd', $errors, true));
});

// ---- response interpretation (§4 GLS-013/015/017) --------------------------------------------------

fch_test('GLS mapper: parses PrintLabels label base64, ParcelNumber/ParcelId/barcode', function (): void {
    $data = MockTransport::fixture('gls/print_labels_success.json');
    $interp = GlsMapper::interpretPrintResponse($data);
    assert_true($interp['ok'], 'print response rejected');
    assert_same([], $interp['errors']);
    assert_same('JVBERi0xLjQKJSBzeW50aGV0aWMgR0xTIGxhYmVsCiUlRU9GCg==', $interp['label_base64']);

    $binary = GlsMapper::extractLabelBinary($interp['label_base64']);
    assert_true(is_string($binary) && str_starts_with($binary, '%PDF'), 'label binary is a PDF');

    $parcel = $interp['parcels'][0];
    assert_same(123456789, $parcel['parcel_id']);
    assert_same('1234567890', $parcel['parcel_number']);
    assert_same('12345678901', $parcel['barcode']);
    assert_same('FCH-992001', $parcel['client_reference']);
});

fch_test('GLS mapper: error codes are surfaced as rejection', function (): void {
    $data = MockTransport::fixture('gls/error_print_labels.json');
    $interp = GlsMapper::interpretPrintResponse($data);
    assert_true(!$interp['ok']);
    assert_same('rejected', $interp['result']);
    assert_same('8', $interp['errors'][0]['code']);
    assert_same('COD amount >= 0', $interp['errors'][0]['message']);
});

fch_test('GLS mapper: PrepareLabels response yields ParcelInfoList', function (): void {
    $data = MockTransport::fixture('gls/prepare_labels_success.json');
    $interp = GlsMapper::interpretPrepareResponse($data);
    assert_true($interp['ok']);
    assert_same(123456789, $interp['parcels'][0]['parcel_id']);
    assert_same('FCH-992001', $interp['parcels'][0]['client_reference']);
});

// ---- tracking status mapping (§6.1) ----------------------------------------------------------------

fch_test('GLS tracking: exact status-code mapping from the contract table', function (): void {
    assert_same('delivered', GlsTracking::mapStatusCode(5));
    assert_same('delivered', GlsTracking::mapStatusCode(54));
    assert_same('delivered', GlsTracking::mapStatusCode(97));
    assert_same('unshippable', GlsTracking::mapStatusCode(23));
    assert_same('unshippable', GlsTracking::mapStatusCode(40));
    assert_same('unshippable', GlsTracking::mapStatusCode(17));
    assert_same('shipped', GlsTracking::mapStatusCode(1));
    assert_same('shipped', GlsTracking::mapStatusCode(3));
    assert_same('shipped', GlsTracking::mapStatusCode(60));
    assert_same('shipped', GlsTracking::mapStatusCode(99));
    assert_same('', GlsTracking::mapStatusCode(51), 'data entered, not yet handed over');
    assert_same('', GlsTracking::mapStatusCode(999), 'unknown code never guesses');
});

fch_test('GLS tracking: status-list precedence delivered > unshippable > shipped', function (): void {
    assert_same('delivered', GlsTracking::mapStatusList([['StatusCode' => '3'], ['StatusCode' => '5']]));
    assert_same('unshippable', GlsTracking::mapStatusList([['StatusCode' => '1'], ['StatusCode' => '23']]));
    assert_same('shipped', GlsTracking::mapStatusList([['StatusCode' => '3']]));
    assert_same('', GlsTracking::mapStatusList([]));
});

fch_test('GLS tracking: monotonic shipping-status guard never regresses', function (): void {
    assert_true(GlsTracking::isForwardTransition('unshipped', 'shipped'));
    assert_true(GlsTracking::isForwardTransition('shipped', 'delivered'));
    assert_true(GlsTracking::isForwardTransition('unshipped', 'unshippable'));
    assert_true(!GlsTracking::isForwardTransition('delivered', 'shipped'));
    assert_true(!GlsTracking::isForwardTransition('delivered', 'unshippable'));
    assert_true(!GlsTracking::isForwardTransition('shipped', 'shipped'));
});

fch_test('GLS tracking: reads ParcelStatusList from a GLS-021 response', function (): void {
    $data = MockTransport::fixture('gls/parcel_statuses_delivered.json');
    $list = GlsTracking::statusListFromResponse($data);
    assert_same(2, count($list));
    assert_same('delivered', GlsTracking::mapStatusList($list));
});

// ---- COD limits (§3.6/§5/§6.2 error 8; GLS-004/GLS-904) --------------------------------------------

fch_test('GLS COD: only >= 0 is enforced by default; settings drive min/max', function (): void {
    assert_same(['ok' => false, 'reason' => 'cod_negative'], CodValueLimits::check('gls', -1));
    assert_same(['ok' => true, 'reason' => null], CodValueLimits::check('gls', 0));
    assert_same(['ok' => true, 'reason' => null], CodValueLimits::check('gls', 500000));

    $below = CodValueLimits::check('gls', 49999, 50000, null);
    assert_same('cod_below_min', $below['reason']);
    $above = CodValueLimits::check('gls', 500001, null, 500000);
    assert_same('cod_above_max', $above['reason']);
});

// ---- idempotency semantics (service-level pure logic) ----------------------------------------------

fch_test('GLS service: ledger idempotency key is carrier-scoped + no auto create-retry', function (): void {
    // Global unique key on idempotency_key: the kind segment carries the carrier so an MPL/GLS label on
    // the same order can never collide (fix found during W2 integration).
    $key = GlsShipmentService::idempotencyKey(7, 1);
    assert_same('fch:7:gls:label_create:1', $key);
    assert_true(str_contains($key, ':gls:label_create:'), 'carrier-scoped kind segment');

    // 04_GLS §7: MyGLS has no idempotency key → OperationRunner max_attempts must be 1.
    assert_same(1, GlsShipmentService::MAX_ATTEMPTS);
});

fch_test('GLS service: total COD sums domain minor units', function (): void {
    $snapshot = ['parcels' => [['cod_minor' => 100], ['cod_minor' => 200], ['cod_minor' => -50]]];
    assert_same(300, GlsShipmentService::totalCodMinor($snapshot));
});

// ---- pickup feed (GLS-011 / GLS-UNK-09) ------------------------------------------------------------

fch_test('GLS points: decodePointData handles a decoded list and a GZIP payload', function (): void {
    $rows = GlsPoints::decodePointData(MockTransport::fixture('gls/delivery_points.json'));
    assert_same(2, count($rows));

    if (function_exists('gzdeflate')) {
        $json = json_encode([['Id' => 'X-1', 'Matchcode' => 'Pont']]);
        $encoded = base64_encode(gzdeflate((string) $json));
        $decoded = GlsPoints::decodePointData($encoded);
        assert_same(1, count($decoded));
        assert_same('X-1', $decoded[0]['Id']);
    }

    assert_same([], GlsPoints::decodePointData('not-base64-gzip'));
});

fch_test('GLS points: inactive/vacation points are skipped and types are canonicalised', function (): void {
    $active = GlsPoints::normalizeDeliveryPoint([
        'Id' => 'A-1', 'Matchcode' => 'Pont', 'Address' => 'Fo utca 1.', 'City' => 'Budapest',
        'ZipCode' => '1051', 'DeliveryPointType' => 2, 'IsActive' => true,
    ], 'gls');
    assert_true($active !== null);
    assert_same('gls', $active['carrier']);
    assert_same('A-1', $active['point_id']);
    assert_same('PARCELBOX', $active['type']);

    $inactive = GlsPoints::normalizeDeliveryPoint([
        'Id' => 'A-2', 'DeliveryPointType' => 1, 'IsActive' => false,
    ], 'gls');
    assert_same(null, $inactive);

    $now = time();
    $onVacation = GlsPoints::normalizeDeliveryPoint([
        'Id' => 'A-3', 'DeliveryPointType' => 1, 'IsActive' => true,
        'VacationFrom' => gmdate('Y-m-d', $now - 86400),
        'VacationTill' => gmdate('Y-m-d', $now + 86400),
    ], 'gls');
    assert_same(null, $onVacation);
    assert_true(GlsPoints::isOnVacation(['VacationFrom' => gmdate('Y-m-d H:i:s', $now - 3600), 'VacationTill' => gmdate('Y-m-d H:i:s', $now + 3600)], $now));
});
