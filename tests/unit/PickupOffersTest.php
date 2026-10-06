<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\PickupPoints\PickupOffers;

/**
 * Checkout 3/3 gate #2 — offer resolution policy (WP-free parts):
 * current method wins, single offer is direct, several offers without a current method require an
 * explicit choice (0), and only kind/carrier-accepting bound methods become offers.
 */
fch_test('OF-CHOOSE-01 choose keeps the current method, uses single offers, otherwise demands choice', function (): void {
    $offers = [['method_id' => 11], ['method_id' => 22]];
    assert_same(22, PickupOffers::choose($offers, 22));
    assert_same(11, PickupOffers::choose($offers, 11));
    assert_same(0, PickupOffers::choose($offers, 0), 'several applicable offers need an explicit choice');
    assert_same(0, PickupOffers::choose($offers, 33), 'a current method outside the offers does not win');
    assert_same(5, PickupOffers::choose([['method_id' => 5]], 0));
    assert_same(0, PickupOffers::choose([], 7));
});

fch_test('OF-OFFER-02 offersForPoint filters by carrier, kind and applicability source', function (): void {
    $bindings = [
        11 => ['carrier' => 'mpl', 'service' => 'pickup', 'kinds' => [], 'legacy' => false],
        22 => ['carrier' => 'mpl', 'service' => 'pickup', 'kinds' => ['locker'], 'legacy' => false],
        33 => ['carrier' => 'foxpost', 'service' => 'locker', 'kinds' => [], 'legacy' => false],
    ];
    $staffPoint = ['carrier' => 'mpl', 'type' => 'PP'];
    $lockerPoint = ['carrier' => 'mpl', 'type' => 'CS'];

    // FluentCart unavailable: bound + accepting methods only; no method rows → unknown price, never a
    // fabricated one.
    $staff = PickupOffers::offersForPoint($staffPoint, $bindings, [], 'unavailable');
    assert_same([11], array_column($staff, 'method_id'));
    assert_same(false, $staff[0]['price_known']);
    assert_same('', $staff[0]['price_display']);

    $locker = PickupOffers::offersForPoint($lockerPoint, $bindings, [], 'unavailable');
    assert_same([11, 22], array_column($locker, 'method_id'));

    // Country/zone eligibility filter applies when FluentCart provided the applicable id set.
    $filtered = PickupOffers::offersForPoint($lockerPoint, $bindings, [22], 'fluentcart_country');
    assert_same([22], array_column($filtered, 'method_id'));
    assert_same([], PickupOffers::offersForPoint($lockerPoint, $bindings, [99], 'fluentcart_country'));
    assert_same([], PickupOffers::offersForPoint(['carrier' => 'gls', 'type' => 'PSD'], $bindings, [], 'unavailable'));
});

fch_test('OF-AMOUNT-03 formatAmount falls back to a clearly local format (no client math assumptions)', function (): void {
    assert_true(PickupOffers::formatAmount(1490.0) !== '');
    assert_true(str_contains(PickupOffers::formatAmount(0.0), '0'));
});
