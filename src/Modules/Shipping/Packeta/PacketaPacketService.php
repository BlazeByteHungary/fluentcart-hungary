<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;

/**
 * Orchestrates Packeta packet creation, label storage and reprint for one FluentCart order.
 *
 * Claim + exactly-once local effects (05_DATA_SCHEMA §5.4/§7.4):
 *  - `ShipmentRepository::getOrCreate()` with carrier `packeta` and idempotency key
 *    `fch:{order}:packeta:label_create:{n}` (provider-namespaced so MPL/Packeta rows never collide);
 *  - `OperationRunner::run()` with `kind=label_create`, `max_attempts=1` — `createPacket` has NO
 *    idempotency key and no lookup-by-number, so auto create-retry is NOT safe (04_PACKETA_CONTRACT §7);
 *    a timeout becomes `UNCERTAIN` + a reconcile job (packetInfo read-back), never a re-POST;
 *  - `number` = the order reference for operator reconciliation (04 §5.1); it is NOT an idempotency key.
 *
 * FluentCart reads go only through verified APIs: `Order::find()`, `$order->order_items`,
 * `$order->shipping_address`/`billing_address`, and `getMeta()/updateMeta()` for `_fch_pickup_point`.
 * The apiPassword and raw bodies never reach a ProviderCallResult payload, a log, or an exception.
 * Amounts are integer domain minor units (HUF = whole forint); FluentCart ×100 conversion is applied
 * exactly once at this boundary via {@see FluentCartMoney}.
 */
final class PacketaPacketService
{
    public const PROVIDER = PacketaModule::PROVIDER;
    public const MODULE = PacketaModule::MODULE;
    public const CARRIER = PacketaModule::CARRIER;
    public const MAX_ATTEMPTS = 1; // 04_PACKETA §7: createPacket is not retry-safe

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
     * Create (or confirm) one Packeta packet for an order. Idempotent through the shipment + ledger
     * unique keys: a duplicate run performs no external call.
     *
     * @return array{status:string,state:string,order_id:int,shipment_id:?int,packet_id:?string,barcode:?string,tracking_number:?string,label_ref:?string,reason?:string,errors?:list<string>}
     */
    public function createForOrder(int $orderId, int $sequence = 1): array
    {
        $order = PacketaModule::loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, null, 'order_not_found');
        }

        $guard = CarrierBinding::guardForCarrier($order, self::CARRIER);
        if (!$guard['allow']) {
            $reason = $guard['reason'] === 'not_applicable' ? 'carrier_not_applicable' : $guard['reason'];
            do_action('fch/shipping/label_skipped', $orderId, $reason);
            return $this->summary('skipped', ExternalOperation::NOT_STARTED, $orderId, null, null, null, null, $reason);
        }
        if (!PacketaModule::hasCredentials()) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, null, 'missing_credentials');
        }

        $snapshot = $this->buildSnapshot($order, $sequence);
        if ($snapshot === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, null, 'snapshot_failed');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(PacketaModule::SETTINGS_SCOPE);
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
            'cod_amount_minor' => (int) $snapshot['cod_minor'],
            'currency' => (string) $snapshot['currency'],
        ]);
        $row = $shipment['row'];
        $shipmentId = (int) $row->id;
        $state = (string) $row->state;

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary('already_created', $state, $orderId, $shipmentId, $this->nullableString($row->external_shipment_id ?? null), $this->nullableString($row->barcode ?? null), $this->nullableString($row->tracking_number ?? null), null, [], $this->nullableString($row->label_ref ?? null));
        }
        if (in_array($state, [ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED], true)) {
            return $this->summary('not_retryable', $state, $orderId, $shipmentId, null, null, null, 'shipment_' . strtolower($state));
        }

        $built = PacketaMapper::build($snapshot);
        if (!$built['ok']) {
            // Local validation failure BEFORE dispatch: the ledger is not touched; the shipment stays
            // NOT_STARTED so a corrected payload/re-run is possible (LOCAL_ERROR, not an external state).
            return $this->summary('validation_error', ExternalOperation::NOT_STARTED, $orderId, $shipmentId, null, null, null, 'label_validation_failed', $built['errors']);
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
            'business_reference' => (string) $snapshot['number'],
            'currency' => (string) $snapshot['currency'],
            'amount_minor' => FluentCartMoney::fromCartCents(
                (int) ($order->total_amount ?? 0),
                (string) $snapshot['currency']
            ),
            'max_attempts' => self::MAX_ATTEMPTS,
        ], function (object $ledgerRow) use ($client, $built): ProviderCallResult {
            $response = $client->createPacket($built['payload']);
            return $this->interpretCreateResponse($response, $built['payload']);
        });

        $state = (string) $run['state'];
        $fields = $run['result']?->fields ?? [];

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            $fields['operation_id'] = (int) $run['row']->id;
            $label = $this->storeLabel($client, (string) ($fields['packet_id'] ?? ''));
            if ($label !== null) {
                $fields['label_ref'] = $label['ref'];
                $fields['label_sha256'] = $label['sha256'];
            }
            $this->finalizeConfirmed($shipmentId, $fields);
            // The mirror has no barcode_text column; keep the operator-friendly printed form on order meta.
            if (!empty($fields['barcode_text']) && method_exists($order, 'updateMeta')) {
                $order->updateMeta('_fch_packeta_barcode_text', (string) $fields['barcode_text']);
            }
            $fresh = $this->shipments->getById($shipmentId);
            return $this->summary(
                'created',
                $state,
                $orderId,
                $shipmentId,
                $this->nullableString(($fresh->external_shipment_id ?? null) ?? ($fields['packet_id'] ?? null)),
                $this->nullableString(($fresh->barcode ?? null) ?? ($fields['barcode'] ?? null)),
                $this->nullableString(($fresh->tracking_number ?? null) ?? ($fields['tracking_number'] ?? null)),
                null,
                [],
                $this->nullableString(($fresh->label_ref ?? null) ?? ($fields['label_ref'] ?? null))
            );
        }

        if ($state === ExternalOperation::PROVEN_REJECTED) {
            self::ensureShipmentInProgress($this->shipments, $shipmentId);
            $this->shipments->markRejected($shipmentId, ['local_error_code' => (string) ($run['result']?->code ?? 'REJECTED')]);
            return $this->summary('rejected', $state, $orderId, $shipmentId, null, null, null, (string) ($run['result']?->code ?? 'REJECTED'));
        }

        if ($state === ExternalOperation::UNCERTAIN) {
            self::ensureShipmentInProgress($this->shipments, $shipmentId);
            $this->shipments->markUncertain($shipmentId);
            return $this->summary('uncertain', $state, $orderId, $shipmentId, null, null, null, (string) ($run['result']?->code ?? 'TIMEOUT_AFTER_DISPATCH'));
        }

        return $this->summary('local_error', $state, $orderId, $shipmentId, null, null, null, (string) ($run['result']?->code ?? 'LOCAL_ERROR'));
    }

    /**
     * Read-only label reprint (invariant §1.6): returns the STORED ref only. Never inserts a row, never
     * changes shipment state or label_state, and never creates/fetches a new provider label.
     *
     * @return array{shipment_id:int,source:string,label_ref:string,label:string}|null
     */
    public function reprintLabel(int $shipmentId): ?array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return null;
        }

        $labelRef = trim((string) ($row->label_ref ?? ''));
        if ($labelRef === '') {
            return null;
        }
        try {
            $stored = (new CarrierLabelStore(self::CARRIER))->read($labelRef);
        } catch (\Throwable $e) {
            return null;
        }
        if ($stored === null || $stored === '') {
            return null;
        }

        return ['shipment_id' => $shipmentId, 'source' => 'stored', 'label_ref' => $labelRef, 'label' => $stored];
    }

    /**
     * PKT-005 `cancelPacket` — ledger-bound dispatcher (W4 logistics lifecycle). The container/carrier &
     * mode/account binding is taken from the ORIGINAL shipment row via the ledger row; a timeout is
     * UNCERTAIN and resolves through `packetStatus` read-back, never a second cancelPacket.
     *
     * @param object $ledgerRow claim ledger row (kind shipment_cancel)
     */
    public function dispatchCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::localError('MISSING_SHIPMENT_ROW', 'A szállítmánysor nem található.');
        }
        $packetId = PacketaClient::canonicalPacketId((string) ($row->external_shipment_id ?? $row->barcode ?? ''));
        if ($packetId === '') {
            return ProviderCallResult::localError('NO_PACKET_ID', 'Nincs visszavonható csomagazonosító a szállítmánysoron.');
        }
        $password = trim((string) (new \FluentCartHungary\Core\Settings\CredentialStore())->get(
            self::PROVIDER,
            (string) $ledgerRow->mode,
            (string) $ledgerRow->account_id,
            'api_password'
        ));
        if ($password === '') {
            return ProviderCallResult::localError('MISSING_CREDENTIALS', 'A visszavonáshoz az eredeti Packeta fiók (mód/fiók) apiPassword kulcsa nem érhető el.');
        }

        $client = new PacketaClient($password);
        $request = ['operation' => 'cancelPacket', 'packetId' => $packetId];
        $response = $client->cancelPacket($packetId);

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('PACKETA_HTTP_' . $response->status, $request);
        }

        $decoded = PacketaClient::decode($response->body);
        if ($response->isSuccess() && !empty($decoded['ok'])) {
            return self::cancelSuccess($request, $packetId, 'cancelled');
        }

        // Explicit refusal or already-cancelled signal. `CancelNotAllowedFault` / `PacketIdFault` mean the
        // packet is not cancelable in this state (04 §7) — confirmed only by the packetStatus read-back.
        $fault = (string) ($decoded['fault'] ?? '');
        $alreadySignal = stripos($fault, 'CancelNotAllowed') !== false || stripos($fault, 'PacketId') !== false
            || stripos($fault, 'already') !== false;
        $statusCode = $this->readBackStatusCode($client, $packetId);
        if ($statusCode === 11) {
            return self::cancelSuccess($request, $packetId, 'already_cancelled');
        }
        if ($alreadySignal && $statusCode === null) {
            // The provider refuses to cancel and we cannot prove the state: a human must look at it.
            return ProviderCallResult::uncertain('CANCEL_UNPROVEN', $request, ['fault' => $fault]);
        }
        if ($response->isSuccess()) {
            return ProviderCallResult::uncertain('CANCEL_UNPROVEN', $request, ['fault' => $fault]);
        }
        return ProviderCallResult::rejected(
            'PACKETA_CANCEL_REJECTED',
            $fault !== '' ? $fault : 'HTTP ' . $response->status,
            $request,
            ['fault' => $fault]
        );
    }

    /**
     * Read-back for an UNCERTAIN cancellation: only the documented `packetStatus` code `11` proves it.
     * Any other/absent code keeps the row UNCERTAIN — a negative read-back is never a rejection.
     *
     * @param object $ledgerRow shipment_cancel ledger row
     */
    public function reconcileCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::uncertain('MISSING_SHIPMENT_ROW');
        }
        $packetId = PacketaClient::canonicalPacketId((string) ($row->external_shipment_id ?? $row->barcode ?? ''));
        if ($packetId === '') {
            return ProviderCallResult::uncertain('NO_PACKET_ID');
        }
        $password = trim((string) (new \FluentCartHungary\Core\Settings\CredentialStore())->get(
            self::PROVIDER,
            (string) $ledgerRow->mode,
            (string) $ledgerRow->account_id,
            'api_password'
        ));
        if ($password === '') {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }
        $client = new PacketaClient($password);
        $request = ['operation' => 'packetStatus', 'packetId' => $packetId];
        $statusCode = $this->readBackStatusCode($client, $packetId);
        if ($statusCode === 11) {
            return self::cancelSuccess($request, $packetId, 'cancelled');
        }
        return ProviderCallResult::uncertain(
            'QUERY_NOT_CANCELLED_NOT_FINAL',
            $request,
            ['statusCode' => $statusCode]
        );
    }

    /** @return array<string,mixed> */
    private static function cancelSuccess(array $request, string $packetId, string $state): ProviderCallResult
    {
        return ProviderCallResult::success([
            'external_id' => $packetId,
            'external_ref2' => $packetId,
            'barcode' => $packetId,
            'provider_doc_type' => 'cancellation',
            'cancel_state' => $state,
        ], $request, ['packetId' => $packetId, 'state' => $state]);
    }

    /** Documented status code 11 = cancelled (04 §6); null when the provider cannot answer. */
    private function readBackStatusCode(PacketaClient $client, string $packetId): ?int
    {
        $response = $client->packetStatus($packetId);
        if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
            return null;
        }
        $decoded = PacketaClient::decode($response->body);
        if (empty($decoded['ok']) || !is_array($decoded['result'] ?? null)) {
            return null;
        }
        $code = PacketaClient::firstString($decoded['result'], 'statusCode');
        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * PKT-005 `cancelPacket` (legacy direct helper): delegates to the shared, ledger-routed cancellation
     * service so the admin path and this call share one lifecycle and one identity.
     *
     * @return array{ok:bool,status:int,state:?string,error?:string,reason?:string}
     */
    public function cancelShipment(int $shipmentId): array
    {
        $result = (new \FluentCartHungary\Core\Shipping\ShipmentCancellationService())->request($shipmentId);
        $status = (string) ($result['status'] ?? '');
        $ok = in_array($status, ['cancelled_externally', 'cancelled_local', 'already_cancelled'], true);
        return [
            'ok' => $ok,
            'status' => $ok ? 200 : 0,
            'state' => isset($result['state']) ? (string) $result['state'] : null,
            'error' => $ok ? null : (string) ($result['reason'] ?? $status),
        ];
    }

    // ---- snapshot -----------------------------------------------------------------------------------

    /**
     * Build the immutable snapshot the pure mapper consumes. Amounts are integer domain minor units taken
     * from FC columns via {@see FluentCartMoney}; no float money arithmetic is performed.
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

            $billing = $order->billing_address ?? null;
            $currency = strtoupper((string) ($order->currency ?? 'HUF'));
            $country = strtoupper(trim((string) ($address->country ?? 'HU')));
            if ($country === '') {
                $country = 'HU';
            }

            $phone = trim((string) ($address->phone ?? ''));
            if ($phone === '' && is_object($billing)) {
                $phone = trim((string) ($billing->phone ?? ''));
            }
            $email = trim((string) ($address->email ?? ''));
            if ($email === '' && is_object($billing)) {
                $email = trim((string) ($billing->email ?? ''));
            }

            [$name, $surname] = PacketaMapper::splitName((string) ($address->name ?? ''));

            $pickup = $this->pickupPoint($order);
            $deliveryMode = self::deliveryModeFromPickup($pickup);

            $pointId = trim((string) ($pickup['point_id'] ?? ''));
            $addressId = $deliveryMode === PacketaMapper::DELIVERY_PUDO && $pointId !== ''
                ? $pointId
                : trim((string) PacketaModule::setting('hd_address_id', ''));
            if ($addressId === '' && $deliveryMode === PacketaMapper::DELIVERY_HD) {
                // Legacy HS-041 default per destination; the operator setting always wins.
                $addressId = PacketaMapper::defaultHdAddressId($country);
            }

            $codMinor = $this->codAmount($order);
            $totalMinor = FluentCartMoney::fromCartCents((int) ($order->total_amount ?? 0), $currency);
            $valueMinor = max(0, $totalMinor);
            if ($valueMinor <= 0) {
                $valueMinor = max(0, (int) PacketaModule::setting('default_value_minor', 0));
            }

            $customs = $this->customsPayload($address, $order, $currency, $valueMinor);

            return [
                'order_id' => (int) ($order->id ?? 0),
                'idempotency_key' => sprintf('fch:%d:packeta:label_create:%d', (int) ($order->id ?? 0), $sequence),
                'number' => $this->orderReference($order),
                'name' => $name,
                'surname' => $surname,
                'company' => trim((string) ($address->company ?? '')),
                'email' => $email,
                'phone' => $phone,
                'country' => $country,
                'address_id' => $addressId,
                'currency' => $currency,
                'cod_minor' => $codMinor,
                'value_minor' => $valueMinor,
                'weight_kg' => PacketaMapper::gramsToKgString($this->totalWeightG($order)),
                'deliver_on' => trim((string) PacketaModule::setting('deliver_on', '')),
                'today' => gmdate('Y-m-d'),
                'eshop' => (string) PacketaModule::setting('eshop', ''),
                'adult_content' => (bool) PacketaModule::setting('adult_content', false),
                'note' => (string) PacketaModule::setting('note', ''),
                'delivery_mode' => $deliveryMode,
                'street' => trim((string) ($address->address_1 ?? '')),
                'house_number' => trim((string) ($address->address_2 ?? '')),
                'city' => trim((string) ($address->city ?? '')),
                'zip' => trim((string) ($address->postcode ?? '')),
                'province' => trim((string) ($address->state ?? '')),
                'carrier_service' => trim((string) PacketaModule::setting('carrier_service', '')),
                'carrier_pickup_point' => $deliveryMode === PacketaMapper::DELIVERY_PUDO ? $pointId : '',
                'attributes' => $customs['attributes'],
                'items' => $customs['items'],
                'security' => (array) PacketaModule::setting('security', []),
                'services' => (array) PacketaModule::setting('services', []),
                'affiliate_id' => trim((string) PacketaModule::setting('affiliate_id', '')),
                'cod_min_minor' => PacketaModule::setting('cod_min_minor'),
                'cod_max_minor' => PacketaModule::setting('cod_max_minor'),
                'value_max_minor' => PacketaModule::setting('value_max_minor'),
            ];
        } catch (\Throwable $e) {
            do_action('fch/packeta/snapshot_failed', (int) ($order->id ?? 0), $e);
            return null;
        }
    }

    // ---- provider I/O -------------------------------------------------------------------------------

    /**
     * Interpret the PKT-001 response (`PacketIdDetail{id,barcode,barcodeText}`; 04 §4). A lost response
     * after dispatch is UNCERTAIN and is never auto-retried (04 §7).
     *
     * @param array<string,mixed> $payload the dispatched PacketAttributes (secret-free)
     */
    public function interpretCreateResponse(Response $response, array $payload): ProviderCallResult
    {
        $request = ['number' => (string) ($payload['number'] ?? ''), 'addressId' => (string) ($payload['addressId'] ?? '')];

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('PACKETA_HTTP_' . $response->status, $request);
        }

        $decoded = PacketaClient::decode($response->body);
        if ($response->status >= 400 || !$decoded['ok']) {
            $fault = (string) ($decoded['fault'] ?? 'PACKETA_FAULT');
            // An unparseable/empty 200 body after dispatch is an unknown outcome, not a refusal.
            if (in_array($fault, ['xml_unavailable', 'malformed_xml', 'empty_response'], true)) {
                return ProviderCallResult::uncertain('PACKETA_UNPARSEABLE_RESPONSE', $request, ['fault' => $fault]);
            }
            if (stripos($fault, 'IncorrectApiPassword') !== false || stripos($fault, 'ArgumentsFault') !== false) {
                // Definitive local/auth misconfiguration: not a business rejection, no packet was created.
                return ProviderCallResult::localError('PACKETA_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $fault) ?? 'FAULT'), $fault, $request);
            }
            return ProviderCallResult::rejected('PACKETA_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $fault) ?? 'FAULT'), $fault, $request);
        }

        $result = $decoded['result'];
        $id = PacketaClient::firstString($result, 'id');
        $barcode = PacketaClient::firstString($result, 'barcode');
        $barcodeText = PacketaClient::firstString($result, 'barcodeText');
        $tracking = $barcode !== '' ? $barcode : $id;

        if ($id === '' && $barcode === '') {
            return ProviderCallResult::uncertain('PACKETA_NO_PACKET_ID', $request, ['status' => $response->status]);
        }

        $fields = [
            'packet_id' => $id !== '' ? $id : $barcode,
            'external_id' => $id !== '' ? $id : $barcode,
            'external_ref2' => $barcode,
            'barcode' => $barcode !== '' ? $barcode : $id,
            'barcode_text' => $barcodeText,
            'tracking_number' => $tracking,
            'carrier_reference' => (string) ($payload['number'] ?? ''),
        ];

        return ProviderCallResult::success($fields, $request, ['packetId' => (string) $fields['packet_id'], 'barcode' => (string) $fields['barcode']]);
    }

    /** Fetch + store the label PDF after a proved create (best-effort; never downgrades the packet). */
    private function storeLabel(PacketaClient $client, string $packetId): ?array
    {
        $packetId = PacketaClient::canonicalPacketId($packetId);
        if ($packetId === '') {
            return null;
        }
        $format = (string) PacketaModule::setting('label_format', 'A6 on A6');
        $response = $client->packetLabelPdf($packetId, $format, 0);
        if ($response->error !== null || $response->status === 0 || !$response->isSuccess()) {
            return null;
        }
        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return null;
        }
        $base64 = PacketaClient::firstString($decoded['result'], 'packetLabelPdfResult', 'label', 'value');
        if ($base64 === '') {
            return null;
        }
        $binary = base64_decode(trim($base64), true);
        if (!is_string($binary) || $binary === '') {
            return null;
        }
        try {
            return (new CarrierLabelStore(self::CARRIER))->store('packeta-label-' . $packetId, $binary, 'pdf');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Persist a proved create outcome onto the shipment mirror (05_DATA_SCHEMA §7.4). @param array<string,mixed> $fields */
    private function finalizeConfirmed(int $shipmentId, array $fields): void
    {
        self::ensureShipmentInProgress($this->shipments, $shipmentId);

        $extra = [
            'operation_id' => (int) ($fields['operation_id'] ?? 0),
            'external_shipment_id' => (string) ($fields['packet_id'] ?? $fields['external_id'] ?? ''),
            'external_ref2' => (string) ($fields['barcode'] ?? $fields['external_ref2'] ?? ''),
            'barcode' => (string) ($fields['barcode'] ?? ''),
            'tracking_number' => (string) ($fields['tracking_number'] ?? ''),
            'carrier_reference' => (string) ($fields['carrier_reference'] ?? ''),
            'label_ref' => $fields['label_ref'] ?? null,
            'label_sha256' => $fields['label_sha256'] ?? null,
        ];

        $this->shipments->markConfirmed($shipmentId, $extra);
        if (!empty($extra['label_ref'])) {
            $this->shipments->setLabelState($shipmentId, 'PREPARED');
        }
    }

    /** Bridge NOT_STARTED -> IN_PROGRESS before a mirror terminal transition (mirror machine §7.4). */
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

    // ---- snapshot helpers ---------------------------------------------------------------------------

    /** COD amount in domain minor units (HUF = forint) for a FluentCart COD order. */
    private function codAmount(object $order): int
    {
        if ((string) ($order->payment_method ?? '') !== PacketaModule::COD_METHOD) {
            return 0;
        }
        return max(0, FluentCartMoney::fromCartCents(
            (int) ($order->total_amount ?? 0),
            (string) ($order->currency ?? 'HUF')
        ));
    }

    /** @return list<array{order_item_id:int,qty:int}> */
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

    /** Total shipment weight in grams from order items (`other_info.weight`). */
    private function totalWeightG(object $order): int
    {
        $total = 0;
        $defaultUnit = strtolower((string) PacketaModule::setting('weight_unit', 'kg'));
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
            $total = max(0, (int) PacketaModule::setting('default_weight_g', 1000));
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
            'raw' => $raw,
        ];
    }

    /** PUDO when a point type is documented (PP / Z-BOX / external carrier point); otherwise HD. */
    public static function deliveryModeFromPickup(array $pickup): string
    {
        $type = strtoupper(trim((string) ($pickup['type'] ?? '')));
        if (in_array($type, ['PP', 'Z-BOX', 'ZBOX', 'Z_BOX', 'EXTERNAL'], true)) {
            return PacketaMapper::DELIVERY_PUDO;
        }
        return PacketaMapper::DELIVERY_HD;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function pickupSnapshotForRow(array $snapshot): ?array
    {
        if ((string) ($snapshot['delivery_mode'] ?? '') !== PacketaMapper::DELIVERY_PUDO) {
            return null;
        }
        return [
            'addressId' => (string) ($snapshot['address_id'] ?? ''),
            'type' => PacketaMapper::DELIVERY_PUDO,
        ];
    }

    /**
     * Customs payload for non-EU home delivery. Returns packet-level attributes and item list from the
     * order's mapping (settings `customs_attributes` / `customs_items`) after local validation.
     *
     * @return array{attributes:list<array{key:string,value:string}>,items:list<array{attributes:list<array{key:string,value:string}>}>}
     */
    private function customsPayload(object $address, object $order, string $currency, int $valueMinor): array
    {
        $country = strtoupper(trim((string) ($address->country ?? 'HU')));
        if (PacketaMapper::isEu($country)) {
            return ['attributes' => [], 'items' => []];
        }

        $attrSetting = PacketaModule::setting('customs_attributes', []);
        $itemSetting = PacketaModule::setting('customs_items', []);

        $attributes = PacketaCustoms::buildPacketAttributes(array_merge(
            [
                'currency' => $currency,
                'delivery_cost_minor' => (int) PacketaModule::setting('customs_delivery_cost_minor', 0),
                'invoice_number' => (string) $this->orderReference($order),
                'invoice_issue_date' => gmdate('Y-m-d'),
            ],
            is_array($attrSetting) ? $attrSetting : []
        ));

        $lines = [];
        foreach (is_array($itemSetting) ? $itemSetting : [] as $line) {
            if (is_array($line)) {
                $line['currency'] = $line['currency'] ?? $currency;
                $lines[] = $line;
            }
        }
        $items = PacketaCustoms::buildItems($lines);

        return ['attributes' => $attributes, 'items' => $items];
    }

    /** Operator-facing order reference for `PacketAttributes.number` (04 §5.1). */
    private function orderReference(object $order): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) PacketaModule::setting('number_prefix', '')) ?? '';
        $reference = $prefix . (string) (int) ($order->id ?? 0);
        return substr($reference, 0, 36);
    }

    /**
     * @param array<string,mixed> $snapshot @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function requestSummary(array $snapshot, array $payload): array
    {
        return [
            'number' => (string) ($payload['number'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'surname' => (string) ($payload['surname'] ?? ''),
            'addressId' => (string) ($payload['addressId'] ?? ''),
            'currency' => (string) ($payload['currency'] ?? ''),
            'cod' => (string) ($payload['cod'] ?? ''),
            'value' => (string) ($payload['value'] ?? ''),
            'weight' => (string) ($payload['weight'] ?? ''),
            'delivery_mode' => (string) ($snapshot['delivery_mode'] ?? ''),
            'item_count' => count((array) ($payload['items'] ?? [])),
        ];
    }

    /**
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

    // ---- misc ---------------------------------------------------------------------------------------

    private function client(): PacketaClient
    {
        return new PacketaClient();
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

    /**
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function summary(
        string $status,
        string $state,
        int $orderId,
        ?int $shipmentId,
        ?string $packetId,
        ?string $barcode,
        ?string $tracking,
        ?string $reason = null,
        array $errors = [],
        ?string $labelRef = null
    ): array {
        $out = [
            'status' => $status,
            'state' => $state,
            'order_id' => $orderId,
            'shipment_id' => $shipmentId,
            'packet_id' => $packetId,
            'barcode' => $barcode,
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
