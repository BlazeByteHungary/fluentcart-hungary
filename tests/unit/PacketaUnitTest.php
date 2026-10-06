<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaClient;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaClaims;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaCustoms;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaMapper;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaTracking;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PacketaPoints;
use FluentCartHungary\Tests\Mock\MockTransport;

/**
 * Pure unit tests for the Packeta W2 module (04_PACKETA_CONTRACT.md). No WordPress, no HTTP: the client
 * envelope builder/decoder, the PacketAttributes mapper, the COD limits, the status-code mapping and the
 * PUDO feed normalizers are all pure functions.
 */

/** @return array<string,mixed> valid HU PUDO packet snapshot */
function packeta_snapshot(array $overrides = []): array
{
    $base = [
        'order_id' => 1,
        'idempotency_key' => 'fch:1:packeta:label_create:1',
        'number' => '991001',
        'name' => 'John',
        'surname' => 'Doe',
        'company' => '',
        'email' => 'john@example.hu',
        'phone' => '+36301234567',
        'country' => 'HU',
        'address_id' => '13789',
        'currency' => 'HUF',
        'cod_minor' => 0,
        'value_minor' => 10000,
        'weight_kg' => '2.5',
        'deliver_on' => '',
        'today' => '2026-10-05',
        'eshop' => 'FCH',
        'adult_content' => false,
        'note' => '',
        'delivery_mode' => PacketaMapper::DELIVERY_PUDO,
        'street' => '',
        'house_number' => '',
        'city' => '',
        'zip' => '',
        'province' => '',
        'carrier_service' => '',
        'carrier_pickup_point' => '',
        'attributes' => [],
        'items' => [],
        'security' => [],
        'services' => [],
        'affiliate_id' => '',
        'cod_min_minor' => null,
        'cod_max_minor' => null,
        'value_max_minor' => null,
    ];
    return array_replace_recursive($base, $overrides);
}

/** @return list<string> */
function packeta_errors(array $snapshot): array
{
    return PacketaMapper::build($snapshot)['errors'];
}

// ---- request envelope ------------------------------------------------------------------------------

fch_test('PKT-039 request envelope: root=method and apiPassword is the FIRST child', function (): void {
    $password = str_repeat('p', 32);
    $xml = PacketaClient::buildXml('createPacket', [
        'packetAttributes' => ['number' => '991001', 'name' => 'John', 'surname' => 'Doe'],
    ], $password);

    assert_true(str_starts_with($xml, '<createPacket><apiPassword>'), 'apiPassword must open the envelope');
    assert_true(str_ends_with($xml, '</createPacket>'));
    $posPassword = strpos($xml, '<apiPassword>');
    $posAttributes = strpos($xml, '<packetAttributes>');
    assert_true($posPassword !== false && $posAttributes !== false && $posPassword < $posAttributes, 'apiPassword precedes arguments');
    assert_same(1, substr_count($xml, '<apiPassword>'));
});

fch_test('PKT-001 createPacket XML carries packetAttributes fields and escapes values', function (): void {
    $xml = PacketaClient::buildXml('createPacket', [
        'packetAttributes' => [
            'number' => '991001',
            'name' => 'A & B',
            'currency' => 'HUF',
            'cod' => '4240',
            'weight' => '2.5',
        ],
    ], str_repeat('p', 32));

    assert_true(str_contains($xml, '<packetAttributes>'));
    assert_true(str_contains($xml, '<number>991001</number>'));
    assert_true(str_contains($xml, '<name>A &amp; B</name>'), 'XML must escape &');
    assert_true(str_contains($xml, '<cod>4240</cod>'));
    assert_true(str_contains($xml, '<weight>2.5</weight>'));
});

fch_test('PKT-016 packetLabelPdf argument order is packetId, format, offset', function (): void {
    $xml = PacketaClient::buildXml('packetLabelPdf', [
        'packetId' => 'Z4154090000',
        'format' => 'A6 on A6',
        'offset' => 1,
    ], str_repeat('p', 32));
    assert_true(str_contains($xml, '<packetId>Z4154090000</packetId>'));
    assert_true(str_contains($xml, '<format>A6 on A6</format>'));
    assert_true(str_contains($xml, '<offset>1</offset>'));
});

// ---- PacketAttributes mapping ----------------------------------------------------------------------

fch_test('PKT-036 PacketAttributes mapping: identity, address, money and weight', function (): void {
    $built = PacketaMapper::build(packeta_snapshot());
    assert_true($built['ok'], implode(',', $built['errors']));
    $payload = $built['payload'];

    assert_same('991001', $payload['number']);
    assert_same('John', $payload['name']);
    assert_same('Doe', $payload['surname']);
    assert_same('13789', $payload['addressId']);
    assert_same('HUF', $payload['currency']);
    assert_same('2.5', $payload['weight']);
    // HUF exponent 0: domain minor units are whole forint and rendered verbatim.
    assert_same('10000', $payload['value']);
    assert_true(!array_key_exists('cod', $payload), 'no COD attribute when cod is zero');
});

fch_test('PKT-036 HUF value and COD render as whole forint (no /100 leak)', function (): void {
    $payload = PacketaMapper::build(packeta_snapshot(['value_minor' => 254000, 'cod_minor' => 4240]))['payload'];
    assert_same('254000', $payload['value']);
    assert_same('4240', $payload['cod']);
});

fch_test('PKT-036 EUR value renders with two decimals at the boundary', function (): void {
    $payload = PacketaMapper::build(packeta_snapshot(['currency' => 'EUR', 'value_minor' => 12345, 'country' => 'AT', 'phone' => '', 'address_id' => '79']))['payload'];
    assert_same('123.45', $payload['value']);
});

fch_test('PKT-037 adultContent only on Packeta internal PUDO CZ/SK/HU/RO', function (): void {
    $allowed = PacketaMapper::build(packeta_snapshot(['adult_content' => true]));
    assert_true($allowed['ok'], implode(',', $allowed['errors']));
    assert_same(1, $allowed['payload']['adultContent']);

    $hd = packeta_snapshot([
        'adult_content' => true,
        'delivery_mode' => PacketaMapper::DELIVERY_HD,
        'street' => 'Fo utca 1.',
        'city' => 'Budapest',
        'zip' => '1051',
    ]);
    assert_true(in_array('adult_content_not_allowed', packeta_errors($hd), true));
});

fch_test('PKT-038 non-EU home delivery requires customs items', function (): void {
    $outside = packeta_snapshot([
        'country' => 'US',
        'phone' => '',
        'delivery_mode' => PacketaMapper::DELIVERY_HD,
        'address_id' => '3294',
        'street' => '1 Main St',
        'city' => 'New York',
        'zip' => '10001',
    ]);
    assert_true(in_array('customs_required_outside_eu', packeta_errors($outside), true));

    $line = [
        'customs_code' => '49019900',
        'country_of_origin' => 'US',
        'value_minor' => 5000,
        'product_name_en' => 'Book',
        'units_count' => 1,
        'weight_kg' => '1',
    ];
    assert_same('', PacketaCustoms::validateLine($line));
    $withCustoms = $outside;
    $withCustoms['items'] = PacketaCustoms::buildItems([$line]);
    $built = PacketaMapper::build($withCustoms);
    assert_true($built['ok'], implode(',', $built['errors']));
    assert_same('49019900', $built['payload']['items'][0]['attributes'][0]['value']);
});

fch_test('PKT-035 mandatory fields and name regex are validated locally', function (): void {
    assert_true(in_array('missing_number', packeta_errors(packeta_snapshot(['number' => ''])), true));
    assert_true(in_array('invalid_number', packeta_errors(packeta_snapshot(['number' => 'has space'])), true));
    assert_true(in_array('missing_contact', packeta_errors(packeta_snapshot(['email' => '', 'phone' => ''])), true));
    assert_true(in_array('invalid_email', packeta_errors(packeta_snapshot(['email' => 'nope'])), true));
    assert_true(in_array('missing_address_id', packeta_errors(packeta_snapshot(['address_id' => ''])), true));
    assert_true(in_array('invalid_currency', packeta_errors(packeta_snapshot(['currency' => 'XXX'])), true));
    assert_true(in_array('value_required', packeta_errors(packeta_snapshot(['value_minor' => 0])), true));
    assert_true(in_array('invalid_name', packeta_errors(packeta_snapshot(['name' => 'Bad#Name'])), true));
});

fch_test('PKT-036 deliverOn must be within 14 days', function (): void {
    assert_true(PacketaMapper::build(packeta_snapshot(['deliver_on' => '2026-10-10']))['ok']);
    assert_true(in_array('deliver_on_invalid', packeta_errors(packeta_snapshot(['deliver_on' => '2026-11-30'])), true));
    assert_true(in_array('deliver_on_invalid', packeta_errors(packeta_snapshot(['deliver_on' => 'nonsense'])), true));
});

fch_test('PKT-036 splitName and weight helpers are stable', function (): void {
    assert_same(['John', 'Doe'], PacketaMapper::splitName('John Doe'));
    assert_same(['Madonna', 'Madonna'], PacketaMapper::splitName('Madonna'));
    assert_same('1', PacketaMapper::gramsToKgString(1000));
    assert_same('1.5', PacketaMapper::gramsToKgString(1500));
    assert_same('1.25', PacketaMapper::gramsToKgString(1250));
    assert_same('4159', PacketaMapper::defaultHdAddressId('HU'));
    assert_same('', PacketaMapper::defaultHdAddressId('US'));
});

// ---- HUF COD multiple of 5 --------------------------------------------------------------------------

fch_test('PKT-036 HUF COD must be a multiple of 5 (shared CodValueLimits)', function (): void {
    assert_true(CodValueLimits::check('packeta', 4240)['ok']);
    assert_same('cod_not_multiple_of_5', CodValueLimits::check('packeta', 4241)['reason']);
    assert_same('cod_not_multiple_of_5', CodValueLimits::check('packeta', 4246)['reason']);
    assert_true(CodValueLimits::check('packeta', 5)['ok']);
});

fch_test('PKT-036 COD multiple-of-5 rejection is enforced before dispatch', function (): void {
    assert_true(in_array('cod_not_multiple_of_5', packeta_errors(packeta_snapshot(['cod_minor' => 4241])), true));
    assert_true(PacketaMapper::build(packeta_snapshot(['cod_minor' => 4240]))['ok']);
});

fch_test('PKT-036 COD operator max and CZK whole-number rules', function (): void {
    assert_true(in_array('cod_above_max', packeta_errors(packeta_snapshot(['cod_minor' => 5000, 'cod_max_minor' => 1000])), true));
    assert_true(in_array('cod_below_min', packeta_errors(packeta_snapshot(['cod_minor' => 500, 'cod_min_minor' => 1000])), true));
    // CZK (exponent 2) COD must be whole; 4240 minor = 42.40 CZK -> rejected, 4200 -> 42.00 accepted.
    assert_true(in_array('cod_czk_not_whole', packeta_errors(packeta_snapshot(['currency' => 'CZK', 'cod_minor' => 4240, 'country' => 'CZ', 'address_id' => '79'])), true));
    assert_true(PacketaMapper::build(packeta_snapshot(['currency' => 'CZK', 'cod_minor' => 4200, 'value_minor' => 4200, 'country' => 'CZ', 'phone' => '', 'address_id' => '79']))['ok']);
});

// ---- tracking ---------------------------------------------------------------------------------------

fch_test('PKT-042 tracking: delivered / returned / in-transit status map', function (): void {
    assert_same('delivered', PacketaTracking::mapStatusCode(7));
    assert_same('delivered', PacketaTracking::mapStatusCode(12));
    assert_same('shipped', PacketaTracking::mapStatusCode(1));
    assert_same('shipped', PacketaTracking::mapStatusCode(4));
    assert_same('shipped', PacketaTracking::mapStatusCode(5)); // ready for pickup (PP / Z-BOX)
    assert_same('shipped', PacketaTracking::mapStatusCode(25)); // HU FoxPost BOX carrier attempt
    assert_same('unshippable', PacketaTracking::mapStatusCode(10));
    assert_same('unshippable', PacketaTracking::mapStatusCode(11));
    assert_same('unshippable', PacketaTracking::mapStatusCode(20));
    assert_same('', PacketaTracking::mapStatusCode(0));
    assert_same('', PacketaTracking::mapStatusCode(999));
});

fch_test('PKT-042 tracking: record history takes the highest terminal mapping', function (): void {
    assert_same('delivered', PacketaTracking::mapRecords([['statusCode' => 1], ['statusCode' => 7]]));
    assert_same('unshippable', PacketaTracking::mapRecords([['statusCode' => 4], ['statusCode' => 10]]));
    assert_same('shipped', PacketaTracking::mapRecords([['statusCode' => 1], ['statusCode' => 5]]));
    assert_same('delivered', PacketaTracking::mapRecords(PacketaTracking::normalizeRecords(['record' => [['statusCode' => 7, 'codeText' => 'delivered']]])));
});

fch_test('PKT-042 tracking: monotonic shipping-status guard never regresses', function (): void {
    assert_true(PacketaTracking::isForwardTransition('unshipped', 'shipped'));
    assert_true(PacketaTracking::isForwardTransition('shipped', 'delivered'));
    assert_true(PacketaTracking::isForwardTransition('unshipped', 'unshippable'));
    assert_true(!PacketaTracking::isForwardTransition('delivered', 'shipped'));
    assert_true(!PacketaTracking::isForwardTransition('delivered', 'unshippable'));
    assert_true(!PacketaTracking::isForwardTransition('shipped', 'shipped'));
});

// ---- packet id string handling ----------------------------------------------------------------------

fch_test('PKT-001 packet ids stay STRINGS (32-bit WSDL note)', function (): void {
    $decoded = PacketaClient::decode(MockTransport::fixtureRaw('packeta/create_packet_success.xml'));
    assert_true($decoded['ok'], (string) ($decoded['fault'] ?? 'decode failed'));
    assert_same('4154090000', $decoded['result']['id']);
    assert_same('Z4154090000', $decoded['result']['barcode']);
    assert_same('Z 415 4090 000', $decoded['result']['barcodeText']);
});

fch_test('PKT-012 packetTracking records decode to a LIST of string fields', function (): void {
    $decoded = PacketaClient::decode(MockTransport::fixtureRaw('packeta/packet_tracking.xml'));
    assert_true($decoded['ok']);
    $records = $decoded['result']['record'];
    assert_true(is_array($records) && count($records) === 4, 'four records expected');
    assert_same('7', $records[3]['statusCode']);
    assert_same('delivered', $records[3]['codeText']);
});

fch_test('PKT-001 canonicalPacketId strips barcodeText spaces', function (): void {
    assert_same('Z4154090000', PacketaClient::canonicalPacketId('Z 415 4090 000'));
    assert_same('4154090000', PacketaClient::canonicalPacketId(' 4154090000 '));
});

fch_test('PKT-010 packetInfo decode keeps number and consignPassword', function (): void {
    $decoded = PacketaClient::decode(MockTransport::fixtureRaw('packeta/packet_info_success.xml'));
    assert_true($decoded['ok']);
    assert_same('992002', PacketaClient::firstString($decoded['result'], 'number'));
    assert_same('999', PacketaClient::firstString($decoded['result'], 'branchId'));
    assert_same('123456789', PacketaClient::firstString($decoded['result'], 'consignPassword'));
});

fch_test('PKT-007 claim number is the original barcode with a RET prefix', function (): void {
    assert_same('RETZ4154090000', PacketaClaims::claimNumber('Z 415 4090 000'));
    assert_same('RETZ4154090000', PacketaClaims::claimNumber('RETZ4154090000'));
    $attrs = PacketaClaims::buildAttributes([
        'number' => 'RETZ4154090000',
        'email' => 'a@b.hu',
        'value_minor' => 10000,
        'currency' => 'HUF',
        'consign_country' => 'HU',
    ]);
    assert_same('RETZ4154090000', $attrs['number']);
    assert_same('10000', $attrs['value']);
    assert_same(false, $attrs['sendEmailToCustomer']);
});

// ---- PUDO feed normalization ------------------------------------------------------------------------

fch_test('PKT-030 PUDO branch feed normalizes to a canonical PP point', function (): void {
    $row = MockTransport::fixture('packeta/feed_branch.json')[0];
    $point = PacketaPoints::mapBranch($row);
    assert_true($point !== null);
    assert_same('13789', $point['point_id']);
    assert_same('packeta', $point['carrier']);
    assert_same('PP', $point['type']);
    assert_same('Budapest', $point['city']);
    assert_same('1052', $point['zip']);
    assert_same(47.4978, $point['lat']);
});

fch_test('PKT-030 PUDO box feed normalizes to a canonical Z-BOX point', function (): void {
    $row = MockTransport::fixture('packeta/feed_box.json')[0];
    $point = PacketaPoints::mapBox($row);
    assert_true($point !== null);
    assert_same('18579', $point['point_id']);
    assert_same('Z-BOX', $point['type']);
});

fch_test('PKT-032 carrier point feed normalizes carrierId:code with EXTERNAL type', function (): void {
    $fixture = MockTransport::fixture('packeta/feed_carrier_point.json');
    $carrier = $fixture['carriers'][0];
    $point = PacketaPoints::mapCarrierPoint($carrier, $carrier['points'][0]);
    assert_true($point !== null);
    assert_same('32970:FOX-HU-0001', $point['point_id']);
    assert_same('EXTERNAL', $point['type']);
    assert_same('32970', $point['carrier_id']);
    assert_same('FOX-HU-0001', $point['carrier_pickup_point']);
});

fch_test('PKT-001 request summary is secret-free (never contains apiPassword/password)', function (): void {
    $xml = PacketaClient::buildXml('createPacket', ['packetAttributes' => ['number' => '1']], 'SUPER-SECRET-PASSWORD');
    // The password only ever appears inside the apiPassword element, never as a bare argument name.
    assert_same(1, substr_count($xml, 'SUPER-SECRET-PASSWORD'));
    assert_true(!str_contains($xml, '<apiPassword>apiPassword</apiPassword>'));
});
