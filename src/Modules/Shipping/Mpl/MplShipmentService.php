<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Orchestrates MPL shipment creation, reprint and manifest close for one FluentCart order.
 *
 * FluentCart reads go ONLY through verified APIs (no raw SQL, no core patching):
 *  - `FluentCart\App\Models\Order::find($id)` (class at app/Models/Order.php:35; `find()` inherited from the
 *    framework Model, same pattern as SzamlazzInvoiceService.php:205-211);
 *  - `$order->order_items` (Order.php:210), `$order->shipping_address` (Order.php:344),
 *    `$order->billing_address` (Order.php:349);
 *  - `$order->getMeta()/updateMeta()` for the pickup snapshot (Order.php:489/502; constraints §2).
 *
 * Claim + exactly-once local effects:
 *  - `ShipmentRepository::getOrCreate()` with carrier `mpl` and `idempotency_key fch:{order}:label_create:{n}`
 *    (05_DATA_SCHEMA §5.4);
 *  - `OperationRunner::run()` with `kind=label_create`, `max_attempts=1` — MPL create has no idempotency key
 *    and auto create-retry is NOT safe (04_MPL §7 verdict); a timeout becomes `UNCERTAIN` + a reconciliation
 *    job, never a re-POST;
 *  - `customData1` = the idempotency key (04_MPL §7 reconciliation handle).
 *
 * The API key/secret and the raw request/response bodies never reach a ProviderCallResult payload, a log, or
 * an exception. Amounts are integer HUF (exponent 0), never floats.
 */
final class MplShipmentService
{
    public const PROVIDER = MplModule::PROVIDER;
    public const MODULE = MplModule::MODULE;
    public const CARRIER = MplModule::CARRIER;
    public const MAX_ATTEMPTS = 1; // 04_MPL §7: create is not retry-safe

    private ShipmentRepository $shipments;

    private OperationRunner $runner;

    private ModuleSettings $settings;

    public function __construct(
        ?ShipmentRepository $shipments = null,
        ?OperationRunner $runner = null,
        ?ModuleSettings $settings = null
    ) {
        $this->shipments = $shipments ?? new ShipmentRepository();
        $this->runner = $runner ?? new OperationRunner();
        $this->settings = $settings ?? new ModuleSettings();
    }

    /**
     * Create (or confirm) one MPL shipment for an order. Idempotent through the shipment + ledger unique
     * keys: a duplicate run performs no external call.
     *
     * @return array{status:string,state:string,order_id:int,shipment_id:?int,tracking_number:?string,label_ref:?string,reason?:string,errors?:list<string>}
     */
    public function createForOrder(int $orderId, int $sequence = 1): array
    {
        $order = MplModule::loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'order_not_found');
        }

        $guard = CarrierBinding::guardForCarrier($order, self::CARRIER);
        if (!$guard['allow']) {
            $reason = $guard['reason'] === 'not_applicable' ? 'carrier_not_applicable' : $guard['reason'];
            do_action('fch/shipping/label_skipped', $orderId, $reason);
            return $this->summary('skipped', ExternalOperation::NOT_STARTED, $orderId, null, null, null, $reason);
        }
        if (!MplModule::hasCredentials()) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'missing_credentials');
        }

        $snapshot = $this->buildSnapshot($order, $sequence);
        if ($snapshot === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'snapshot_failed');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(MplModule::SETTINGS_SCOPE);
        $idempotencyKey = (string) $snapshot['idempotency_key'];

        $shipment = $this->shipments->getOrCreate([
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'carrier' => self::CARRIER,
            'mode' => $mode,
            'account_id' => $account,
            'shipment_no' => $sequence,
            'idempotency_key' => $idempotencyKey,
            'items' => $this->jsonEncode($this->coveredItems($order)),
            'pickup_point' => $this->jsonEncode($this->pickupSnapshotForRow($snapshot)),
            'cod_amount_minor' => $this->codAmount($order),
            'currency' => (string) ($snapshot['currency'] ?? ''),
        ]);
        $row = $shipment['row'];
        $shipmentId = (int) $row->id;
        $state = (string) $row->state;

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary('already_created', $state, $orderId, $shipmentId, $this->nullableString($row->tracking_number ?? null), $this->nullableString($row->label_ref ?? null));
        }
        if (in_array($state, [ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED], true)) {
            return $this->summary('not_retryable', $state, $orderId, $shipmentId, null, null, 'shipment_' . strtolower($state));
        }

        $built = MplShipmentMapper::build($snapshot);
        if (!$built['ok']) {
            // Local validation failure BEFORE dispatch: the ledger is not touched; the shipment stays
            // NOT_STARTED so a corrected payload/re-run is possible (LOCAL_ERROR, not an external state).
            return $this->summary('validation_error', ExternalOperation::NOT_STARTED, $orderId, $shipmentId, null, null, 'label_validation_failed', $built['errors']);
        }

        $client = $this->client();
        $request = $this->requestSummary($snapshot, $built['payload']);

        $run = $this->runner->run([
            'idempotency_key' => $idempotencyKey,
            'module' => self::MODULE,
            'provider' => self::PROVIDER,
            'kind' => 'label_create',
            'mode' => $mode,
            'account_id' => $account,
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'owner_type' => 'shipment',
            'owner_id' => $shipmentId,
            'business_reference' => (string) ($snapshot['webshop_id'] ?? ''),
            'currency' => (string) ($snapshot['currency'] ?? ''),
            // FC stores amount×100 (even HUF) → convert to our ISO minor units at the boundary.
            'amount_minor' => FluentCartMoney::fromCartCents(
                (int) ($order->total_amount ?? 0),
                (string) ($snapshot['currency'] ?? 'HUF')
            ),
            'max_attempts' => self::MAX_ATTEMPTS,
        ], function (object $ledgerRow) use ($client, $built, $snapshot, $request): ProviderCallResult {
            $response = $client->createShipments([$built['payload']]);
            return $this->interpretCreateResponse($response, $snapshot, $request);
        });

        $state = (string) $run['state'];
        $fields = $run['result']?->fields ?? [];

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            $fields['operation_id'] = (int) $run['row']->id;
            $this->finalizeConfirmed($shipmentId, $fields);
            $fresh = $this->shipments->getById($shipmentId);
            return $this->summary(
                'created',
                $state,
                $orderId,
                $shipmentId,
                $this->nullableString(($fresh->tracking_number ?? null) ?? ($fields['tracking_number'] ?? null)),
                $this->nullableString(($fresh->label_ref ?? null) ?? ($fields['label_ref'] ?? null))
            );
        }

        if ($state === ExternalOperation::PROVEN_REJECTED) {
            self::ensureShipmentInProgress($this->shipments, $shipmentId);
            $this->shipments->markRejected($shipmentId, ['local_error_code' => (string) ($run['result']?->code ?? 'REJECTED')]);
            return $this->summary('rejected', $state, $orderId, $shipmentId, null, null, (string) ($run['result']?->code ?? 'REJECTED'));
        }

        if ($state === ExternalOperation::UNCERTAIN) {
            self::ensureShipmentInProgress($this->shipments, $shipmentId);
            $this->shipments->markUncertain($shipmentId);
            return $this->summary('uncertain', $state, $orderId, $shipmentId, null, null, (string) ($run['result']?->code ?? 'TIMEOUT_AFTER_DISPATCH'));
        }

        // LOCAL_ERROR: the ledger row stays NOT_STARTED; the shipment mirror also stays NOT_STARTED.
        return $this->summary('local_error', $state, $orderId, $shipmentId, null, null, (string) ($run['result']?->code ?? 'LOCAL_ERROR'));
    }

    /**
     * Read-only label reprint (invariant §1.6). Never inserts a row and never changes shipment state or
     * label_state. Prefers the stored artifact; otherwise fetches the same label by tracking number
     * (MPL-009 `GET /shipments/label`, 04_MPL §4) without persisting it.
     *
     * @return array{shipment_id:int,source:string,label_ref:?string,label:string}|null
     */
    public function reprintLabel(int $shipmentId): ?array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return null;
        }

        $labelRef = trim((string) ($row->label_ref ?? ''));
        if ($labelRef !== '') {
            try {
                $stored = (new MplLabelStore())->read($labelRef);
                if ($stored !== null) {
                    return ['shipment_id' => $shipmentId, 'source' => 'stored', 'label_ref' => $labelRef, 'label' => $stored];
                }
            } catch (\Throwable $e) {
                // fall through to provider read-back
            }
        }

        $tracking = trim((string) ($row->tracking_number ?? ''));
        if ($tracking === '') {
            return null;
        }

        $client = $this->client();
        $response = $client->getLabel(
            [$tracking],
            (string) MplModule::setting('label_type', 'A4'),
            (string) MplModule::setting('label_format', 'PDF')
        );
        if ($response->error !== null || !$response->isSuccess()) {
            return null;
        }
        $binary = self::extractLabelBinary($this->firstLabelBase64($response->json()));
        if ($binary === null) {
            return null;
        }

        return ['shipment_id' => $shipmentId, 'source' => 'provider', 'label_ref' => null, 'label' => $binary];
    }

    /**
     * Close submitted parcels and generate the manifest (MPL-012 `POST /shipments/close`, 04_MPL §4/§3.8).
     * A deterministic `requestId` is reused across retries so the provider replays the previous close
     * response after a timeout (04_MPL §7 close row; error 607).
     *
     * @param list<int> $shipmentIds
     * @return array{ok:bool,request_id:string,status:int,manifest_ref:?string,error?:string}
     */
    public function closeManifest(array $shipmentIds, ?string $requestId = null): array
    {
        $trackingNumbers = [];
        foreach ($shipmentIds as $shipmentId) {
            $row = $this->shipments->getById((int) $shipmentId);
            if ($row === null || (string) ($row->carrier ?? '') !== self::CARRIER) {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $tracking = trim((string) ($row->tracking_number ?? ''));
            if ($tracking !== '') {
                $trackingNumbers[$tracking] = true;
            }
        }
        if ($trackingNumbers === []) {
            return ['ok' => false, 'request_id' => '', 'status' => 0, 'manifest_ref' => null, 'error' => 'no_confirmed_shipments'];
        }

        if ($requestId === null || trim($requestId) === '') {
            $seed = implode(',', array_map('intval', array_values($shipmentIds))) . '|' . gmdate('Y-m-d');
            $requestId = 'fch-mpl-close-' . substr(hash('sha256', $seed), 0, 32);
        }

        $client = $this->client();
        if (!$client->hasCredentials()) {
            return ['ok' => false, 'request_id' => $requestId, 'status' => 0, 'manifest_ref' => null, 'error' => 'missing_credentials'];
        }

        $response = $client->closeShipments([
            'trackingNumbers' => array_keys($trackingNumbers),
            'requestId' => $requestId,
            'checkList' => true,
            'singleFile' => true,
        ]);
        if ($response->error !== null || $response->status === 0) {
            // Recoverable: replay with the same requestId once the transport is back (04_MPL §7).
            return ['ok' => false, 'request_id' => $requestId, 'status' => 0, 'manifest_ref' => null, 'error' => 'timeout'];
        }

        $manifestRef = null;
        $manifestBase64 = $this->findManifestBase64($response->json());
        if ($manifestBase64 !== null) {
            $binary = self::extractLabelBinary($manifestBase64);
            if ($binary !== null) {
                try {
                    $stored = (new MplLabelStore())->store('mpl-manifest-' . $requestId, $binary, 'pdf');
                    $manifestRef = $stored['ref'];
                } catch (\Throwable $e) {
                    $manifestRef = null;
                }
            }
        }

        return [
            'ok' => $response->isSuccess(),
            'request_id' => $requestId,
            'status' => $response->status,
            'manifest_ref' => $manifestRef,
        ];
    }

    /**
     * Build the immutable snapshot the pure mapper consumes. Amounts are integer HUF minor units taken
     * from order columns; no float money arithmetic is performed.
     *
     * @return array<string,mixed>|null
     */
    public function buildSnapshot(object $order, int $sequence): ?array
    {
        try {
            $address = $order->shipping_address ?? null;
            if (!is_object($address)) {
                $address = $order->billing_address ?? null;
            }
            if (!is_object($address)) {
                return null;
            }

            $country = strtoupper(trim((string) ($address->country ?? 'HU')));
            if ($country === '') {
                $country = 'HU';
            }

            $billing = $order->billing_address ?? null;
            $phone = trim((string) ($address->phone ?? ''));
            if ($phone === '' && is_object($billing)) {
                $phone = trim((string) ($billing->phone ?? ''));
            }

            $pickup = $this->pickupPoint($order);
            $deliveryMode = strtoupper((string) ($pickup['type'] ?? ''));
            if (!in_array($deliveryMode, MplShipmentMapper::DELIVERY_MODES, true)) {
                $deliveryMode = strtoupper((string) MplModule::setting('delivery_mode', 'HA'));
            }

            $webshopId = trim((string) MplModule::setting('webshop_id', ''));
            if ($webshopId === '') {
                $webshopId = 'FCH-' . (int) ($order->id ?? 0);
            }

            $cod = $this->codAmount($order);
            $paymentMode = null;
            if ($cod > 0) {
                $paymentMode = strtoupper((string) MplModule::setting('cod_payment_mode', 'UV_KP'));
            }

            $weight = $this->totalWeightG($order);
            $size = strtoupper(trim((string) MplModule::setting('parcel_size', '')));
            $customs = MplModule::setting('customs', []);

            return [
                'order_id' => (int) ($order->id ?? 0),
                'idempotency_key' => ExternalOperation::idempotencyKey((int) ($order->id ?? 0), 'label_create', $sequence),
                'webshop_id' => $webshopId,
                'mode' => $this->settings->mode(),
                'developer' => (string) MplModule::setting('developer', ''),
                'base_service' => (string) MplModule::setting('base_service', ''),
                'sender' => [
                    'agreement' => (string) MplModule::setting('agreement', ''),
                    'account_no' => (string) MplModule::setting('account_no', ''),
                ],
                'recipient' => [
                    'name' => (string) ($address->name ?? ''),
                    'phone' => $phone,
                    'email' => (string) ($address->email ?? ''),
                    'country' => $country,
                    'address' => [
                        'postCode' => (string) ($address->postcode ?? ''),
                        'city' => (string) ($address->city ?? ''),
                        'address' => trim((string) ($address->address_1 ?? '') . ' ' . (string) ($address->address_2 ?? '')),
                    ],
                    'parcel_pickup_site' => isset($pickup['point_id']) && (string) $pickup['point_id'] !== '' ? (string) $pickup['point_id'] : null,
                ],
                'delivery_mode' => $deliveryMode,
                'package_retention' => (int) MplModule::setting('package_retention', 5),
                'payment_mode' => $paymentMode,
                'label_type' => (string) MplModule::setting('label_type', 'A4'),
                'label_format' => (string) MplModule::setting('label_format', 'PDF'),
                'group_together' => (bool) MplModule::setting('group_together', false),
                'currency' => strtoupper((string) ($order->currency ?? 'HUF')),
                'parcels' => [[
                    'weight_g' => $weight,
                    'size' => $size !== '' ? $size : null,
                    'cod_minor' => $cod,
                    'value_minor' => max(0, (int) MplModule::setting('declared_value_huf', 0)),
                    'extras' => (array) MplModule::setting('extras', []),
                    'customs' => is_array($customs) ? array_values($customs) : [],
                    'dims_mm' => null,
                ]],
            ];
        } catch (\Throwable $e) {
            // Support/diagnostics hook: a snapshot failure otherwise degrades to "snapshot_failed" silently.
            do_action('fch/mpl/snapshot_failed', (int) ($order->id ?? 0), $e);
            return null;
        }
    }

    // ---- provider I/O -------------------------------------------------------------------------------

    /**
     * Interpret the MPL-006 create response (§4; `ShipmentCreateResult` with webshopId, trackingNumber,
     * packageTrackingNumbers, dispatchId, label, errors/warnings). A response that echoes our webshopId is
     * accepted as our shipment (idempotent echo); a lost response after dispatch is UNCERTAIN and is never
     * auto-retried (04_MPL §7).
     *
     * @param array<string,mixed> $request secret-free request summary
     */
    private function interpretCreateResponse(Response $response, array $snapshot, array $request): ProviderCallResult
    {
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('MPL_HTTP_' . $response->status, $request);
        }

        $data = $response->json();
        if ($data === []) {
            return ProviderCallResult::uncertain('MPL_EMPTY_RESPONSE', $request, ['status' => $response->status]);
        }

        $results = self::asResultList($data);
        $webshopId = (string) ($snapshot['webshop_id'] ?? '');
        $idempotencyKey = (string) ($snapshot['idempotency_key'] ?? '');
        $candidate = self::matchResult($results, [$webshopId, $idempotencyKey]);

        if ($candidate === null) {
            return ProviderCallResult::rejected('MPL_RESPONSE_MISMATCH', 'No ShipmentCreateResult echoed webshopId.', $request);
        }

        $errors = self::extractErrors($candidate);
        if ($errors !== []) {
            // Definitive business rejection (error codes 2..232, 04_MPL §6.2); never retry as create.
            $first = $errors[0];
            return ProviderCallResult::rejected((string) $first['code'], (string) $first['message'], $request, ['webshopId' => $webshopId]);
        }

        $tracking = trim((string) ($candidate['trackingNumber'] ?? ''));
        $packageTracking = self::stringList($candidate['packageTrackingNumbers'] ?? []);
        if ($tracking === '' && $packageTracking !== []) {
            $tracking = $packageTracking[0];
        }
        if ($tracking === '') {
            // Echoed our webshopId but no parcel identity: cannot be proved as created.
            return ProviderCallResult::uncertain('MPL_NO_TRACKING_NUMBER', $request, ['webshopId' => $webshopId]);
        }

        $fields = [
            'external_id' => $tracking,
            'external_ref2' => $webshopId,
            'external_ref3' => $idempotencyKey,
            'barcode' => $packageTracking !== [] ? $packageTracking[0] : $tracking,
            'tracking_number' => $tracking,
            'carrier_reference' => $webshopId,
            'parcels' => $packageTracking,
            'webshop_id' => $webshopId,
            'custom_data1' => $idempotencyKey,
            'package_count' => max(1, count($packageTracking)),
        ];

        $binary = self::extractLabelBinary((string) ($candidate['label'] ?? ''));
        if ($binary !== null && $binary !== '') {
            $extension = strtoupper((string) ($snapshot['label_format'] ?? 'PDF')) === 'ZPL' ? 'zpl' : 'pdf';
            try {
                $stored = (new MplLabelStore())->store('mpl-label-' . $tracking, $binary, $extension);
                $fields['label_ref'] = $stored['ref'];
                $fields['label_sha256'] = $stored['sha256'];
            } catch (\Throwable $e) {
                // Label storage is best-effort; the proved shipment must not be downgraded by it.
            }
        }

        return ProviderCallResult::success($fields, $request, ['webshopId' => $webshopId, 'trackingNumber' => $tracking]);
    }

    /** Persist a proved create outcome onto the shipment mirror (05_DATA_SCHEMA §7.4). @param array<string,mixed> $fields */
    private function finalizeConfirmed(int $shipmentId, array $fields): void
    {
        self::ensureShipmentInProgress($this->shipments, $shipmentId);

        $extra = [
            'operation_id' => (int) ($fields['operation_id'] ?? 0),
            'external_shipment_id' => (string) ($fields['tracking_number'] ?? ''),
            'external_ref2' => (string) ($fields['external_ref2'] ?? ''),
            'external_ref3' => (string) ($fields['external_ref3'] ?? ''),
            'barcode' => (string) ($fields['barcode'] ?? ''),
            'tracking_number' => (string) ($fields['tracking_number'] ?? ''),
            'carrier_reference' => (string) ($fields['carrier_reference'] ?? ''),
            'parcels' => $this->jsonEncode((array) ($fields['parcels'] ?? [])),
            'package_count' => (int) ($fields['package_count'] ?? 1),
            'label_ref' => $fields['label_ref'] ?? null,
            'label_sha256' => $fields['label_sha256'] ?? null,
        ];

        $this->shipments->markConfirmed($shipmentId, $extra);

        if (!empty($extra['label_ref'])) {
            // 05_DATA_SCHEMA §7.4: a stored label is PREPARED (not yet printed).
            $this->shipments->setLabelState($shipmentId, 'PREPARED');
        }
    }

    /** Bridge NOT_STARTED -> IN_PROGRESS before a mirror terminal transition (mirror state machine §7.4). */
    public static function ensureShipmentInProgress(ShipmentRepository $repo, int $shipmentId): void
    {
        $row = $repo->getById($shipmentId);
        if ($row === null) {
            return;
        }
        $state = (string) $row->state;
        if (in_array($state, [
            ExternalOperation::IN_PROGRESS,
            ExternalOperation::EXTERNAL_CONFIRMED,
            ExternalOperation::PROVEN_REJECTED,
            ExternalOperation::SUPERSEDED,
            ExternalOperation::MANUALLY_RESOLVED,
            ExternalOperation::UNCERTAIN,
            ExternalOperation::AWAITING_RECONCILIATION,
        ], true)) {
            return;
        }
        if ($state !== ExternalOperation::NOT_STARTED) {
            return;
        }
        $method = new \ReflectionMethod(ShipmentRepository::class, 'transitionRow');
        $method->setAccessible(true);
        $method->invoke($repo, $shipmentId, ExternalOperation::IN_PROGRESS, [], ExternalOperation::NOT_STARTED, []);
    }

    /**
     * MPL-011 `DELETE /v2/mplapi/shipments/{trackingNumber}` — ledger-bound cancellation dispatcher
     * (W4 logistics lifecycle). Documented "already deleted/closed" business errors (201/202) prove an
     * idempotent no-op success; a timeout resolves through `GET /shipments/{trackingNumber}` read-back
     * (absent => deleted), never a second delete.
     *
     * @param object $ledgerRow shipment_cancel ledger row
     */
    public function dispatchCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::localError('MISSING_SHIPMENT_ROW', 'A szállítmánysor nem található.');
        }
        $tracking = trim((string) ($row->external_shipment_id ?? $row->tracking_number ?? $row->barcode ?? ''));
        if ($tracking === '') {
            return ProviderCallResult::localError('NO_TRACKING_NUMBER', 'Nincs visszavonható MPL csomagazonosító.');
        }
        $client = $this->boundClient($ledgerRow);
        if ($client === null) {
            return ProviderCallResult::localError('MISSING_CREDENTIALS', 'A visszavonáshoz az eredeti MPL fiók (mód/fiók) kulcsa nem érhető el.');
        }

        $request = ['operation' => 'deleteShipment', 'trackingNumber' => $tracking];
        $response = $client->deleteShipment($tracking);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('MPL_HTTP_' . $response->status, $request);
        }

        $decoded = json_decode((string) $response->body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $errorCode = (string) ($decoded['error']['code'] ?? $decoded['code'] ?? '');
        if (in_array($errorCode, ['201', '202'], true)) {
            return self::cancelSuccess($request, $tracking, 'already_deleted');
        }

        if ($response->isSuccess()) {
            if ($errorCode !== '') {
                return ProviderCallResult::uncertain('MPL_DELETE_UNPROVEN', $request, ['code' => $errorCode]);
            }
            return self::cancelSuccess($request, $tracking, 'cancelled');
        }

        // 4xx: documented delete errors 201/202 were handled; prove the actual state before rejecting.
        $exists = $this->readBackExists($client, $tracking);
        if ($exists === false) {
            return self::cancelSuccess($request, $tracking, 'already_deleted');
        }
        if ($exists === true) {
            return ProviderCallResult::rejected('MPL_DELETE_REJECTED', (string) ($decoded['message'] ?? 'HTTP ' . $response->status), $request);
        }
        return ProviderCallResult::uncertain('MPL_DELETE_UNPROVEN', $request, ['http' => $response->status]);
    }

    /** Read-back for an UNCERTAIN cancellation: absent shipment proves the delete; present keeps UNCERTAIN. */
    public function reconcileCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::uncertain('MISSING_SHIPMENT_ROW');
        }
        $tracking = trim((string) ($row->external_shipment_id ?? $row->tracking_number ?? $row->barcode ?? ''));
        if ($tracking === '') {
            return ProviderCallResult::uncertain('NO_TRACKING_NUMBER');
        }
        $client = $this->boundClient($ledgerRow);
        if ($client === null) {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }
        $request = ['operation' => 'getShipment', 'trackingNumber' => $tracking];
        $exists = $this->readBackExists($client, $tracking);
        if ($exists === false) {
            return self::cancelSuccess($request, $tracking, 'cancelled');
        }
        if ($exists === true) {
            return ProviderCallResult::uncertain('QUERY_STILL_EXISTS_NOT_FINAL', $request);
        }
        return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request);
    }

    /** @return array<string,mixed> */
    private static function cancelSuccess(array $request, string $tracking, string $state): ProviderCallResult
    {
        return ProviderCallResult::success([
            'external_id' => $tracking,
            'external_ref2' => $tracking,
            'tracking_number' => $tracking,
            'provider_doc_type' => 'cancellation',
            'cancel_state' => $state,
        ], $request, ['trackingNumber' => $tracking, 'state' => $state]);
    }

    /** true = still exists, false = proved absent (deleted), null = cannot tell. */
    private function readBackExists(MplClient $client, string $tracking): ?bool
    {
        $response = $client->getShipment($tracking);
        if ($response->error !== null || $response->status === 0 || $response->status >= 500) {
            return null;
        }
        if ($response->status === 404) {
            return false;
        }
        if (!$response->isSuccess()) {
            return null;
        }
        $decoded = json_decode((string) $response->body, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        $errorCode = (string) ($decoded['error']['code'] ?? '');
        if (in_array($errorCode, ['201', '202', '136', '137'], true)) {
            return false;
        }
        return true;
    }

    /** Credentials of the ORIGINAL shipment binding (mode/account from the ledger row). */
    private function boundClient(object $ledgerRow): ?MplClient
    {
        $mode = (string) $ledgerRow->mode;
        $account = (string) $ledgerRow->account_id;
        $credentials = new \FluentCartHungary\Core\Settings\CredentialStore();
        $key = (string) ($credentials->get(self::PROVIDER, $mode, $account, 'api_key') ?? '');
        $secret = (string) ($credentials->get(self::PROVIDER, $mode, $account, 'api_secret') ?? '');
        if (trim($key) === '' || trim($secret) === '') {
            return null;
        }
        return new MplClient($mode, $key, $secret, (string) $this->settings->get(self::PROVIDER, 'accounting_code', ''));
    }

    private function client(): MplClient
    {
        return new MplClient($this->settings->mode());
    }

    // ---- snapshot helpers ---------------------------------------------------------------------------

    /**
     * COD amount in our ISO minor units (HUF = forint) for a FluentCart COD order
     * (payment_method `offline_payment`, Cod.php:40/91). FluentCart stores amount×100; MPL
     * `services.cod` is a HUF integer (04_MPL §3.6) — the boundary conversion is mandatory here.
     */
    private function codAmount(object $order): int
    {
        if ((string) ($order->payment_method ?? '') !== MplModule::COD_METHOD) {
            return 0;
        }
        return max(0, FluentCartMoney::fromCartCents(
            (int) ($order->total_amount ?? 0),
            (string) ($order->currency ?? 'HUF')
        ));
    }

    /** @return list<array{order_item_id:int,qty:int}> covered order items (partial fulfillment §7.4). */
    private function coveredItems(object $order): array
    {
        $covered = [];
        foreach ($this->toList($order->order_items ?? []) as $item) {
            $item = is_object($item) ? $item : (array) $item;
            $id = (int) (is_object($item) ? ($item->id ?? 0) : ($item['id'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $quantity = (int) (is_object($item) ? ($item->quantity ?? 0) : ($item['quantity'] ?? 0));
            $fulfilled = (int) (is_object($item) ? ($item->fulfilled_quantity ?? 0) : ($item['fulfilled_quantity'] ?? 0));
            $remaining = $quantity - $fulfilled;
            $covered[] = ['order_item_id' => $id, 'qty' => $remaining > 0 ? $remaining : max(0, $quantity)];
        }
        return $covered;
    }

    /** @return int total shipment weight in grams from order items (`other_info.weight`), 0 handled by settings. */
    private function totalWeightG(object $order): int
    {
        $total = 0;
        $defaultUnit = strtolower((string) MplModule::setting('weight_unit', 'kg'));
        foreach ($this->toList($order->order_items ?? []) as $item) {
            $info = is_object($item) ? ($item->other_info ?? null) : ($item['other_info'] ?? null);
            if (is_string($info) && $info !== '') {
                $info = json_decode($info, true);
            }
            if (!is_array($info)) {
                continue;
            }
            $weight = (float) ($info['weight'] ?? 0);
            if ($weight <= 0) {
                continue;
            }
            $unit = strtolower((string) ($info['weight_unit'] ?? $defaultUnit));
            $grams = $unit === 'g' ? $weight : $weight * 1000;
            $quantity = is_object($item) ? (int) ($item->quantity ?? 1) : (int) ($item['quantity'] ?? 1);
            $total += (int) round($grams * max(1, $quantity));
        }
        if ($total <= 0) {
            $total = max(0, (int) MplModule::setting('default_weight_g', 1000));
        }
        return $total;
    }

    /** Pickup-point snapshot from order meta `_fch_pickup_point` (constraints §2). @return array<string,mixed> */
    private function pickupPoint(object $order): array
    {
        if (!method_exists($order, 'getMeta')) {
            return [];
        }
        $raw = $order->getMeta('_fch_pickup_point', []);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $carrier = (string) ($raw['carrier'] ?? '');
        if ($carrier !== '' && strtolower($carrier) !== self::CARRIER) {
            return [];
        }
        return [
            'carrier' => $carrier,
            'type' => strtoupper((string) ($raw['type'] ?? '')),
            'point_id' => (string) ($raw['point_id'] ?? $raw['id'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'address' => (string) ($raw['address'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function pickupSnapshotForRow(array $snapshot): ?array
    {
        $recipient = (array) ($snapshot['recipient'] ?? []);
        $site = trim((string) ($recipient['parcel_pickup_site'] ?? ''));
        if ($site === '') {
            return null;
        }
        return ['parcelPickupSite' => $site, 'deliveryMode' => (string) ($snapshot['delivery_mode'] ?? '')];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $payload @return array<string,mixed> */
    private function requestSummary(array $snapshot, array $payload): array
    {
        $items = (array) ($payload['item'] ?? []);
        $cod = 0;
        $value = 0;
        foreach ($items as $item) {
            $services = (array) ($item['services'] ?? []);
            $cod += (int) ($services['cod'] ?? 0);
            $value += (int) ($services['value'] ?? 0);
        }
        return [
            'webshopId' => (string) ($snapshot['webshop_id'] ?? ''),
            'deliveryMode' => (string) ($payload['deliveryMode'] ?? ''),
            'labelType' => (string) ($payload['labelType'] ?? ''),
            'labelFormat' => (string) ($payload['labelFormat'] ?? ''),
            'item_count' => count($items),
            'cod_huf' => $cod,
            'value_huf' => $value,
        ];
    }

    /**
     * Normalize a FluentCart relation/collection/array into a plain list. FluentCart's Collection::get()
     * is a KEY ACCESSOR (requires an argument) unlike a Laravel-style no-arg getter — calling it blind
     * fatals, so toArray()/iteration is used instead (same contract as SzamlazzInvoiceService::toList).
     *
     * @return list<mixed>
     */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $items = $value->toArray();
            return is_array($items) ? array_values($items) : [];
        }
        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value));
        }
        return [];
    }

    // ---- response helpers ---------------------------------------------------------------------------

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    private static function asResultList(array $data): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['shipments', 'results', 'items'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return [$data];
    }

    /**
     * @param list<array<string,mixed>> $results
     * @param list<string>             $references webshopId / idempotency key / customData1 candidates
     * @return array<string,mixed>|null
     */
    private static function matchResult(array $results, array $references): ?array
    {
        $references = array_values(array_filter(array_map('strval', $references), static fn(string $v): bool => $v !== ''));
        if ($references !== []) {
            foreach ($results as $result) {
                foreach (['webshopId', 'customData1', 'customData2', 'orderId'] as $key) {
                    $value = trim((string) ($result[$key] ?? ''));
                    if ($value !== '' && in_array($value, $references, true)) {
                        return $result;
                    }
                }
            }
        }
        // Single-result responses are unambiguous even when the provider omits the echo.
        return count($results) === 1 ? $results[0] : null;
    }

    /** @param array<string,mixed> $result @return list<array{code:string,message:string}> */
    private static function extractErrors(array $result): array
    {
        $raw = $result['errors'] ?? [];
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $errors = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $code = (string) ($entry['code'] ?? $entry['errorCode'] ?? '');
                $message = (string) ($entry['message'] ?? $entry['errorMessage'] ?? '');
            } else {
                $code = (string) $entry;
                $message = '';
            }
            if ($code !== '' || $message !== '') {
                $errors[] = ['code' => $code !== '' ? $code : 'MPL_ERROR', 'message' => $message];
            }
        }
        return $errors;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_scalar($entry) && (string) $entry !== '') {
                $out[] = (string) $entry;
            }
        }
        return $out;
    }

    /** @param array<string,mixed>|array<int,mixed> $data @return string|null base64 label */
    private function firstLabelBase64(array $data): ?string
    {
        $results = self::asResultList($data);
        foreach ($results as $result) {
            $label = (string) ($result['label'] ?? '');
            if ($label !== '') {
                return $label;
            }
        }
        return null;
    }

    /** @param array<string,mixed>|array<int,mixed> $data @return string|null base64 manifest */
    private function findManifestBase64(array $data): ?string
    {
        $results = self::asResultList($data);
        foreach ($results as $result) {
            foreach (['manifest', 'manifestPDF', 'pdf'] as $key) {
                $label = (string) ($result[$key] ?? '');
                if ($label !== '') {
                    return $label;
                }
            }
        }
        return null;
    }

    private static function extractLabelBinary(string $base64): ?string
    {
        $base64 = trim($base64);
        if ($base64 === '') {
            return null;
        }
        $binary = base64_decode($base64, true);
        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    private function jsonEncode(mixed $value): string
    {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($encoded) ? $encoded : '[]';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $value === null ? '' : (string) $value;
        return $value === '' ? null : $value;
    }

    /** @param list<string> $errors */
    private function summary(string $status, string $state, int $orderId, ?int $shipmentId, ?string $tracking, ?string $labelRef, ?string $reason = null, array $errors = []): array
    {
        $out = [
            'status' => $status,
            'state' => $state,
            'order_id' => $orderId,
            'shipment_id' => $shipmentId,
            'tracking_number' => $tracking,
            'label_ref' => $labelRef,
        ];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        if ($errors !== []) {
            $out['errors'] = $errors;
        }
        return $out;
    }
}
