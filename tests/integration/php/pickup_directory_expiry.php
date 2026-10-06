<?php
/**
 * T44 — directory lifecycle gate: fresh/stale/expired policy (checkout 3/3 release gate #3).
 *
 * The last-good list is kept for operations, but it is not selectable forever: beyond the documented
 * expiry window (ttl × multiplier) a point can neither be saved nor ordered. A stale list is still
 * selectable and visibly flagged. The version fingerprint changes with the directory generation so
 * pagination can never mix generations.
 */

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;

$out = ['ok' => true, 'checks' => [], 'note' => 'fresh/stale/expired policy; no network, no order'];

if (!class_exists('FchExpiryFakeCart', false)) {
    class FchExpiryFakeCart
    {
        /** @var array<string,mixed> */
        public array $checkout_data = [];
        public string $stage = 'draft';
        public int $saves = 0;

        public function save(): bool
        {
            $this->saves++;
            return true;
        }
    }
}

(new ModuleSettings())->set('shipping', 'pickup_expiry_multiplier', '2');
$shipSettings = new ShippingMethodSettings();
$shipSettings->saveForMethod(93201, ['carrier' => 'mpl', 'service' => 'pickup', 'service_types' => 'point,locker']);

$directory = new PickupDirectory();
$points = [['point_id' => 'OFX-PP', 'carrier' => 'mpl', 'name' => 'OFX Pont', 'address' => 'Teszt 3.', 'zip' => '1053', 'city' => 'Budapest', 'type' => 'PP']];
$directory->importStatic($points, 'mpl');

$validator = new PickupPointValidator();
$saved = static fn (): array => (new PickupDirectory())->load('mpl');

// --- A: state policy through the real option store -------------------------------------------------
$now = time();
$directory->save('mpl', $points, 'static', 3600, $now - 100);
$out['checks']['state_fresh'] = PickupDirectory::stateAt($saved(), $now) === 'fresh';
$directory->save('mpl', $points, 'static', 3600, $now - 4000);
$out['checks']['state_stale'] = PickupDirectory::stateAt($saved(), $now) === 'stale';
$directory->save('mpl', $points, 'static', 3600, $now - 8000);
$out['checks']['state_expired'] = PickupDirectory::stateAt($saved(), $now) === 'expired';

// --- B: validation gate -----------------------------------------------------------------------------
$context = static fn (string $state): array => [
    'required' => true,
    'carrier' => 'mpl',
    'allowed_types' => ['point', 'locker'],
    'directory' => new PickupDirectory(),
    'directory_state' => $state,
];
$selection = ['carrier' => 'mpl', 'point_id' => 'OFX-PP', 'delivery_mode' => 'PP'];

$expiredResult = $validator->validate($selection, $context('expired'));
$out['checks']['validator_rejects_expired'] = ($expiredResult['ok'] ?? true) === false
    && ($expiredResult['error'] ?? '') === PickupPointValidator::ERROR_EXPIRED;
$staleResult = $validator->validate($selection, $context('stale'));
$out['checks']['validator_allows_stale'] = ($staleResult['ok'] ?? false) === true;
$freshResult = $validator->validate($selection, $context('fresh'));
$out['checks']['validator_allows_fresh'] = ($freshResult['ok'] ?? false) === true;

// --- C: the save endpoint must not persist from an expired directory --------------------------------
$handler = new PickupCheckoutHandler(new PickupDirectory(), new PickupPointValidator());
$cart = new FchExpiryFakeCart();
$cart->checkout_data = ['shipping_data' => ['shipping_method_id' => 93201]];
$before = $cart->checkout_data;
$savesBefore = $cart->saves;
$result = $handler->saveSelection($cart, [
    'point' => (string) wp_json_encode($selection),
    'base_rev' => 0,
    'method_id' => 93201,
]);
$out['checks']['save_rejected_on_expired'] = ($result['ok'] ?? true) === false
    && ($result['code'] ?? '') === PickupPointValidator::ERROR_EXPIRED
    && $cart->checkout_data === $before
    && $cart->saves === $savesBefore;

// --- D: version fingerprint follows the directory generation ----------------------------------------
$directory->save('mpl', $points, 'static', 3600, time());
$v1 = $directory->versionSet(['mpl']);
$directory->save('mpl', array_merge($points, [['point_id' => 'OFX-CS', 'carrier' => 'mpl', 'name' => 'OFX Automata', 'address' => 'Teszt 4.', 'zip' => '1054', 'city' => 'Budapest', 'type' => 'CS']]), 'static', 3600, time());
$out['checks']['version_changes_with_generation'] = $directory->versionSet(['mpl']) !== $v1;

foreach ($out['checks'] as $name => $ok) {
    if (!$ok) {
        $out['ok'] = false;
        $out['failed'][] = $name;
    }
}

echo wp_json_encode($out) . "\n";
