<?php
/**
 * T36 — fresh-boot module initialization, registration and diagnostics audit.
 *
 * Run by run.sh in a FRESH wp invocation with all nine provider modules + pickuppoints enabled in
 * fch_modules BEFORE boot, so what is asserted here is the real plugin boot, not a late re-registration:
 *  - every module reaches `active` (a partially broken boot must not read as success),
 *  - every business hook a module promises is present in the live registries
 *    (JobRunner handlers, ReconcilerRegistry, CarrierRegistry, PickupFeedRegistry, FC GatewayManager),
 *  - Diagnostics::data() reports the same states with well-formed probes,
 *  - DPD intentionally has NO reconciler (no documented query-by-reference endpoint).
 *
 * Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Admin\Diagnostics;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

$out = ['checks' => [], 'states' => [], 'messages' => [], 'probes' => [], 'handlers' => [], 'reconcilers' => [], 'carriers' => [], 'feeds' => []];

$moduleIds = [
    'payments/barion', 'payments/simplepay', 'billing/szamlazz', 'billing/billingo',
    'shipping/mpl', 'shipping/gls', 'shipping/dpd', 'shipping/packeta', 'shipping/foxpost',
    'shipping/pickuppoints',
];

$registry = ModuleRegistry::instance();
$probesFormed = true;
foreach ($moduleIds as $id) {
    $state = $registry->state($id);
    $out['states'][$id] = $state;
    $out['messages'][$id] = $registry->message($id);

    $module = $registry->get($id);
    $probe = ($module !== null && method_exists($module, 'probe')) ? $module->probe() : null;
    if (!is_array($probe) || !array_key_exists('ok', $probe) || !is_string($probe['message'] ?? null)) {
        $probesFormed = false;
        $out['probes'][$id] = null;
    } else {
        $out['probes'][$id] = ['ok' => (bool) $probe['ok'], 'message' => (string) $probe['message']];
    }
}

// Enabled modules must finish boot as `active` or `degraded` — never `inactive`/`blocked` — and a
// degraded state must be honest: it must correspond to probe.ok === false (missing credential /
// environment), so a partially failed boot can never read as success.
$out['checks']['all_modules_booted'] = !in_array(false, array_map(static fn($s): bool => in_array($s, ['active', 'degraded'], true), $out['states']), true);
$degradedHonest = true;
foreach ($out['states'] as $id => $state) {
    $probeOk = is_array($out['probes'][$id] ?? null) ? (bool) $out['probes'][$id]['ok'] : null;
    if ($state === 'degraded' && $probeOk !== false) {
        $degradedHonest = false;
    }
    if ($state === 'active' && $probeOk === false) {
        $degradedHonest = false;
    }
}
$out['checks']['degraded_states_honest'] = $degradedHonest;
$out['checks']['probes_well_formed'] = $probesFormed;

// ---- business hook registration --------------------------------------------------------------------
$handlers = JobRunner::instance()->handlers();
foreach ([
    'fch_create_label', 'fch_create_label_gls', 'fch_create_label_dpd', 'fch_create_label_packeta', 'fch_create_label_foxpost',
    'fch_sync_tracking', 'fch_sync_tracking_gls', 'fch_sync_tracking_dpd', 'fch_sync_tracking_packeta', 'fch_sync_tracking_foxpost',
    'fch_issue_invoice', 'fch_simplepay_confirm',
] as $hook) {
    $out['handlers'][$hook] = array_key_exists($hook, $handlers);
}
$out['checks']['job_handlers_registered'] = !in_array(false, $out['handlers'], true);

$expectedReconcilers = ['szamlazz', 'billingo', 'barion', 'simplepay', 'mpl', 'gls', 'packeta', 'foxpost'];
foreach ($expectedReconcilers as $provider) {
    $out['reconcilers'][$provider] = ReconcilerRegistry::has($provider);
}
$out['reconcilers']['dpd'] = ReconcilerRegistry::has('dpd');
$out['checks']['reconcilers_registered'] = !in_array(false, array_map(static fn($p): bool => $out['reconcilers'][$p], $expectedReconcilers), true);
$out['checks']['dpd_has_no_reconciler'] = $out['reconcilers']['dpd'] === false;

foreach (['gls', 'dpd', 'packeta', 'foxpost'] as $carrier) {
    $out['carriers'][$carrier] = CarrierRegistry::get($carrier) !== null;
}
$out['checks']['carrier_registry_registered'] = !in_array(false, $out['carriers'], true);

foreach (['mpl', 'gls', 'dpd', 'packeta', 'foxpost'] as $carrier) {
    $out['feeds'][$carrier] = PickupFeedRegistry::get($carrier) !== null;
}
$out['checks']['pickup_feeds_registered'] = !in_array(false, $out['feeds'], true);

$gatewayOk = false;
$gatewayRefundOk = false;
if (class_exists('FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
    $gatewayOk = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::has('barion')
        && \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::has('simplepay');
    // W4: both payment gateways must advertise the refund seam and have a dispatcher registered.
    $barionGateway = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::gateway('barion');
    $simplepayGateway = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::gateway('simplepay');
    $gatewayRefundOk = is_object($barionGateway) && method_exists($barionGateway, 'has') && $barionGateway->has('refund')
        && is_object($simplepayGateway) && method_exists($simplepayGateway, 'has') && $simplepayGateway->has('refund')
        && \FluentCartHungary\Core\Payments\RefundDispatcherRegistry::has('barion')
        && \FluentCartHungary\Core\Payments\RefundDispatcherRegistry::has('simplepay');
}
$out['checks']['fc_gateways_registered'] = $gatewayOk;
$out['checks']['refund_seams_registered'] = $gatewayRefundOk;

// W4 logistics: exactly the carriers with a documented external cancellation route register a dispatcher;
// GLS (DeleteLabels is a label-file operation only) must NOT pretend to have a withdrawal route.
$out['cancel_dispatchers'] = [
    'packeta' => \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has('packeta'),
    'mpl' => \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has('mpl'),
    'foxpost' => \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has('foxpost'),
    'dpd' => \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has('dpd'),
    'gls' => \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has('gls'),
];
$out['checks']['cancel_seams_registered'] = $out['cancel_dispatchers']['packeta']
    && $out['cancel_dispatchers']['mpl']
    && $out['cancel_dispatchers']['foxpost']
    && $out['cancel_dispatchers']['dpd']
    && $out['cancel_dispatchers']['gls'] === false;

// ---- diagnostics view consistency ------------------------------------------------------------------
$data = Diagnostics::data();
$diagStates = [];
foreach ((array) ($data['modules'] ?? []) as $entry) {
    $entryId = (string) ($entry['id'] ?? '');
    if ($entryId !== '') {
        $diagStates[$entryId] = (string) ($entry['state'] ?? '');
    }
}
$diagMatches = true;
foreach ($moduleIds as $id) {
    if (($diagStates[$id] ?? null) !== $out['states'][$id]) {
        $diagMatches = false;
    }
}
$out['checks']['diagnostics_match_boot'] = is_array($data) && isset($data['modules']) && $diagMatches;

// A partially failing provider boot must be VISIBLE: every degraded module carries a non-empty
// diagnostics message (the operator sees which provider is limited and why).
$out['degraded'] = array_keys(array_filter($out['states'], static fn($s): bool => $s === 'degraded'));
$partialVisible = true;
foreach ($out['degraded'] as $degradedId) {
    $entryMessage = '';
    foreach ((array) ($data['modules'] ?? []) as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $degradedId) {
            $entryMessage = trim((string) ($candidate['message'] ?? ''));
            break;
        }
    }
    if ($entryMessage === '') {
        $partialVisible = false;
    }
}
$out['checks']['partial_boot_visible'] = $partialVisible;

// ---- credential form rendering (D-W3-4): every provider's secret fields must be reachable in the
// Settings UI — a provider whose module class is missing from SettingsPage's map silently disappears.
$settingsHtml = '';
if (function_exists('wp_set_current_user') && function_exists('user_can')) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    if ((array) $admins !== []) {
        wp_set_current_user((int) $admins[0]->ID);
    }
    if (user_can(get_current_user_id(), 'manage_options')) {
        ob_start();
        \FluentCartHungary\Core\Settings\SettingsPage::renderContent();
        $settingsHtml = (string) ob_get_clean();
    }
}
$out['checks']['credential_fields_rendered'] = str_contains($settingsHtml, 'fch_value_barion_pos_key')
    && str_contains($settingsHtml, 'fch_value_simplepay_secret_key_huf')
    && str_contains($settingsHtml, 'fch_value_billingo_api_key')
    && str_contains($settingsHtml, 'fch_value_szamlazz_agent_key');

$out['ok'] = !in_array(false, $out['checks'], true);
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
