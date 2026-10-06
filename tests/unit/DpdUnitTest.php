<?php
declare(strict_types=1);

use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits;
use FluentCartHungary\Modules\Shipping\Dpd\DpdClient;
use FluentCartHungary\Modules\Shipping\Dpd\DpdGeodata;
use FluentCartHungary\Modules\Shipping\Dpd\DpdMapper;
use FluentCartHungary\Modules\Shipping\Dpd\DpdModule;
use FluentCartHungary\Modules\Shipping\Dpd\DpdReconciler;
use FluentCartHungary\Modules\Shipping\Dpd\DpdTracking;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\DpdPoints;

/**
 * Pure unit tests for the DPD carrier module (04_DPD_CONTRACT.md §2/§3.6/§4.1/§5/§6.2, DPD-001/006/007).
 * No WordPress, no HTTP — bearer/header shaping and the mapper are pure functions; the client is
 * constructed with an injected token so CredentialStore/ModuleSettings are never touched.
 */

/** @return array<string,mixed> valid domestic home-delivery snapshot */
function dpd_snapshot(array $overrides = []): array
{
    $base = [
        'order_id' => 1,
        'idempotency_key' => 'fch:1:dpd:label_create:1',
        'bu_code' => '123',
        'customer_id' => 'CUST-00000000001',
        'currency' => 'HUF',
        'sender' => [
            'sender_address_id' => 'ADDR-1',
            'company_name' => '',
            'name' => '',
            'contact_name' => '',
            'contact_phone' => '',
            'contact_email' => '',
            'address' => [],
        ],
        'receiver' => [
            'name' => 'Teszt Elek',
            'contactPhone' => '+36301234567',
            'contactEmail' => 'teszt@example.hu',
            'country' => 'HU',
            'address' => [
                'countryCode' => 'HU',
                'zipCode' => '1051',
                'city' => 'Budapest',
                'addressLine1' => 'Fo utca 1.',
                'addressLine2' => '',
            ],
        ],
        'delivery_mode' => 'HD',
        'service' => [
            'main_service_code' => '101',
            'main_service_element_codes' => [],
            'additional_service' => ['pudo_id' => ''],
        ],
        'cod' => ['amount_minor' => 0, 'currency' => 'HUF', 'payment_type' => 'Cash', 'split' => '', 'bank_account_id' => ''],
        'parcels' => [['weight_kg' => 1.0, 'insurance_minor' => 0, 'dims' => null]],
        'label' => ['size' => 'A4', 'format' => 'pdf', 'dpi' => 203, 'parcelonly' => false],
        'save_mode' => 'printed',
        'pickup_date' => '',
        'reference2' => 'order-uuid-1',
        'reference3' => '',
        'reference4' => '',
        'inter' => null,
        'return_consolidation' => null,
    ];

    return array_replace_recursive($base, $overrides);
}

/** @return list<string> */
function dpd_errors(array $snapshot): array
{
    return DpdMapper::build($snapshot)['errors'];
}

// ---- request shaping: bearer + DPD-BU-ID (04_DPD §2.1) ------------------------------------------------

fch_test('DPD client: bearer + DPD-BU-ID headers, PREPROD default base', function (): void {
    $client = new DpdClient('test', 'TOK-123', 'BU9');
    $headers = $client->buildAuthorizedHeaders();

    assert_same('Bearer TOK-123', $headers['Authorization']);
    assert_same('BU9', $headers['DPD-BU-ID']);
    assert_same('application/json', $headers['Accept']);
    assert_true($client->hasToken());
    assert_true($client->hasBuCode());

    // BaseClient normalizes the base by trimming the trailing slash used in the contract URL.
    assert_same('https://nst-preprod.dpsin.dpdgroup.com/api/v1.1/', DpdClient::TEST_BASE);
    assert_same(rtrim(DpdClient::TEST_BASE, '/'), $client->baseUrl());
    assert_same(rtrim(DpdClient::LIVE_BASE, '/'), (new DpdClient('live', 'TOK-123', 'BU9'))->baseUrl());
    assert_same('test', (new DpdClient('weird-mode', 't', 'b'))->mode());
});

fch_test('DPD client: DPD-BU-ID omitted when no bu_code is configured', function (): void {
    $client = new DpdClient('test', 'TOK-123', '');
    $headers = $client->buildAuthorizedHeaders();
    assert_same('Bearer TOK-123', $headers['Authorization']);
    assert_true(!array_key_exists('DPD-BU-ID', $headers));
    assert_true(!$client->hasBuCode());
});

fch_test('DPD client: missing token probe reports jwt_scheme_unknown (G10/DPD-U1)', function (): void {
    $probe = (new DpdClient('test', '', 'BU9'))->probe();
    assert_same(false, $probe['ok']);
    assert_same('jwt_scheme_unknown', $probe['reason']);
    assert_true(in_array('jwt_scheme_unknown', $probe['blocked'], true));

    assert_same(true, (new DpdClient('test', 'TOK-123', 'BU9'))->probe()['ok']);
});

// ---- payload mapping: envelope, receiver, service, references (DPD-001) --------------------------------

fch_test('DPD mapper builds the DPD-001 request envelope and shipment keys', function (): void {
    $built = DpdMapper::build(dpd_snapshot());
    assert_true($built['ok'], 'valid snapshot rejected: ' . implode(',', $built['errors']));
    $payload = $built['payload'];

    assert_same('123', $payload['buCode']);
    assert_same('CUST-00000000001', $payload['customerId']);
    $shipment = $payload['shipments'][0];
    assert_same('1', $shipment['numOrder']);
    assert_same('ADDR-1', $shipment['senderAddressId']);
    assert_same('101', $shipment['service']['mainServiceCode']);
    assert_same('Teszt Elek', $shipment['receiver']['name']);
    assert_same('+36301234567', $shipment['receiver']['contactPhone']);
    assert_same('1051', $shipment['receiver']['address']['zipCode']);
    assert_same('HU', $shipment['receiver']['address']['countryCode']);
    assert_same(1.0, $shipment['parcels'][0]['weight']);
    assert_same('A4', $shipment['labelSize']);
    assert_same('pdf', $shipment['printFormat']);
    assert_same(203, $shipment['dpi']);
    assert_same('printed', $shipment['saveMode']);
    // reference1 is our local idempotency key for manual timeout reconciliation (no lookup endpoint, DPD-906).
    assert_same('fch:1:dpd:label_create:1', $shipment['reference1']);
    assert_same('order-uuid-1', $shipment['reference2']);
});

fch_test('DPD mapper: COD maps to service.additionalService.cod (§3.6)', function (): void {
    $snapshot = dpd_snapshot([
        'cod' => ['amount_minor' => 499000, 'currency' => 'HUF', 'payment_type' => 'Cash', 'split' => 'Even', 'bank_account_id' => 'BANK-7'],
    ]);
    $built = DpdMapper::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));

    $cod = $built['payload']['shipments'][0]['service']['additionalService']['cod'];
    assert_same(499000, $cod['amount']);
    assert_same('HUF', $cod['currency']);
    assert_same('Cash', $cod['paymentType']);
    assert_same('Even', $cod['split']);
    assert_same('BANK-7', $cod['bankAccountId']);
    assert_same('fch:1:dpd:label_create:1', $cod['reference']);
});

fch_test('DPD mapper: no additionalService.cod when COD is zero', function (): void {
    $payload = DpdMapper::build(dpd_snapshot())['payload'];
    assert_true(!isset($payload['shipments'][0]['service']['additionalService']['cod']));
});

fch_test('DPD mapper: PUDO point maps to service.additionalService.pudoId (§3.2)', function (): void {
    $snapshot = dpd_snapshot([
        'delivery_mode' => 'PUDO',
        'service' => ['additional_service' => ['pudo_id' => 'PUDO-HU-12345']],
    ]);
    $built = DpdMapper::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));
    assert_same('PUDO-HU-12345', $built['payload']['shipments'][0]['service']['additionalService']['pudoId']);
});

fch_test('DPD mapper: parcels[] split is preserved and multi-parcel MPS is not merged', function (): void {
    $snapshot = dpd_snapshot([
        'parcels' => [
            ['weight_kg' => 10.0, 'insurance_minor' => 0, 'dims' => null],
            ['weight_kg' => 12.5, 'insurance_minor' => 0, 'dims' => null],
            ['weight_kg' => 3.0, 'insurance_minor' => 0, 'dims' => null],
        ],
    ]);
    $payload = DpdMapper::build($snapshot)['payload'];
    $parcels = $payload['shipments'][0]['parcels'];
    assert_same(3, count($parcels));
    assert_same(10.0, $parcels[0]['weight']);
    assert_same(12.5, $parcels[1]['weight']);
    assert_same(3.0, $parcels[2]['weight']);
});

fch_test('DPD mapper: dimension and insurance parcel fields use contract names', function (): void {
    $snapshot = dpd_snapshot([
        'parcels' => [['weight_kg' => 5.0, 'insurance_minor' => 25000, 'dims' => ['width' => 30, 'height' => 20, 'length' => 40]]],
    ]);
    $parcel = DpdMapper::build($snapshot)['payload']['shipments'][0]['parcels'][0];
    assert_same(30, $parcel['dimensionWidth']);
    assert_same(20, $parcel['dimensionHeight']);
    assert_same(40, $parcel['dimensionLength']);
    assert_same(25000, $parcel['insuranceAmount']);
});

// ---- validation: weight limits, address rules (04_DPD §5) ---------------------------------------------

fch_test('DPD mapper: weight limits are 50 kg domestic / 31.5 kg international', function (): void {
    assert_same(50.0, DpdMapper::maxWeightKg(true));
    assert_same(31.5, DpdMapper::maxWeightKg(false));

    $domestic = dpd_snapshot(['parcels' => [['weight_kg' => 50.0]]]);
    assert_true(DpdMapper::build($domestic)['ok'], '50 kg domestic must be allowed');

    $overDomestic = dpd_snapshot(['parcels' => [['weight_kg' => 50.5]]]);
    assert_true(in_array('parcel_weight_over_limit_domestic', dpd_errors($overDomestic), true));

    $overIntl = dpd_snapshot([
        'receiver' => ['country' => 'AT', 'address' => ['countryCode' => 'AT', 'zipCode' => '1010', 'city' => 'Wien', 'addressLine1' => 'Ring 1']],
        'parcels' => [['weight_kg' => 32.0]],
    ]);
    assert_true(in_array('parcel_weight_over_limit_international', dpd_errors($overIntl), true));

    $missingWeight = dpd_snapshot(['parcels' => [['weight_kg' => 0]]]);
    assert_true(in_array('parcel_weight_missing', dpd_errors($missingWeight), true));
});

fch_test('DPD mapper: parcel split plan keeps every parcel under the cap', function (): void {
    assert_same([40.0, 40.0, 40.0], DpdMapper::splitWeights(120.0, 50.0));
    assert_same([30.0], DpdMapper::splitWeights(30.0, 50.0));
    assert_same([], DpdMapper::splitWeights(0.0, 50.0));
    foreach (DpdMapper::splitWeights(131.5, 31.5) as $weight) {
        assert_true($weight <= 31.5 + 1e-9, 'split parcel exceeds the cap');
    }
});

fch_test('DPD mapper: address validation (zipCode string(9), city, addressLine1, country)', function (): void {
    $badZip = dpd_snapshot(['receiver' => ['address' => ['zipCode' => '1234567890']]]);
    assert_true(in_array('invalid_zip_code', dpd_errors($badZip), true));

    $emptyZip = dpd_snapshot(['receiver' => ['address' => ['zipCode' => '']]]);
    assert_true(in_array('invalid_zip_code', dpd_errors($emptyZip), true));

    $badCity = dpd_snapshot(['receiver' => ['address' => ['city' => '']]]);
    assert_true(in_array('missing_city', dpd_errors($badCity), true));

    $badAddress = dpd_snapshot(['receiver' => ['address' => ['addressLine1' => '']]]);
    assert_true(in_array('missing_address_line1', dpd_errors($badAddress), true));

    $badCountry = dpd_snapshot(['receiver' => ['country' => 'HUN']]);
    assert_true(in_array('invalid_receiver_country', dpd_errors($badCountry), true));
});

fch_test('DPD mapper: envelope/service validation (buCode, customerId, sender, main service, idempotency)', function (): void {
    assert_true(in_array('missing_bu_code', dpd_errors(dpd_snapshot(['bu_code' => ''])), true));
    assert_true(in_array('missing_customer_id', dpd_errors(dpd_snapshot(['customer_id' => ''])), true));
    assert_true(in_array('missing_idempotency_key', dpd_errors(dpd_snapshot(['idempotency_key' => ''])), true));
    assert_true(in_array('missing_main_service', dpd_errors(dpd_snapshot(['service' => ['main_service_code' => '', 'main_service_element_codes' => []]])), true));
    assert_true(in_array('missing_sender_identity', dpd_errors(dpd_snapshot(['sender' => ['sender_address_id' => '', 'company_name' => '']])), true));
    assert_true(in_array('missing_sender_contact', dpd_errors(dpd_snapshot(['sender' => ['sender_address_id' => '', 'company_name' => 'Cég Kft', 'contact_phone' => '', 'contact_email' => '']])), true));

    $senderObject = dpd_snapshot(['sender' => [
        'sender_address_id' => '',
        'company_name' => 'Cég Kft',
        'contact_phone' => '+3612345678',
    ]]);
    $built = DpdMapper::build($senderObject);
    assert_true($built['ok'], implode(',', $built['errors']));
    assert_same('Cég Kft', $built['payload']['shipments'][0]['sender']['companyName']);
    assert_same('+3612345678', $built['payload']['shipments'][0]['sender']['contactPhone']);
});

fch_test('DPD mapper: PUDO id length, COD currency/paymentType/split validation', function (): void {
    $longPudo = dpd_snapshot(['service' => ['additional_service' => ['pudo_id' => str_repeat('X', 21)]]]);
    assert_true(in_array('pudo_id_too_long', dpd_errors($longPudo), true));

    $codCurrency = dpd_snapshot(['cod' => ['amount_minor' => 1000, 'currency' => 'EUR', 'payment_type' => 'Cash', 'split' => '']]);
    assert_true(in_array('cod_currency_mismatch', dpd_errors($codCurrency), true));

    $codPayment = dpd_snapshot(['cod' => ['amount_minor' => 1000, 'currency' => 'HUF', 'payment_type' => 'Crypto', 'split' => '']]);
    assert_true(in_array('invalid_cod_payment_type', dpd_errors($codPayment), true));

    $codSplit = dpd_snapshot(['cod' => ['amount_minor' => 1000, 'currency' => 'HUF', 'payment_type' => 'Cash', 'split' => 'Half']]);
    assert_true(in_array('invalid_cod_split', dpd_errors($codSplit), true));

    $negative = dpd_snapshot(['cod' => ['amount_minor' => -1, 'currency' => 'HUF', 'payment_type' => 'Cash', 'split' => '']]);
    assert_true(in_array('cod_negative', dpd_errors($negative), true));
});

// ---- unknown-field graceful handling -------------------------------------------------------------------

fch_test('DPD mapper: unknown snapshot fields are ignored, never fatal', function (): void {
    $snapshot = dpd_snapshot([
        'totally_unknown' => ['nested' => [1, 2, 3]],
        'receiver' => ['unknown_receiver_field' => 'x'],
        'service' => ['unknown_service_field' => 'y', 'main_service_code' => '101'],
    ]);
    $built = DpdMapper::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));
    assert_true(!isset($built['payload']['totally_unknown']));
});

// ---- STATUSDATA status mapping + monotonicity (04_DPD §6.2) ---------------------------------------------

function dpd_tracking_subject(): DpdTracking
{
    return new DpdTracking();
}

fch_test('DPD tracking: scan 13 delivered -> delivered', function (): void {
    assert_same('delivered', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '13']));
    assert_same('delivered', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '13', 'ADD_SERVICE_1' => '37']));
});

fch_test('DPD tracking: network scans -> shipped, ParcelShop handover stays shipped', function (): void {
    assert_same('shipped', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '01']));
    assert_same('shipped', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '03']));
    assert_same('shipped', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '20']));
    assert_same('shipped', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '23']));
});

fch_test('DPD tracking: return exception codes -> unshippable', function (): void {
    assert_same('unshippable', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '14', 'ADD_SERVICE_1' => '37']));
    assert_same('unshippable', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '10', 'ADD_SERVICE_3' => '49']));
});

fch_test('DPD tracking: unknown scan -> empty (never guess)', function (): void {
    assert_same('', dpd_tracking_subject()->mapRecord(['SCAN_CODE' => '99']));
    assert_same('', dpd_tracking_subject()->mapRecord([]));
    assert_same('', dpd_tracking_subject()->mapRecords([]));
});

fch_test('DPD tracking: aggregate precedence delivered > unshippable > shipped', function (): void {
    $subject = dpd_tracking_subject();
    assert_same('delivered', $subject->mapRecords([['SCAN_CODE' => '01'], ['SCAN_CODE' => '13']]));
    assert_same('unshippable', $subject->mapRecords([['SCAN_CODE' => '01'], ['SCAN_CODE' => '14', 'ADD_SERVICE_1' => '37']]));
    assert_same('shipped', $subject->mapRecords([['SCAN_CODE' => '01'], ['SCAN_CODE' => '03']]));
});

fch_test('DPD tracking: monotonic shipping-status guard', function (): void {
    assert_true(DpdTracking::isForwardTransition('unshipped', 'shipped'));
    assert_true(DpdTracking::isForwardTransition('shipped', 'delivered'));
    assert_true(DpdTracking::isForwardTransition('unshipped', 'unshippable'));
    assert_true(!DpdTracking::isForwardTransition('delivered', 'shipped'));
    assert_true(!DpdTracking::isForwardTransition('delivered', 'unshippable'));
    assert_true(!DpdTracking::isForwardTransition('shipped', 'shipped'));
    assert_true(!DpdTracking::isForwardTransition('unshippable', 'delivered'));
});

fch_test('DPD tracking: STATUSDATA CSV parses the documented 20-field record', function (): void {
    $csv = "PARCELNO;SCAN_CODE;DEPOT_CODE;DEPOTNAME;EVENT_DATE_TIME;ROUTE;TOUR;PCODE;SERVICE;CONSIGNEE_COUNTRY_CODE;CONSIGNEE_ZIP;ADD_SERVICE_1;ADD_SERVICE_2;ADD_SERVICE_3;WEIGHT;CUSTOMER_REFERENCE;POD_IMAGE_REF;RECEIVER_NAME;INFO_TEXT;LOCATION\r\n"
        . "12345678901234;13;BUD;Budapest;20261006103000;R1;T1;1051;101;348;1051;;;;1.0;fch:1:dpd:label_create:1;;Teszt Elek;Delivered;Budapest\r\n";
    $records = DpdTracking::parseStatusdataCsv($csv);
    assert_same(1, count($records));
    assert_same('12345678901234', $records[0]['PARCELNO']);
    assert_same('13', $records[0]['SCAN_CODE']);
    assert_same('delivered', dpd_tracking_subject()->mapRecords($records));
});

fch_test('DPD tracking: no documented tracking API -> syncOrder is unsupported (DPD-U6)', function (): void {
    $result = dpd_tracking_subject()->syncOrder(42);
    assert_same('unsupported', $result['status']);
    assert_same('tracking_endpoint_undocumented', $result['reason']);
});

// ---- COD limits are settings-driven (ACCOUNT_DEPENDENT, DPD-U11 / §3.6) ---------------------------------

fch_test('DPD COD limits: no built-in cap; settings min/max are enforced', function (): void {
    CodValueLimits::reset();
    assert_same(['ok' => true, 'reason' => null], CodValueLimits::check('dpd', 100000));

    assert_same('cod_below_min', CodValueLimits::check('dpd', 5000, 10000, null)['reason']);
    assert_same('cod_above_max', CodValueLimits::check('dpd', 200000, null, 100000)['reason']);
    assert_same('cod_negative', CodValueLimits::check('dpd', -1)['reason']);
    assert_same(['ok' => true, 'reason' => null], CodValueLimits::check('dpd', 50000, 1000, 100000));
});

// ---- reconciler is deliberately not registered (DPD-906 / DPD-U8) --------------------------------------

fch_test('DPD reconciler: no query-by-reference endpoint -> unsupported, never invents a query', function (): void {
    assert_same(false, DpdReconciler::supported());
    assert_same(false, DpdReconciler::QUERY_BY_REFERENCE_DOCUMENTED);
    $result = (new DpdReconciler())((object) ['provider' => 'dpd', 'idempotency_key' => 'fch:1:dpd:label_create:1']);
    assert_true($result instanceof ProviderCallResult);
    assert_true($result->isUncertain());
    assert_same('DPD_QUERY_BY_REFERENCE_MISSING_DOC', $result->code);
});

// ---- geodata capability map (DPD-018…024 documented; Back-Office/PUDO not_supported) --------------------

fch_test('DPD geodata: routing documented; Back-Office host and PUDO feed are not_supported', function (): void {
    $caps = DpdGeodata::capabilities();
    assert_same('documented', $caps['DPD-018']['status']);
    assert_same('documented', $caps['DPD-024']['status']);
    assert_same('not_supported', $caps['DPD-040']['status']);
    assert_same('backoffice_host_unknown', $caps['DPD-040']['reason']);
    assert_same('not_supported', $caps['DPD-902']['status']);
    assert_same('pudo_feed_missing_doc', $caps['DPD-902']['reason']);

    $pudo = (new DpdGeodata())->pudoDirectory();
    assert_same(false, $pudo['ok']);
    assert_same(false, $pudo['supported']);
    assert_same('pudo_feed_missing_doc', $pudo['reason']);
});

// ---- pickup feed: static option only, never invented inventory (DPD-U3) ---------------------------------

fch_test('DPD pickup feed: no static option -> feed_unavailable (never invents points)', function (): void {
    $result = (new DpdPoints())->fetch();
    assert_same(false, $result['ok']);
    assert_same('feed_unavailable', $result['error']);
    assert_same([], $result['points']);
});

fch_test('DPD pickup feed: static list normalizes via PickupDirectory, entries without id are dropped', function (): void {
    $points = DpdPoints::normalizeStatic([
        'points' => [
            ['id' => 'PUDO-1', 'name' => 'DPD Pont', 'zip' => '1051', 'city' => 'Budapest', 'address' => 'Fo utca 1.'],
            ['name' => 'no id'],
        ],
    ]);
    assert_same(1, count($points));
    assert_same('PUDO-1', $points[0]['point_id']);
    assert_same('dpd', $points[0]['carrier']);
    assert_same('1051', $points[0]['zip']);
});

// ---- module unknowns are surfaced explicitly ------------------------------------------------------------

fch_test('DPD module: probe unknown map names G10 + the blocked matrix ids', function (): void {
    $unknowns = DpdModule::unknowns();
    assert_true(isset($unknowns['G10']));
    assert_true(isset($unknowns['DPD-U1']));
    assert_true(isset($unknowns['DPD-U3']));
    assert_true(isset($unknowns['DPD-U8']));
    assert_same('shipping/dpd', DpdModule::ID);
    assert_same('dpd', DpdModule::CARRIER);
    assert_same('dpd', DpdModule::PROVIDER);
    assert_same('shipping', DpdModule::MODULE);
    assert_same('dpd', DpdModule::SETTINGS_SCOPE);
});
