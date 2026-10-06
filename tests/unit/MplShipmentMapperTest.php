<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\Mpl\MplShipmentMapper;

/**
 * Pure unit tests for MplShipmentMapper (04_MPL_CONTRACT.md §4 MPL-006, §5 limits, §3.4/§3.6).
 * No WordPress, no HTTP — the mapper is a pure function of a snapshot array.
 */

/** @return array<string,mixed> valid domestic home-delivery snapshot */
function mpl_mapper_base(array $overrides = []): array
{
    $base = [
        'order_id' => 1,
        'idempotency_key' => 'fch:1:label_create:1',
        'webshop_id' => 'FCH-1',
        'mode' => 'test',
        'developer' => 'FCHDEV',
        'base_service' => '',
        'sender' => ['agreement' => 'ABCD1234', 'account_no' => '1234567890'],
        'recipient' => [
            'name' => 'Teszt Elek',
            'phone' => '+36301234567',
            'email' => 'teszt@example.hu',
            'country' => 'HU',
            'address' => ['postCode' => '1051', 'city' => 'Budapest', 'address' => 'Fo utca 1.'],
            'parcel_pickup_site' => null,
        ],
        'delivery_mode' => 'HA',
        'package_retention' => 5,
        'payment_mode' => null,
        'label_type' => 'A4',
        'label_format' => 'PDF',
        'group_together' => false,
        'currency' => 'HUF',
        'parcels' => [[
            'weight_g' => 1000,
            'size' => null,
            'cod_minor' => 0,
            'value_minor' => 0,
            'extras' => [],
            'customs' => [],
            'dims_mm' => null,
        ]],
    ];

    return array_replace_recursive($base, $overrides);
}

/** @return list<string> */
function mpl_mapper_errors(array $snapshot): array
{
    return MplShipmentMapper::build($snapshot)['errors'];
}

fch_test('MPL mapper builds the exact MPL-006 request shape', function (): void {
    $built = MplShipmentMapper::build(mpl_mapper_base());
    assert_true($built['ok'], 'valid snapshot rejected: ' . implode(',', $built['errors']));
    $payload = $built['payload'];

    assert_same('FCHDEV', $payload['developer']);
    assert_same('ABCD1234', $payload['sender']['agreement']);
    assert_same('1234567890', $payload['sender']['accountNo']);
    assert_same('FCH-1', $payload['webshopId']);
    assert_same('A4', $payload['labelType']);
    assert_same('PDF', $payload['labelFormat']);
    assert_same('HA', $payload['deliveryMode']);
    assert_same(5, $payload['packageRetention']);
    assert_same(false, $payload['groupTogether']);
    assert_same('fch:1:label_create:1', $payload['customData1']);
    assert_same('g', $payload['item'][0]['weight']['unit']);
    assert_same(1000, $payload['item'][0]['weight']['value']);
});

fch_test('MPL mapper: customData1 is the reconciliation idempotency key', function (): void {
    $snapshot = mpl_mapper_base(['idempotency_key' => 'fch:42:label_create:2']);
    $payload = MplShipmentMapper::build($snapshot)['payload'];
    assert_same('fch:42:label_create:2', $payload['customData1']);
});

fch_test('MPL mapper: COD maps to services.cod + K_UVT and paymentMode', function (): void {
    $snapshot = mpl_mapper_base([
        'payment_mode' => 'UV_KP',
        'parcels' => [['weight_g' => 1000, 'cod_minor' => 250000]],
    ]);
    $built = MplShipmentMapper::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));
    $services = $built['payload']['item'][0]['services'];
    assert_same(250000, $services['cod']);
    assert_true(in_array('K_UVT', $services['extras'], true), 'K_UVT missing');
    assert_same('UV_KP', $built['payload']['paymentMode']);
});

fch_test('MPL mapper: UV_AT requires sender.accountNo (error 94)', function (): void {
    $snapshot = mpl_mapper_base([
        'sender' => ['account_no' => ''],
        'payment_mode' => 'UV_AT',
        'parcels' => [['weight_g' => 1000, 'cod_minor' => 1000]],
    ]);
    assert_true(in_array('uv_at_requires_account_no', mpl_mapper_errors($snapshot), true));
});

fch_test('MPL mapper: value declaration maps to services.value + K_ENY', function (): void {
    $snapshot = mpl_mapper_base(['parcels' => [['weight_g' => 1000, 'value_minor' => 100000]]]);
    $built = MplShipmentMapper::build($snapshot);
    assert_true($built['ok'], implode(',', $built['errors']));
    assert_same(100000, $built['payload']['item'][0]['services']['value']);
    assert_true(in_array('K_ENY', $built['payload']['item'][0]['services']['extras'], true));
});

fch_test('MPL mapper: domestic weight limits per delivery mode', function (): void {
    assert_true(in_array('weight_over_limit_HA', mpl_mapper_errors(mpl_mapper_base(['parcels' => [['weight_g' => 40001]]])), true));
    assert_true(in_array('weight_over_limit_PM', mpl_mapper_errors(mpl_mapper_base(['delivery_mode' => 'PM', 'recipient' => ['parcel_pickup_site' => 'PP-1'], 'parcels' => [['weight_g' => 30001]]])), true));
    assert_true(in_array('weight_over_limit_CS', mpl_mapper_errors(mpl_mapper_base(['delivery_mode' => 'CS', 'recipient' => ['parcel_pickup_site' => 'CS-1'], 'parcels' => [['weight_g' => 20001, 'size' => 'M']]])), true));
    assert_true(in_array('weight_over_limit_PP', mpl_mapper_errors(mpl_mapper_base(['delivery_mode' => 'PP', 'recipient' => ['parcel_pickup_site' => 'PP-1'], 'parcels' => [['weight_g' => 20001]]])), true));
    assert_true(in_array('weight_over_limit_RA', mpl_mapper_errors(mpl_mapper_base(['delivery_mode' => 'RA', 'parcels' => [['weight_g' => 1000001, 'value_minor' => 100000]]])), true));
});

fch_test('MPL mapper: Postacsomag HA capped at 10 kg and single parcel', function (): void {
    $snapshot = mpl_mapper_base(['base_service' => 'A_177_MPC', 'parcels' => [['weight_g' => 10001]]]);
    assert_true(in_array('weight_over_limit_HA', mpl_mapper_errors($snapshot), true));
    $two = mpl_mapper_base(['base_service' => 'A_177_MPC', 'parcels' => [['weight_g' => 1000], ['weight_g' => 1000]]]);
    assert_true(in_array('postacsomag_single_parcel', mpl_mapper_errors($two), true));
});

fch_test('MPL mapper: parcel terminal one parcel, size mandatory, 500k caps', function (): void {
    $twoParcels = mpl_mapper_base([
        'delivery_mode' => 'CS',
        'recipient' => ['parcel_pickup_site' => 'CS-1'],
        'parcels' => [['weight_g' => 1000, 'size' => 'M'], ['weight_g' => 1000, 'size' => 'M']],
    ]);
    assert_true(in_array('parcel_terminal_single_parcel', mpl_mapper_errors($twoParcels), true));

    $noSize = mpl_mapper_base([
        'delivery_mode' => 'CS',
        'recipient' => ['parcel_pickup_site' => 'CS-1'],
        'parcels' => [['weight_g' => 1000, 'size' => null]],
    ]);
    assert_true(in_array('parcel_terminal_size_required', mpl_mapper_errors($noSize), true));

    $overCap = mpl_mapper_base([
        'delivery_mode' => 'CS',
        'recipient' => ['parcel_pickup_site' => 'CS-1'],
        'payment_mode' => 'UV_KP',
        'parcels' => [['weight_g' => 1000, 'size' => 'M', 'cod_minor' => 500001]],
    ]);
    assert_true(in_array('cod_over_value_cap', mpl_mapper_errors($overCap), true));
});

fch_test('MPL mapper: ZPL only A6/A5E/A5E_EXTRA (error 132)', function (): void {
    $zplA4 = mpl_mapper_base(['label_format' => 'ZPL', 'label_type' => 'A4']);
    assert_true(in_array('error_132_zpl_label_type', mpl_mapper_errors($zplA4), true));

    $zplA6 = MplShipmentMapper::build(mpl_mapper_base(['label_format' => 'ZPL', 'label_type' => 'A6']));
    assert_true($zplA6['ok'], implode(',', $zplA6['errors']));
});

fch_test('MPL mapper: customs required outside the EU (04_MPL 5.2)', function (): void {
    $outside = mpl_mapper_base(['recipient' => ['country' => 'US']]);
    assert_true(in_array('customs_required_outside_eu', mpl_mapper_errors($outside), true));

    $withCustoms = mpl_mapper_base([
        'recipient' => ['country' => 'US', 'address' => ['postCode' => '10001', 'city' => 'New York', 'address' => '1 Main St']],
        'parcels' => [[
            'weight_g' => 1000,
            'customs' => [[
                'produceCount' => 1,
                'produceName' => 'Book',
                'produceValue' => 5000,
                'tariffCode' => '49019900',
                'country' => 'US',
                'weightValue' => 1000,
            ]],
        ]],
    ]);
    $built = MplShipmentMapper::build($withCustoms);
    assert_true($built['ok'], implode(',', $built['errors']));
    assert_same('10001', $built['payload']['recipient']['address']['postCode']);
    assert_same('49019900', $built['payload']['item'][0]['customs'][0]['tariffCode']);
});

fch_test('MPL mapper: EU destination needs no customs', function (): void {
    $built = MplShipmentMapper::build(mpl_mapper_base([
        'recipient' => ['country' => 'AT', 'address' => ['postCode' => '1010', 'city' => 'Wien', 'address' => 'Ring 1']],
    ]));
    assert_true($built['ok'], implode(',', $built['errors']));
});

fch_test('MPL mapper: HU E.164 phone and address length rules', function (): void {
    $badPhone = mpl_mapper_base(['recipient' => ['phone' => '06301234567']]);
    assert_true(in_array('invalid_phone_e164', mpl_mapper_errors($badPhone), true));

    $badPostCode = mpl_mapper_base(['recipient' => ['address' => ['postCode' => '105100']]]);
    assert_true(in_array('invalid_postcode', mpl_mapper_errors($badPostCode), true));

    $badCity = mpl_mapper_base(['recipient' => ['address' => ['city' => 'B']]]);
    assert_true(in_array('invalid_city_length', mpl_mapper_errors($badCity), true));

    $badAddress = mpl_mapper_base(['recipient' => ['address' => ['address' => 'Fo']]]);
    assert_true(in_array('invalid_address_length', mpl_mapper_errors($badAddress), true));
});

fch_test('MPL mapper: pickup-point modes need parcelPickupSite and a phone (68/38)', function (): void {
    $noSite = mpl_mapper_base(['delivery_mode' => 'CS', 'parcels' => [['weight_g' => 1000, 'size' => 'M']]]);
    assert_true(in_array('missing_parcel_pickup_site', mpl_mapper_errors($noSite), true));

    $noPhone = mpl_mapper_base([
        'delivery_mode' => 'PP',
        'recipient' => ['phone' => '', 'parcel_pickup_site' => 'PP-1'],
        'parcels' => [['weight_g' => 1000]],
    ]);
    assert_true(in_array('missing_phone_for_pickup_point', mpl_mapper_errors($noPhone), true));
});

fch_test('MPL mapper: >40 kg requires a value declaration (error 64)', function (): void {
    $noValue = mpl_mapper_base(['delivery_mode' => 'RA', 'parcels' => [['weight_g' => 50000]]]);
    assert_true(in_array('value_declaration_required_over_40kg', mpl_mapper_errors($noValue), true));

    $withValue = mpl_mapper_base(['delivery_mode' => 'RA', 'parcels' => [['weight_g' => 50000, 'value_minor' => 500000]]]);
    $built = MplShipmentMapper::build($withValue);
    assert_true($built['ok'], implode(',', $built['errors']));
});

fch_test('MPL mapper: agreement must be 8 chars and idempotency key is required', function (): void {
    assert_true(in_array('agreement_must_be_8_chars', mpl_mapper_errors(mpl_mapper_base(['sender' => ['agreement' => 'ABC']])), true));
    assert_true(in_array('missing_idempotency_key', mpl_mapper_errors(mpl_mapper_base(['idempotency_key' => ''])), true));
});

fch_test('MPL mapper: pickup site is emitted into recipient.address', function (): void {
    $snapshot = mpl_mapper_base([
        'delivery_mode' => 'PP',
        'recipient' => ['parcel_pickup_site' => 'PP-999'],
        'parcels' => [['weight_g' => 1000]],
    ]);
    $payload = MplShipmentMapper::build($snapshot)['payload'];
    assert_same('PP-999', $payload['recipient']['address']['parcelPickupSite']);
    assert_same('PP', $payload['deliveryMode']);
});
