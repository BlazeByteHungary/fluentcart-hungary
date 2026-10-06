<?php
/**
 * T39 — W4 logistics lifecycle (mock-only, real plugin boot).
 *
 * Covers: Packeta return claims routed through the ledger (PKT-007: success/replay, local error + resume,
 * lost response + manual resolution), shipment cancellation lifecycle (Packeta + MPL read-back proofs,
 * Foxpost direct proof, local cancellation of a never-dispatched intent, unsupported carrier block,
 * parallel request dedup), FC order cancellation never touching a carrier, late label/tracking events
 * after a cancellation, and label-file preservation after an external cancellation.
 *
 * Precondition (run.sh): billing/szamlazz, billing/billingo, payments/barion and ALL five shipping
 * modules are enabled before this process boots, so the dispatchers/reconcilers are the ones the real
 * plugin boot registered. Every provider host is mocked with a catch-all timeout — no network call can
 * escape. Prints exactly one JSON line.
 */

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CancelDispatcherRegistry;
use FluentCartHungary\Core\Shipping\ShipmentCancellationService;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaClaimService;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaPacketService;
use FluentCartHungary\Tests\Mock\MockTransport;

require_once dirname(__DIR__, 2) . '/mock/MockTransport.php';

$out = ['ok' => false, 'checks' => [], 'cases' => [], 'errors' => []];
if (!defined('FCH_CREDENTIALS_KEY')) {
    define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
}
global $wpdb;

$out['checks']['modules_booted'] = [
    'packeta' => in_array(ModuleRegistry::instance()->state('shipping/packeta'), ['active', 'degraded'], true),
    'mpl' => in_array(ModuleRegistry::instance()->state('shipping/mpl'), ['active', 'degraded'], true),
    'foxpost' => in_array(ModuleRegistry::instance()->state('shipping/foxpost'), ['active', 'degraded'], true),
    'gls' => in_array(ModuleRegistry::instance()->state('shipping/gls'), ['active', 'degraded'], true),
];
$out['checks']['cancel_dispatchers'] = [
    'packeta' => CancelDispatcherRegistry::has('packeta'),
    'mpl' => CancelDispatcherRegistry::has('mpl'),
    'foxpost' => CancelDispatcherRegistry::has('foxpost'),
    'dpd' => CancelDispatcherRegistry::has('dpd'),
    'gls' => CancelDispatcherRegistry::has('gls'),
];

// ---------------------------------------------------------------- cleanup + settings
foreach (range(998201, 998220) as $oid) {
    foreach ([
        "DELETE FROM {$wpdb->prefix}fch_invoice_documents WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fch_shipments WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d",
        "DELETE FROM {$wpdb->prefix}fct_orders WHERE id = %d",
    ] as $sql) {
        $wpdb->query($wpdb->prepare($sql, $oid));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fch_jobs WHERE job_key LIKE %s", '%' . $oid . '%'));
}
$wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_transactions WHERE order_id BETWEEN 998201 AND 998220");

$settings = new ModuleSettings();
$settings->setMode('test');
foreach (['packeta', 'mpl', 'foxpost', 'gls', 'dpd'] as $scope) {
    $settings->set($scope, 'account_id', 'default');
}
$settings->set('packeta', 'eshop', 't39-lab');
$settings->set('packeta', 'label_format', 'A6 on A6');
$settings->set('packeta', 'claim_send_email', false);
$settings->set('mpl', 'accounting_code', '13965');
$settings->set('dpd', 'customer_id', 'T39-CUSTOMER');
$settings->set('dpd', 'bu_code', '123');
$creds = new CredentialStore();
$creds->set('packeta', 'test', 'default', 'api_password', 'T39-PACKETA-API-PASSWORD');
$creds->set('mpl', 'test', 'default', 'api_key', 't39-mpl-key');
$creds->set('mpl', 'test', 'default', 'api_secret', 't39-mpl-secret');
$creds->set('foxpost', 'test', 'default', 'username', 't39-fox');
$creds->set('foxpost', 'test', 'default', 'password', 't39-fox-pass');
$creds->set('foxpost', 'test', 'default', 'api_key', 't39-fox-api-key');

$GLOBALS['t39'] = [
    'pk_create' => 0, 'pk_label' => 0, 'pk_info' => 0, 'pk_status' => 0, 'pk_claim' => 0, 'pk_cancel' => 0,
    'mpl_delete' => 0, 'mpl_get' => 0,
    'fox_delete' => 0, 'fox_tracks' => 0,
    'pk_create_timeout' => false, 'pk_claim_timeout' => false, 'pk_cancel_timeout' => false,
    'mpl_delete_timeout' => false, 'fox_delete_timeout' => false,
    'pk_status_code' => 5,          // 5 = ready for pickup (not cancelled)
    'pk_claim_created' => [],       // keys created even when the response is lost
    'pk_cancel_applied' => false,   // cancelPacket side effect applied even when the response is lost
    'mpl_deleted' => false,
];
$t39 = static function (): array {
    return $GLOBALS['t39'];
};

// ---------------------------------------------------------------- fixture helpers
$createOrder = static function (int $orderId, int $cents, string $method = 'bank_transfer', bool $paid = true): bool {
    $orderClass = 'FluentCart\App\Models\Order';
    $itemClass = 'FluentCart\App\Models\OrderItem';
    $addressClass = 'FluentCart\App\Models\OrderAddress';
    if (!class_exists($orderClass) || !class_exists($itemClass)) {
        return false;
    }
(new \FluentCartHungary\Modules\Shipping\CoreShipping\ShippingMethodSettings())->saveForMethod(94105, ['carrier' => 'packeta', 'service' => 'home']); // 3/3 routing gate binding
    $order = $orderClass::create([
        'status' => 'processing',
        'payment_status' => $paid ? 'paid' : 'pending',
        'payment_method' => $method,
        'type' => 'payment',
        'fulfillment_type' => 'physical',
        'customer_id' => 0,
        'currency' => 'HUF',
        'subtotal' => $cents,
        'tax_total' => 0,
        'total_amount' => $cents,
        'total_paid' => $paid ? $cents : 0,
        'tax_behavior' => 0,
        'mode' => 'test',
        'config' => ['shipping_method_id' => 94105, 'shipping_method_title' => 'Lab'],
    ]);
    global $wpdb;
    $actual = (int) $order->id;
    if ($actual !== $orderId) {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET id = %d WHERE id = %d", $orderId, $actual));
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_order_items SET order_id = %d WHERE order_id = %d", $orderId, $actual));
    }
    $itemClass::create([
        'order_id' => $orderId,
        'title' => 'T39 logisztikai tétel',
        'quantity' => 1,
        'unit_price' => $cents,
        'subtotal' => $cents,
        'tax_amount' => 0,
        'discount_total' => 0,
        'line_total' => $cents,
        'rate' => 0,
        'payment_type' => 'onetime',
        'fulfillment_type' => 'physical',
    ]);
    if (class_exists($addressClass)) {
        $addressClass::create([
            'order_id' => $orderId,
            'type' => 'shipping',
            'name' => 'T39 Logisztika Vevő',
            'address_1' => 'Fő utca 1',
            'city' => 'Budapest',
            'state' => '',
            'postcode' => '1052',
            'country' => 'HU',
            'meta' => [
                'phone' => '+36301112223',
                'email' => 't39-lab@example.test',
                'other_data' => ['phone' => '+36301112223', 'email' => 't39-lab@example.test'],
            ],
        ]);
    }
    return true;
};

$seedConfirmedShipment = static function (int $orderId, string $carrier, string $externalId, string $tracking, int $sequence = 1, array $extra = []): int {
    $repo = new ShipmentRepository();
    $key = sprintf('fch:%d:%s:label_create:%d', $orderId, $carrier, $sequence);
    $created = $repo->getOrCreate(array_merge([
        'order_id' => $orderId,
        'carrier' => $carrier,
        'mode' => 'test',
        'account_id' => 'default',
        'shipment_no' => $sequence,
        'idempotency_key' => $key,
        'external_shipment_id' => $externalId,
        'barcode' => $externalId,
        'tracking_number' => $tracking,
        'currency' => 'HUF',
        'cod_amount_minor' => 0,
    ], $extra));
    $id = (int) $created['row']->id;
    $repo->markStarted($id);
    $repo->markConfirmed($id, ['external_shipment_id' => $externalId, 'barcode' => $externalId, 'tracking_number' => $tracking]);
    return $id;
};

$resetTransient = static function (): void {
    foreach (['pk_create_timeout', 'pk_claim_timeout', 'pk_cancel_timeout', 'mpl_delete_timeout', 'fox_delete_timeout'] as $flag) {
        $GLOBALS['t39'][$flag] = false;
    }
};

$packetaRouter = static function ($url, $args) {
    $g = &$GLOBALS['t39'];
    $body = (string) ($args['body'] ?? '');
    $val = static function (string $body, string $tag): string {
        return preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/', $body, $m)
            ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'))
            : '';
    };
    if (str_contains($body, '<createPacket>')) {
        $g['pk_create']++;
        if ($g['pk_create_timeout']) {
            return MockTransport::timeout();
        }
        $packetId = 'T39PK' . (7000 + $g['pk_create']);
        return MockTransport::response(200, '<response><status>ok</status><result><id>' . $packetId . '</id>'
            . '<barcode>' . $packetId . '</barcode><number>' . $val($body, 'number') . '</number>'
            . '<barcodeText>' . $packetId . '</barcodeText></result></response>');
    }
    if (str_contains($body, '<packetLabelPdf>')) {
        $g['pk_label']++;
        return MockTransport::response(200, '<response><status>ok</status><result><packetLabelPdfResult>'
            . base64_encode('%PDF-1.4 T39 packeta label') . '</packetLabelPdfResult></result></response>');
    }
    if (str_contains($body, '<packetStatus>')) {
        $g['pk_status']++;
        return MockTransport::response(200, '<response><status>ok</status><result><statusCode>'
            . (int) $g['pk_status_code'] . '</statusCode></result></response>');
    }
    if (str_contains($body, '<packetInfo>')) {
        $g['pk_info']++;
        $packetId = t39_packeta_id($body);
        return MockTransport::response(200, '<response><status>ok</status><result><id>' . $packetId . '</id>'
            . '<barcode>' . $packetId . '</barcode><number>' . $val($body, 'number') . '</number>'
            . '<branchId></branchId></result></response>');
    }
    if (str_contains($body, '<createPacketClaimWithPassword>')) {
        $g['pk_claim']++;
        $number = $val($body, 'number');
        $claimId = 'T39CLAIM' . (9000 + $g['pk_claim']);
        $g['pk_claim_created'][] = $claimId;
        if ($g['pk_claim_timeout']) {
            // The provider DID create the claim; the response is lost (no read-back exists).
            return MockTransport::timeout();
        }
        return MockTransport::response(200, '<response><status>ok</status><result><id>' . $claimId . '</id>'
            . '<barcode>' . $number . '</barcode><barcodeText>' . $number . '</barcodeText>'
            . '<password>123456789</password></result></response>');
    }
    if (str_contains($body, '<cancelPacket>')) {
        $g['pk_cancel']++;
        $g['pk_cancel_applied'] = true;
        $g['pk_status_code'] = 11;
        if ($g['pk_cancel_timeout']) {
            return MockTransport::timeout();
        }
        return MockTransport::response(200, '<response><status>ok</status><result><cancelPacketResult>true</cancelPacketResult></result></response>');
    }
    return MockTransport::timeout();
};

function t39_packeta_id(string $body): string
{
    if (preg_match('/<packetId>([^<]*)<\/packetId>/', $body, $m)) {
        return trim($m[1]);
    }
    return '';
}

$mplRouter = static function ($url, $args) {
    $g = &$GLOBALS['t39'];
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    if ($method === 'POST' && str_contains((string) $url, 'token')) {
        return MockTransport::json(200, ['access_token' => 'T39-MPL-TOKEN', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }
    if ($method === 'DELETE') {
        $g['mpl_delete']++;
        $g['mpl_deleted'] = true;
        if ($g['mpl_delete_timeout']) {
            return MockTransport::timeout();
        }
        return MockTransport::json(200, ['status' => 'ok']);
    }
    if ($method === 'GET') {
        $g['mpl_get']++;
        if ($g['mpl_deleted']) {
            return MockTransport::json(404, ['error' => ['code' => '136', 'message' => 'GUID not found']]);
        }
        return MockTransport::json(200, ['trackingNumber' => 'T39MPL0001', 'shipmentId' => 'T39MPL0001']);
    }
    return MockTransport::timeout();
};

$foxRouter = static function ($url, $args) {
    $g = &$GLOBALS['t39'];
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    if ($method === 'DELETE') {
        $g['fox_delete']++;
        if ($g['fox_delete_timeout']) {
            $g['mpl_deleted'] = true; // provider applied the delete; response lost
            return MockTransport::timeout();
        }
        return MockTransport::response(204, '');
    }
    if ($method === 'GET' && str_contains((string) $url, '/tracking')) {
        $g['fox_tracks']++;
        return MockTransport::json(404, ['message' => 'parcel not found']);
    }
    return MockTransport::timeout();
};

$installMocks = static function () use ($packetaRouter, $mplRouter, $foxRouter): MockTransport {
    foreach ((array) ($GLOBALS['t39_mocks'] ?? []) as $old) {
        if ($old instanceof MockTransport) {
            $old->uninstall();
        }
    }
    $GLOBALS['t39_mocks'] = [];
    $mock = MockTransport::make()
        ->on('zasilkovna.cz', $packetaRouter)
        ->on('api.posta.hu', $mplRouter)
        ->on('webapi-test.foxpost.hu', $foxRouter);
    $mock->install();
    $GLOBALS['t39_mocks'][] = $mock;
    return $mock;
};

$delta = static function (array $before): array {
    $after = $GLOBALS['t39'];
    $diff = [];
    foreach (['pk_create', 'pk_label', 'pk_status', 'pk_claim', 'pk_cancel', 'mpl_delete', 'mpl_get', 'fox_delete', 'fox_tracks'] as $k) {
        $diff[$k] = (int) ($after[$k] ?? 0) - (int) ($before[$k] ?? 0);
    }
    return $diff;
};

$shipmentById = static fn(int $id): ?object => (new ShipmentRepository())->getById($id);

// ---------------------------------------------------------------- Case A: claim success + replay
$case = ['name' => 'packeta_claim_success_and_replay'];
try {
    $oid = 998201;
    $base = $t39();
    $createOrder($oid, 379000);
    $mock = $installMocks();
    $created = (new PacketaPacketService())->createForOrder($oid, 1);
    $shipment = (new ShipmentRepository())->listForOrder($oid)[0] ?? null;
    $case['label_created'] = in_array((string) ($created['status'] ?? ''), ['created', 'confirmed'], true)
        && $shipment !== null && (string) $shipment->state === ExternalOperation::EXTERNAL_CONFIRMED;

    $claim = (new PacketaClaimService())->request((int) $shipment->id);
    $case['claim_confirmed'] = ($claim['status'] ?? '') === 'confirmed';
    $order = 'FluentCart\App\Models\Order'::find($oid);
    $case['password_sealed'] = is_object($order)
        && trim((string) $order->getMeta('_fch_packeta_claim_password_ref')) !== ''
        && trim((string) $order->getMeta('_fch_packeta_claim_ref')) !== '';
    $case['one_claim_call'] = $delta($base)['pk_claim'] === 1;

    $replay = (new PacketaClaimService())->request((int) $shipment->id);
    $case['replay_no_call'] = ($replay['status'] ?? '') === 'already_confirmed' && $delta($base)['pk_claim'] === 1;
    $case['debug'] = ['created' => $created, 'claim' => $claim, 'replay' => $replay, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['label_created'], $case['claim_confirmed'], $case['password_sealed'], $case['one_claim_call'], $case['replay_no_call']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case B: claim local error -> resume
$case = ['name' => 'packeta_claim_local_error_and_resume'];
try {
    $oid = 998202;
    $base = $t39();
    $createOrder($oid, 379000);
    $mock = $installMocks();
    (new PacketaPacketService())->createForOrder($oid, 1);
    $shipment = (new ShipmentRepository())->listForOrder($oid)[0] ?? null;

    $creds = new CredentialStore();
    $creds->delete('packeta', 'test', 'default', 'api_password');
    $request = (new PacketaClaimService())->request((int) $shipment->id);
    $case['local_error'] = ($request['status'] ?? '') === 'local_error' && ($request['reason'] ?? '') === 'MISSING_CREDENTIALS';
    $case['no_claim_call'] = $delta($base)['pk_claim'] === 0;

    $creds->set('packeta', 'test', 'default', 'api_password', 'T39-PACKETA-API-PASSWORD');
    $resume = (new PacketaClaimService())->resume((int) $shipment->id);
    $case['resume_confirmed'] = ($resume['status'] ?? '') === 'confirmed' && $delta($base)['pk_claim'] === 1;
    $case['debug'] = ['request' => $request, 'resume' => $resume, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['local_error'], $case['no_claim_call'], $case['resume_confirmed']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case C: claim lost response -> manual resolution
$case = ['name' => 'packeta_claim_lost_response_manual'];
try {
    $oid = 998203;
    $base = $t39();
    $createOrder($oid, 379000);
    $mock = $installMocks();
    (new PacketaPacketService())->createForOrder($oid, 1);
    $shipment = (new ShipmentRepository())->listForOrder($oid)[0] ?? null;

    $GLOBALS['t39']['pk_claim_timeout'] = true; // the provider creates it; the response is lost
    $claimsBefore = count($GLOBALS['t39']['pk_claim_created']);
    $request = (new PacketaClaimService())->request((int) $shipment->id);
    $case['uncertain'] = ($request['status'] ?? '') === 'uncertain';
    $case['caller_created_by_provider'] = count($GLOBALS['t39']['pk_claim_created']) === $claimsBefore + 1;
    $case['one_call_only'] = $delta($base)['pk_claim'] === 1;

    // No documented read-back: the reconcile pass cannot confirm it and must NOT re-post.
    $opId = (int) ($request['operation_id'] ?? 0);
    $state = (new PacketaClaimService())->reconcile((object) []); // direct call shape check
    $case['reconcile_is_uncertain'] = $state->status === \FluentCartHungary\Core\Ledger\ProviderCallResult::UNCERTAIN
        && $state->code === 'CLAIM_NO_READBACK_MANUAL_REQUIRED';
    try {
        (new \FluentCartHungary\Core\Jobs\ReconcileUncertainJob())->handle(['operation_id' => $opId]);
    } catch (\Throwable $e) {
        // The job reports the still-uncertain outcome by throwing; the ledger must stay AWAITING.
    }
    $ledgerRow = (new LedgerRepository())->getById($opId);
    $case['still_uncertain'] = $ledgerRow !== null && (string) $ledgerRow->state === ExternalOperation::AWAITING_RECONCILIATION;
    $case['still_one_call'] = $delta($base)['pk_claim'] === 1;

    $resolved = (new \FluentCartHungary\Core\Ledger\OperationRunner())->resolveManually($opId, 'T39: szolgáltatói felületen ellenőrizve');
    $case['manual_resolved'] = $resolved && (string) (new LedgerRepository())->getById($opId)->state === ExternalOperation::MANUALLY_RESOLVED;
    $case['debug'] = ['request' => $request, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['uncertain'], $case['caller_created_by_provider'], $case['one_call_only'], $case['reconcile_is_uncertain'], $case['still_uncertain'], $case['still_one_call'], $case['manual_resolved']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case D: cancellation lost response -> read-back + parallel dedup
$case = ['name' => 'packeta_cancel_lost_response_readback'];
try {
    $oid = 998201; // reuses the confirmed packeta shipment of case A
    $shipment = (new ShipmentRepository())->listForOrder($oid)[0] ?? null;
    $base = $t39();
    $mock = $installMocks();
    $labelBefore = trim((string) ($shipment->label_ref ?? ''));

    $GLOBALS['t39']['pk_cancel_timeout'] = true; // provider applied the cancel; response lost
    $request = (new ShipmentCancellationService())->request((int) $shipment->id);
    $case['uncertain'] = ($request['status'] ?? '') === 'uncertain';
    $case['one_cancel_call'] = $delta($base)['pk_cancel'] === 1;

    // Parallel/duplicate request: replay only, no second provider call.
    $second = (new ShipmentCancellationService())->request((int) $shipment->id);
    $case['parallel_replay'] = in_array((string) ($second['status'] ?? ''), ['uncertain', 'in_flight'], true)
        && $delta($base)['pk_cancel'] === 1;

    $opRow = (new LedgerRepository())->getByKey(sprintf('fch:%d:packeta:shipment_cancel:1', $oid));
    $opId = (int) ($opRow->id ?? 0);
    $state = (new ShipmentCancellationService())->reconcileNow($opId);
    $fresh = $shipmentById((int) $shipment->id);
    $case['readback_confirmed'] = $state === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $fresh->state === ExternalOperation::SUPERSEDED
        && trim((string) ($fresh->cancelled_at ?? '')) !== '';
    $case['no_second_cancel'] = $delta($base)['pk_cancel'] === 1 && $delta($base)['pk_status'] >= 1;
    $label = $labelBefore !== '' ? (new CarrierLabelStore('packeta'))->read($labelBefore) : null;
    $case['label_file_kept'] = $label !== null && str_contains((string) $label, '%PDF');
    $case['debug'] = ['request' => $request, 'second' => $second, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['uncertain'], $case['one_cancel_call'], $case['parallel_replay'], $case['readback_confirmed'], $case['no_second_cancel'], $case['label_file_kept']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case E: local cancel of a never-dispatched intent
$case = ['name' => 'cancel_local_never_dispatched'];
try {
    $oid = 998204;
    $base = $t39();
    $createOrder($oid, 379000);
    $repo = new ShipmentRepository();
    $key = sprintf('fch:%d:packeta:label_create:1', $oid);
    $row = $repo->getOrCreate([
        'order_id' => $oid, 'carrier' => 'packeta', 'mode' => 'test', 'account_id' => 'default',
        'shipment_no' => 1, 'idempotency_key' => $key, 'currency' => 'HUF', 'cod_amount_minor' => 0,
    ])['row'];
    $ledger = new LedgerRepository();
    $op = $ledger->claim([
        'idempotency_key' => $key, 'module' => 'shipping', 'provider' => 'packeta', 'kind' => 'label_create',
        'mode' => 'test', 'account_id' => 'default', 'order_id' => $oid, 'owner_type' => 'shipment', 'owner_id' => (int) $row->id,
    ]);
    $repo->attachOperation((int) $row->id, (int) $op['row']->id);
    $mock = $installMocks();

    $request = (new ShipmentCancellationService())->request((int) $row->id);
    $fresh = $shipmentById((int) $row->id);
    $case['cancelled_local'] = ($request['status'] ?? '') === 'cancelled_local' && (string) $fresh->state === ExternalOperation::SUPERSEDED;
    $case['zero_provider_calls'] = $delta($base)['pk_create'] === 0 && $delta($base)['pk_cancel'] === 0;
    $createOp = $ledger->getById((int) $op['row']->id);
    $case['create_intent_superseded'] = $createOp !== null && (string) $createOp->state === ExternalOperation::SUPERSEDED;

    // Late create request (same identity) must not create a packet silently.
    $late = (new PacketaPacketService())->createForOrder($oid, 1);
    $case['late_create_not_retryable'] = ($late['status'] ?? '') === 'not_retryable' && $delta($base)['pk_create'] === 0;
    $case['debug'] = ['request' => $request, 'late' => $late, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['cancelled_local'], $case['zero_provider_calls'], $case['create_intent_superseded'], $case['late_create_not_retryable']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case F: MPL read-back + Foxpost direct proof + GLS block
$case = ['name' => 'mpl_foxpost_cancel_and_gls_block'];
try {
    $base = $t39();
    $createOrder(998205, 379000);
    $createOrder(998206, 379000);
    $createOrder(998207, 379000);
    $mplId = $seedConfirmedShipment(998205, 'mpl', 'T39MPL0001', 'T39MPL0001');
    $foxId = $seedConfirmedShipment(998206, 'foxpost', 'CLFOX-T39-1', 'CLFOX-T39-1');
    $glsId = $seedConfirmedShipment(998207, 'gls', 'T39GLS1', 'T39GLS1');
    $mock = $installMocks();

    $GLOBALS['t39']['mpl_delete_timeout'] = true; // applied at the provider; response lost
    $mpl = (new ShipmentCancellationService())->request($mplId);
    $case['mpl_uncertain'] = ($mpl['status'] ?? '') === 'uncertain';
    $mplOp = (new LedgerRepository())->getByKey('fch:998205:mpl:shipment_cancel:1');
    $mplState = (new ShipmentCancellationService())->reconcileNow((int) ($mplOp->id ?? 0));
    $case['mpl_readback_confirmed'] = $mplState === ExternalOperation::EXTERNAL_CONFIRMED
        && (string) $shipmentById($mplId)->state === ExternalOperation::SUPERSEDED;
    $case['mpl_one_delete'] = $delta($base)['mpl_delete'] === 1;

    $fox = (new ShipmentCancellationService())->request($foxId);
    $case['fox_confirmed'] = ($fox['status'] ?? '') === 'cancelled_externally'
        && (string) $shipmentById($foxId)->state === ExternalOperation::SUPERSEDED
        && $delta($base)['fox_delete'] === 1;

    $gls = (new ShipmentCancellationService())->request($glsId);
    $case['gls_blocked'] = ($gls['status'] ?? '') === 'blocked' && ($gls['reason'] ?? '') === 'cancellation_not_supported';
    $case['gls_intact'] = (string) $shipmentById($glsId)->state === ExternalOperation::EXTERNAL_CONFIRMED;
    $case['debug'] = ['mpl' => $mpl, 'fox' => $fox, 'gls' => $gls, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['mpl_uncertain'], $case['mpl_readback_confirmed'], $case['mpl_one_delete'], $case['fox_confirmed'], $case['gls_blocked'], $case['gls_intact']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- Case G: FC order cancel alone + late tracking/label after cancel
$case = ['name' => 'fc_cancel_alone_and_late_events'];
try {
    $oid = 998206; // foxpost shipment already cancelled in case F
    $base = $t39();
    $mock = $installMocks();

    // Merchant cancels the FC order: nothing external may happen.
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fct_orders SET status='canceled' WHERE id=%d", $oid));
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fch_jobs SET available_at = UTC_TIMESTAMP() WHERE state = 'PENDING' AND job_key LIKE %s", '%' . $oid . '%'));
    for ($i = 0; $i < 3; $i++) {
        if (\FluentCartHungary\Core\Jobs\JobRunner::instance()->runDue(50) === 0) {
            break;
        }
    }
    $case['no_external_call_on_fc_cancel'] = $delta($base)['fox_delete'] === 0 && $delta($base)['fox_tracks'] === 0;
    $case['shipment_stays_superseded'] = (string) (new ShipmentRepository())->listForOrder($oid)[0]->state === ExternalOperation::SUPERSEDED;

    // Late label request (same identity) and a late tracking sync must not create or resurrect anything.
    $late = (new \FluentCartHungary\Modules\Shipping\Foxpost\FoxpostParcelService())->createForOrder($oid, 1);
    $case['late_label_not_retryable'] = ($late['status'] ?? '') === 'not_retryable';
    (new \FluentCartHungary\Modules\Shipping\Foxpost\SyncTrackingJob())->handle(['order_id' => $oid]);
    $case['no_tracking_calls'] = $delta($base)['fox_tracks'] === 0;
    $case['shipment_never_reactivated'] = (string) (new ShipmentRepository())->listForOrder($oid)[0]->state === ExternalOperation::SUPERSEDED;
    $case['debug'] = ['late' => $late, 'delta' => $delta($base)];
    $mock->uninstall();
    $case['ok'] = !in_array(false, [$case['no_external_call_on_fc_cancel'], $case['shipment_stays_superseded'], $case['late_label_not_retryable'], $case['no_tracking_calls'], $case['shipment_never_reactivated']], true);
} catch (\Throwable $e) {
    $case['ok'] = false;
    $case['exception'] = $e->getMessage();
}
$out['cases'][] = $case;
if (!$case['ok']) {
    $out['errors'][] = $case['name'];
}

// ---------------------------------------------------------------- summary
$seamsOk = $out['checks']['cancel_dispatchers']['packeta'] && $out['checks']['cancel_dispatchers']['mpl']
    && $out['checks']['cancel_dispatchers']['foxpost'] && $out['checks']['cancel_dispatchers']['dpd']
    && $out['checks']['cancel_dispatchers']['gls'] === false;
$out['ok'] = $seamsOk && !in_array(false, $out['checks']['modules_booted'], true) && $out['errors'] === [];
echo wp_json_encode($out) . "\n";
