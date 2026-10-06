<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;

function fch_pickup_points_fixture(): array
{
    return [
        ['point_id' => 'MPL-1', 'carrier' => 'mpl', 'name' => 'PostaPont A', 'address' => 'A u. 1', 'zip' => '1052', 'city' => 'Budapest', 'type' => 'PP', 'raw' => ['id' => 'MPL-1']],
        ['point_id' => 'MPL-2', 'carrier' => 'mpl', 'name' => 'Automata B', 'address' => 'B u. 2', 'zip' => '4024', 'city' => 'Debrecen', 'type' => 'CS', 'raw' => ['id' => 'MPL-2']],
        ['point_id' => 'GLS-1', 'carrier' => 'gls', 'name' => 'GLS Pont', 'address' => 'C u. 3', 'zip' => '1111', 'city' => 'Budapest', 'type' => 'PP', 'raw' => ['id' => 'GLS-1']],
    ];
}

fch_test('UT-15 snapshot has the exact normative shape', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate(
        ['carrier' => 'mpl', 'point_id' => 'MPL-1', 'delivery_mode' => 'PP'],
        ['required' => true, 'carrier' => 'mpl', 'allowed_types' => ['PM', 'CS', 'PP'], 'points' => fch_pickup_points_fixture()]
    );
    assert_true($result['ok']);
    assert_same(['carrier', 'point_id', 'name', 'address', 'zip', 'city', 'type', 'raw'], array_keys($result['snapshot']));
    assert_same('mpl', $result['snapshot']['carrier']);
    assert_same('MPL-1', $result['snapshot']['point_id']);
    assert_same('PP', $result['snapshot']['type']);
    assert_same(['id' => 'MPL-1'], $result['snapshot']['raw']);
});

fch_test('IT-17 unknown/stale id is rejected server-side', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate(
        ['carrier' => 'mpl', 'point_id' => 'DOES-NOT-EXIST'],
        ['required' => true, 'carrier' => 'mpl', 'points' => fch_pickup_points_fixture()]
    );
    assert_same(false, $result['ok']);
    assert_same(PickupPointValidator::ERROR_UNKNOWN, $result['error']);
    assert_same(null, $result['snapshot']);
});

fch_test('PICKUP-VAL required with empty selection yields pickup_required', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate([], ['required' => true, 'carrier' => 'mpl']);
    assert_same(PickupPointValidator::ERROR_REQUIRED, $result['error']);
});

fch_test('PROV-17 provenance extension is appended only when the caller supplies it', function (): void {
    $points = [[
        'point_id' => 'MPL-PROV', 'carrier' => 'mpl', 'name' => 'Provenance pont', 'address' => 'A 1.',
        'zip' => '1051', 'city' => 'Budapest', 'type' => 'PP', 'raw' => ['id' => 'MPL-PROV'],
    ]];
    $validator = new PickupPointValidator();
    $base = ['required' => true, 'carrier' => 'mpl', 'allowed_types' => ['PP'], 'points' => $points];

    $plain = $validator->validate(['carrier' => 'mpl', 'point_id' => 'MPL-PROV'], $base);
    assert_same(['carrier', 'point_id', 'name', 'address', 'zip', 'city', 'type', 'raw'], array_keys($plain['snapshot']));

    $provenance = [
        'selector_driver' => 'local',
        'authoritative_source' => 'mpl',
        'source_fetched_at' => 1700000000,
        'directory_version' => 'abc123',
        'validated_at' => 1700000100,
        'validation_method' => 'directory_cache',
    ];
    $extended = $validator->validate(['carrier' => 'mpl', 'point_id' => 'MPL-PROV'], $base + $provenance);
    assert_same('local', $extended['snapshot']['selector_driver']);
    assert_same('mpl', $extended['snapshot']['authoritative_source']);
    assert_same(1700000000, $extended['snapshot']['source_fetched_at']);
    assert_same('directory_cache', $extended['snapshot']['validation_method']);
    // Old 8 keys stay first and unchanged (backward compatible extension).
    assert_same(['carrier', 'point_id', 'name', 'address', 'zip', 'city', 'type', 'raw'], array_slice(array_keys($extended['snapshot']), 0, 8));
});

fch_test('PICKUP-VAL optional with empty selection is ok and snapshot-less', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate([], ['required' => false, 'carrier' => '']);
    assert_true($result['ok']);
    assert_same(null, $result['snapshot']);
});

fch_test('PICKUP-VAL carrier mismatch is rejected', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate(
        ['carrier' => 'gls', 'point_id' => 'MPL-1'],
        ['required' => true, 'carrier' => 'mpl', 'points' => fch_pickup_points_fixture()]
    );
    assert_same(PickupPointValidator::ERROR_CARRIER_MISMATCH, $result['error']);
});

fch_test('PICKUP-VAL point-carrier mismatch is rejected', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate(
        ['carrier' => 'mpl', 'point_id' => 'GLS-1'],
        ['required' => true, 'carrier' => 'mpl', 'points' => fch_pickup_points_fixture()]
    );
    assert_same(PickupPointValidator::ERROR_CARRIER_MISMATCH, $result['error']);
});

fch_test('PICKUP-VAL type mismatch (parcel terminal vs PostaPont-only method) is rejected', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate(
        ['carrier' => 'mpl', 'point_id' => 'MPL-1', 'delivery_mode' => 'PP'],
        ['required' => true, 'carrier' => 'mpl', 'allowed_types' => ['CS'], 'points' => fch_pickup_points_fixture()]
    );
    assert_same(PickupPointValidator::ERROR_TYPE_MISMATCH, $result['error']);
});

fch_test('PICKUP-VAL accepts injected lookup callable (directory abstraction)', function (): void {
    $validator = new PickupPointValidator();
    $result = $validator->validate(
        ['carrier' => 'mpl', 'point_id' => 'MPL-2'],
        [
            'required' => true,
            'carrier' => 'mpl',
            'allowed_types' => ['CS'],
            'lookup' => static fn(string $id): ?array => $id === 'MPL-2'
                ? ['point_id' => 'MPL-2', 'carrier' => 'mpl', 'type' => 'CS', 'name' => 'x', 'zip' => '1', 'city' => 'y', 'address' => 'z', 'raw' => []]
                : null,
        ]
    );
    assert_true($result['ok']);
    assert_same('CS', $result['snapshot']['type']);
});

fch_test('PICKUP-VAL message() returns HU text and codes are stable', function (): void {
    assert_true(str_contains(PickupPointValidator::message(PickupPointValidator::ERROR_REQUIRED), 'átvételi'));
    assert_same('pickup_type_mismatch', PickupPointValidator::ERROR_TYPE_MISMATCH);
});
