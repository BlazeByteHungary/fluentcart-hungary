<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Shipping\Mpl\MplTracking;

/**
 * Pure unit tests for the C43/C9 → FluentCart shipping-status mapping (04_MPL_CONTRACT.md §6.1).
 * instantiate MplTracking with an unused client; only the pure mapping is exercised.
 */

function mpl_tracking_subject(): MplTracking
{
    return new MplTracking();
}

fch_test('MPL tracking: category 5 delivered -> delivered', function (): void {
    assert_same('delivered', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 5, 'c9' => 'delivered']]));
});

fch_test('MPL tracking: C9 delivered without category -> delivered', function (): void {
    assert_same('delivered', mpl_tracking_subject()->mapEventsToShippingStatus([['last_event_type' => 'Delivered']]));
});

fch_test('MPL tracking: acceptance (category 1) -> shipped', function (): void {
    assert_same('shipped', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 1, 'c9' => 'accepted']]));
});

fch_test('MPL tracking: processing/transport (category 2/3) -> shipped', function (): void {
    assert_same('shipped', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 3, 'c9' => 'transport']]));
    assert_same('shipped', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 2, 'c9' => 'feldolgozas']]));
});

fch_test('MPL tracking: returned to sender -> unshippable', function (): void {
    assert_same('unshippable', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 4, 'c9' => 'returned to sender']]));
});

fch_test('MPL tracking: pre-advised -> empty (never guess)', function (): void {
    assert_same('', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 0, 'c9' => 'pre-advised']]));
});

fch_test('MPL tracking: empty or unknown -> empty', function (): void {
    assert_same('', mpl_tracking_subject()->mapEventsToShippingStatus([]));
    assert_same('', mpl_tracking_subject()->mapEventsToShippingStatus([['foo' => 'bar']]));
    assert_same('', mpl_tracking_subject()->mapEventsToShippingStatus([['c43' => 'not-a-number']]));
});

fch_test('MPL tracking: nested events list is flattened and delivered wins', function (): void {
    $entry = [[
        'trackingNumber' => 'MPL1',
        'events' => [
            ['c43' => 1, 'c9' => 'accepted'],
            ['c43' => 5, 'c9' => 'delivered'],
        ],
    ]];
    assert_same('delivered', mpl_tracking_subject()->mapEventsToShippingStatus($entry));
});

fch_test('MPL tracking: delivered beats a later returned event (terminal precedence)', function (): void {
    $events = [
        ['c43' => 5, 'c9' => 'delivered'],
        ['c43' => 4, 'c9' => 'returned to sender'],
    ];
    assert_same('delivered', mpl_tracking_subject()->mapEventsToShippingStatus($events));
});

fch_test('MPL tracking: monotonic shipping-status guard', function (): void {
    assert_true(MplTracking::isForwardTransition('unshipped', 'shipped'));
    assert_true(MplTracking::isForwardTransition('shipped', 'delivered'));
    assert_true(MplTracking::isForwardTransition('unshipped', 'unshippable'));
    assert_true(!MplTracking::isForwardTransition('delivered', 'shipped'));
    assert_true(!MplTracking::isForwardTransition('delivered', 'unshippable'));
    assert_true(!MplTracking::isForwardTransition('shipped', 'shipped'));
});
