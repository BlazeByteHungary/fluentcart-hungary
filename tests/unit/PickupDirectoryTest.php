<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

fch_test('PICKUP-DIR normalize() folds HU accents and case', function (): void {
    assert_same('budapest', PickupDirectory::normalize('Budapest'));
    assert_same('obuda', PickupDirectory::normalize('Óbuda'));
    assert_same('gyor', PickupDirectory::normalize('Győr'));
    assert_same('szeged', PickupDirectory::normalize('  SZEGED '));
    assert_same('', PickupDirectory::normalize(''));
});

fch_test('PICKUP-DIR normalizePoint() accepts canonical and provider keys', function (): void {
    $canonical = PickupDirectory::normalizePoint([
        'point_id' => 'P1', 'name' => 'Teszt Pont', 'zip' => '1052', 'city' => 'Budapest', 'type' => 'CS',
    ], 'mpl');
    assert_same('P1', $canonical['point_id']);
    assert_same('mpl', $canonical['carrier']);
    assert_same('CS', $canonical['type']);

    $provider = PickupDirectory::normalizePoint([
        'id' => 'P2', 'deliveryplace' => 'PostaPont', 'postCode' => '4024', 'city' => 'Debrecen',
        'address' => 'Fő utca 1.', 'servicePointType' => 'pp',
    ], 'mpl');
    assert_same('P2', $provider['point_id']);
    assert_same('PP', $provider['type']);
    assert_same('4024', $provider['zip']);
    assert_same('Fő utca 1.', $provider['address']);
});

fch_test('PICKUP-DIR normalizePoint() drops entries without an id', function (): void {
    assert_same(null, PickupDirectory::normalizePoint(['name' => 'no id'], 'mpl'));
});

fch_test('PICKUP-DIR mapMplResult() uses 04_MPL fields and keeps raw', function (): void {
    $result = ['id' => 'ABC', 'deliveryplace' => 'PostaPont ABC', 'postCode' => '1111', 'city' => 'Budapest', 'address' => 'X', 'geocodeLat' => 47.5, 'geocodeLong' => 19.0];
    $point = PickupDirectory::mapMplResult($result, 'mpl');
    assert_same('ABC', $point['point_id']);
    assert_same('PostaPont ABC', $point['name']);
    assert_same(47.5, $point['lat']);
    assert_same($result, $point['raw']);
    assert_same(null, PickupDirectory::mapMplResult(['deliveryplace' => 'no id'], 'mpl'));
});

fch_test('PICKUP-DIR filterPoints() matches zip, city and name; empty query returns head', function (): void {
    $points = [
        ['point_id' => '1', 'zip' => '1052', 'city' => 'Budapest', 'name' => 'Deák tér'],
        ['point_id' => '2', 'zip' => '4024', 'city' => 'Debrecen', 'name' => 'Fő utca'],
        ['point_id' => '3', 'zip' => '9021', 'city' => 'Győr', 'name' => 'Baross út'],
    ];
    assert_same('1', PickupDirectory::filterPoints($points, '1052', 30)[0]['point_id']);
    assert_same('2', PickupDirectory::filterPoints($points, 'debrecen', 30)[0]['point_id']);
    assert_same('3', PickupDirectory::filterPoints($points, 'gyor', 30)[0]['point_id']);
    assert_same('3', PickupDirectory::filterPoints($points, 'baross', 30)[0]['point_id']);
    assert_same([], PickupDirectory::filterPoints($points, 'nincsilyen', 30));
    assert_same(3, count(PickupDirectory::filterPoints($points, '', 30)));
    assert_same(2, count(PickupDirectory::filterPoints($points, '', 2)));
});

fch_test('PICKUP-DIR freshness window is computed from fetched_at + ttl', function (): void {
    $entry = ['fetched_at' => 1000, 'ttl' => 600, 'points' => []];
    assert_true(PickupDirectory::isFreshAt($entry, 1599));
    assert_same(false, PickupDirectory::isFreshAt($entry, 1601));
    assert_same(600, PickupDirectory::ageAt($entry, 1600));
    assert_same(null, PickupDirectory::ageAt(['fetched_at' => 0], 1600));
});
