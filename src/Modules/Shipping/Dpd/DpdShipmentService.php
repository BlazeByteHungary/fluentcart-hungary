<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;
use FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits;

/**
 * Orchestrates DPD shipment creation and read-only reprint for one FluentCart order.
 *
 * FluentCart reads go ONLY through verified APIs (no raw SQL, no core patching): `Order::find()`,
 * `$order->order_items`, `$order->shipping_address`/`billing_address`, `$order->getMeta()`.
 *
 * Claim + local effects (W2_EXECUTION_PLAN §2):
 *  - `ShipmentRepository::getOrCreate()` with carrier `dpd` and idempotency key
 *    `fch:{order}:dpd:label_create:{n}`;
 *  - `OperationRunner::run()` with `kind=label_create`, `max_attempts=1` — DPD-001 has **no idempotency
 *    key** and auto create-retry is NOT safe (04_DPD §7 verdict); a timeout becomes `UNCERTAIN` +
 *    (manual) reconciliation, never a re-POST;
 *  - the local idempotency key is also sent as `reference1` on the shipment for manual reconciliation
 *    (there is no documented query-by-reference endpoint, DPD-906/DPD-U8).
 *
 * COD is enforced through {@see CodValueLimits::check('dpd', …)} with operator settings
 * `cod_min_minor`/`cod_max_minor`: DPD COD maxima are BU-configured (ACCOUNT_DEPENDENT, 04_DPD §3.6 /
 * DPD-UNK-11 / DPD-U11) so no numeric value is invented here.
 *
 * The bearer JWT and the raw request/response bodies never reach a ProviderCallResult payload, a log, or
 * an exception. Amounts are domain integer minor units (HUF = forint), never floats.
 */
final class DpdShipmentService
{
    public const PROVIDER = DpdModule::PROVIDER;
    public const MODULE = DpdModule::MODULE;
    public const CARRIER = DpdModule::CARRIER;
    public const MAX_ATTEMPTS = 1; // 04_DPD §7: create is not retry-safe

    public const DELIVERY_MODES = ['HD', 'PUDO'];

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

    /** Canonical DPD idempotency key (W2_EXECUTION_PLAN §2: fch:{order}:{carrier}:label_create:{n}). */
    public static function idempotencyKey(int $orderId, int $sequence = 1): string
    {
        return sprintf('fch:%d:dpd:label_create:%d', $orderId, max(1, $sequence));
    }

    /**
     * Create (or confirm) one DPD shipment for an order. Idempotent through the shipment + ledger unique
     * keys: a duplicate run performs no external call.
     *
     * @return array{status:string,state:string,order_id:int,shipment_id:?int,tracking_number:?string,label_ref:?string,reason?:string,errors?:list<string>}
     */
    public function createForOrder(int $orderId, int $sequence = 1): array
    {
        $order = DpdModule::loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'order_not_found');
        }

        $guard = CarrierBinding::guardForCarrier($order, self::CARRIER);
        if (!$guard['allow']) {
            $reason = $guard['reason'] === 'not_applicable' ? 'carrier_not_applicable' : $guard['reason'];
            do_action('fch/shipping/label_skipped', $orderId, $reason);
            return $this->summary('skipped', ExternalOperation::NOT_STARTED, $orderId, null, null, null, $reason);
        }
        if (!DpdModule::hasCredentials()) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'missing_credentials');
        }

        $snapshot = $this->buildSnapshot($order, $sequence);
        if ($snapshot === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'snapshot_failed');
        }

        // COD value-range validation BEFORE any dispatch (§3.6 / DPD-U11; settings-driven).
        $codMinor = (int) ($snapshot['cod']['amount_minor'] ?? 0);
        $codCheck = CodValueLimits::check(
            self::CARRIER,
            $codMinor,
            DpdModule::settingInt('cod_min_minor'),
            DpdModule::settingInt('cod_max_minor')
        );
        if (!$codCheck['ok']) {
            return $this->summary(
                'validation_error',
                ExternalOperation::NOT_STARTED,
                $orderId,
                null,
                null,
                null,
                (string) ($codCheck['reason'] ?? 'cod_limit'),
                ['cod_limit_' . (string) ($codCheck['reason'] ?? 'unknown')]
            );
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(DpdModule::SETTINGS_SCOPE);
        $idempotencyKey = (string) $snapshot['idempotency_key'];

        $shipment = $this->shipments->getOrCreate([
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'carrier' => self::CARRIER,
            'mode' => $mode,
            'account_id' => $account,
            'shipment_no' => max(1, $sequence),
            'idempotency_key' => $idempotencyKey,
            'items' => $this->jsonEncode($this->coveredItems($order)),
            'pickup_point' => $this->jsonEncode($this->pickupSnapshotForRow($snapshot)),
            'cod_amount_minor' => $codMinor,
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

        $built = DpdMapper::build($snapshot);
        if (!$built['ok']) {
            // Local validation failure BEFORE dispatch: ledger untouched, shipment stays NOT_STARTED.
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
            'business_reference' => (string) ($snapshot['reference1'] ?? ''),
            'currency' => (string) ($snapshot['currency'] ?? ''),
            // FC stores amount×100 (even HUF) → convert to our ISO minor units at the boundary.
            'amount_minor' => FluentCartMoney::fromCartCents(
                (int) ($order->total_amount ?? 0),
                (string) ($snapshot['currency'] ?? 'HUF')
            ),
            'max_attempts' => self::MAX_ATTEMPTS,
        ], function (object $ledgerRow) use ($client, $built, $snapshot, $request): ProviderCallResult {
            $response = $client->createShipment($built['payload']);
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

        return $this->summary('local_error', $state, $orderId, $shipmentId, null, null, (string) ($run['result']?->code ?? 'LOCAL_ERROR'));
    }

    /**
     * Read-only label reprint (invariant §1.6). DPD label creation already returns `labelFile` inline when
     * `saveMode:printed`; a reprint is served from the stored artifact ONLY — it never calls the provider
     * and never changes shipment/label state. Returns null when no stored label exists.
     *
     * @return array{shipment_id:int,source:string,label_ref:string,label:string}|null
     */
    public function reprintLabel(int $shipmentId): ?array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return null;
        }
        if ((string) ($row->carrier ?? '') !== self::CARRIER) {
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
     * Build the immutable snapshot the pure mapper and the COD check consume. Amounts are integer domain
     * minor units taken through the FluentCart boundary; no float money arithmetic is performed.
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
            $phone = trim((string) ($address->phone ?? ''));
            if ($phone === '' && is_object($billing)) {
                $phone = trim((string) ($billing->phone ?? ''));
            }
            $email = trim((string) ($address->email ?? ''));
            if ($email === '' && is_object($billing)) {
                $email = trim((string) ($billing->email ?? ''));
            }

            $country = strtoupper(trim((string) ($address->country ?? 'HU')));
            if ($country === '') {
                $country = 'HU';
            }

            $pickup = DpdModule::pickupPoint($order);
            $pudoId = (string) ($pickup['point_id'] ?? '');
            $deliveryMode = $pudoId !== '' ? 'PUDO' : strtoupper((string) DpdModule::setting('delivery_mode', 'HD'));
            if (!in_array($deliveryMode, self::DELIVERY_MODES, true)) {
                $deliveryMode = 'HD';
            }

            $sequence = max(1, $sequence);
            $idempotencyKey = self::idempotencyKey((int) ($order->id ?? 0), $sequence);
            $currency = strtoupper((string) ($order->currency ?? 'HUF'));
            $cod = $this->codAmount($order);

            $totalKg = $this->totalWeightKg($order);
            $weights = DpdMapper::splitWeights($totalKg, DpdMapper::maxWeightKg($country === 'HU'));
            if ($weights === []) {
                $weights = [0.0];
            }
            $declaredValue = max(0, (int) DpdModule::setting('declared_value_huf', 0));
            $parcels = [];
            foreach ($weights as $index => $weight) {
                $parcels[] = [
                    'weight_kg' => round($weight, 3),
                    'insurance_minor' => $index === 0 ? $declaredValue : 0,
                    'dims' => null,
                ];
            }

            $service = [
                'main_service_code' => (string) DpdModule::setting('main_service_code', ''),
                'main_service_element_codes' => array_values(array_filter(
                    (array) DpdModule::setting('main_service_element_codes', []),
                    static fn($v): bool => trim((string) $v) !== ''
                )),
                'additional_service' => ['pudo_id' => $pudoId],
            ];

            return [
                'order_id' => (int) ($order->id ?? 0),
                'idempotency_key' => $idempotencyKey,
                'bu_code' => (string) DpdModule::buCode(),
                'customer_id' => (string) DpdModule::setting('customer_id', ''),
                'sender' => [
                    'sender_address_id' => (string) DpdModule::setting('sender_address_id', ''),
                    'company_name' => (string) DpdModule::setting('sender_company_name', ''),
                    'name' => (string) DpdModule::setting('sender_name', ''),
                    'contact_name' => (string) DpdModule::setting('sender_contact_name', ''),
                    'contact_phone' => (string) DpdModule::setting('sender_phone', ''),
                    'contact_email' => (string) DpdModule::setting('sender_email', ''),
                    'address' => [
                        'countryCode' => (string) DpdModule::setting('sender_country', 'HU'),
                        'zipCode' => (string) DpdModule::setting('sender_zip', ''),
                        'city' => (string) DpdModule::setting('sender_city', ''),
                        'addressLine1' => (string) DpdModule::setting('sender_address_1', ''),
                    ],
                ],
                'receiver' => [
                    'name' => (string) ($address->name ?? ''),
                    'contactPhone' => $phone,
                    'contactEmail' => $email,
                    'country' => $country,
                    'address' => [
                        'countryCode' => $country,
                        'zipCode' => (string) ($address->postcode ?? ''),
                        'city' => (string) ($address->city ?? ''),
                        'addressLine1' => (string) ($address->address_1 ?? ''),
                        'addressLine2' => (string) ($address->address_2 ?? ''),
                    ],
                ],
                'delivery_mode' => $deliveryMode,
                'service' => $service,
                'cod' => [
                    'amount_minor' => $cod,
                    'currency' => $currency,
                    'payment_type' => (string) DpdModule::setting('cod_payment_type', 'Cash'),
                    'split' => (string) DpdModule::setting('cod_split', ''),
                    'bank_account_id' => (string) DpdModule::setting('cod_bank_account_id', ''),
                ],
                'currency' => $currency,
                'parcels' => $parcels,
                'label' => [
                    'size' => (string) DpdModule::setting('label_size', 'A4'),
                    'format' => (string) DpdModule::setting('print_format', 'pdf'),
                    'dpi' => (int) DpdModule::setting('dpi', 203),
                    'parcelonly' => (bool) DpdModule::setting('parcelonly', false),
                ],
                'save_mode' => (string) DpdModule::setting('save_mode', 'printed'),
                'pickup_date' => (string) DpdModule::setting('pickup_date', ''),
                'reference2' => (string) ($order->uuid ?? ''),
                'reference3' => '',
                'reference4' => '',
                'inter' => null,
                'return_consolidation' => null,
            ];
        } catch (\Throwable $e) {
            do_action('fch/dpd/snapshot_failed', (int) ($order->id ?? 0), $e);
            return null;
        }
    }

    // ---- provider I/O -------------------------------------------------------------------------------

    /**
     * Interpret the DPD-001 create response. A 400 field→message body is a definitive rejection; a timeout
     * or 5xx after dispatch is UNCERTAIN and is never auto-retried (04_DPD §7).
     *
     * @param array<string,mixed> $request secret-free request summary
     */
    private function interpretCreateResponse(Response $response, array $snapshot, array $request): ProviderCallResult
    {
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('DPD_HTTP_' . $response->status, $request);
        }
        if ($response->status === 401) {
            // No token refresh is possible (acquisition undocumented); surface the definitive auth failure.
            return ProviderCallResult::rejected('DPD_UNAUTHORIZED', 'Bearer rejected; no documented token refresh.', $request);
        }

        $data = $response->json();
        if ($data === []) {
            return ProviderCallResult::uncertain('DPD_EMPTY_RESPONSE', $request, ['status' => $response->status]);
        }

        $results = self::asResultList($data);
        $isErrorBody = $response->status >= 400 && !isset($data['shipmentResults']);
        if ($isErrorBody || self::firstErrors($results) !== []) {
            $messages = self::flattenMessages($data);
            return ProviderCallResult::rejected('DPD_VALIDATION_' . $response->status, $messages, $request);
        }
        if ($results === []) {
            return ProviderCallResult::uncertain('DPD_NO_RESULT', $request, ['status' => $response->status]);
        }

        $idempotencyKey = (string) ($snapshot['idempotency_key'] ?? '');
        $numOrder = (string) ($snapshot['order_id'] ?? '');
        $candidate = self::matchResult($results, [$idempotencyKey, $numOrder]);
        if ($candidate === null) {
            return ProviderCallResult::uncertain('DPD_RESPONSE_MISMATCH', $request);
        }

        $errors = self::firstErrors([$candidate]);
        if ($errors !== []) {
            $first = $errors[0];
            return ProviderCallResult::rejected((string) $first['code'], (string) $first['message'], $request);
        }

        $shipment = is_array($candidate['shipment'] ?? null) ? $candidate['shipment'] : [];
        $shipmentId = trim((string) ($shipment['shipmentId'] ?? $candidate['shipmentId'] ?? ''));
        $parcelNumbers = self::parcelNumbers($shipment['parcels'] ?? $candidate['parcels'] ?? []);
        if ($shipmentId === '' && $parcelNumbers === []) {
            return ProviderCallResult::uncertain('DPD_NO_SHIPMENT_ID', $request);
        }

        $tracking = $parcelNumbers !== [] ? $parcelNumbers[0] : $shipmentId;
        $barcode = trim((string) ($candidate['barcodeText'] ?? ''));
        if ($barcode === '') {
            $barcode = $tracking;
        }

        $fields = [
            'external_id' => $shipmentId !== '' ? $shipmentId : $tracking,
            'external_ref2' => $idempotencyKey,
            'external_ref3' => $shipmentId,
            'barcode' => $barcode,
            'tracking_number' => $tracking,
            'carrier_reference' => $shipmentId,
            'parcels' => $parcelNumbers,
            'package_count' => max(1, count($parcelNumbers)),
            'dpd_transaction_id' => (string) ($data['transactionId'] ?? ''),
        ];

        $binary = self::extractLabelBinary((string) ($candidate['labelFile'] ?? ''));
        if ($binary !== null) {
            $extension = strtolower((string) ($snapshot['label']['format'] ?? 'pdf')) === 'zpl' ? 'zpl' : 'pdf';
            try {
                $stored = (new CarrierLabelStore(self::CARRIER))->store('dpd-label-' . $tracking, $binary, $extension);
                $fields['label_ref'] = $stored['ref'];
                $fields['label_sha256'] = $stored['sha256'];
            } catch (\Throwable $e) {
                // Label storage is best-effort; the proved shipment must not be downgraded by it.
            }
        }

        return ProviderCallResult::success($fields, $request, ['shipmentId' => $shipmentId, 'parcelNumber' => $tracking]);
    }

    /** Persist a proved create outcome onto the shipment mirror (05_DATA_SCHEMA §7.4). */
    private function finalizeConfirmed(int $shipmentId, array $fields): void
    {
        self::ensureShipmentInProgress($this->shipments, $shipmentId);

        $extra = [
            'operation_id' => (int) ($fields['operation_id'] ?? 0),
            'external_shipment_id' => (string) ($fields['carrier_reference'] ?? ''),
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
     * DPD-004 `PUT /shipments/cancellation` — ledger-bound cancellation dispatcher (W4 logistics).
     * Draft -> deleted, non-draft -> status "Cancelled"; no idempotency key is documented (04 §4.1), so a
     * timeout is UNCERTAIN and stays on the operator queue (DPD has no registered read-back reconciler —
     * resolving it needs the DPD UI / manual resolution), never a second cancellation call.
     *
     * @param object $ledgerRow shipment_cancel ledger row
     */
    public function dispatchCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::localError('MISSING_SHIPMENT_ROW', 'A szállítmánysor nem található.');
        }
        $externalId = trim((string) ($row->external_shipment_id ?? ''));
        if ($externalId === '') {
            return ProviderCallResult::localError('NO_SHIPMENT_ID', 'Nincs visszavonható DPD küldeményazonosító.');
        }
        $buCode = trim((string) DpdModule::buCode());
        $customerId = trim((string) DpdModule::setting('customer_id', ''));
        if ($buCode === '' || $customerId === '') {
            return ProviderCallResult::localError('MISSING_ACCOUNT_CONFIG', 'A visszavonáshoz hiányzik a DPD buCode vagy customerId beállítás.');
        }

        $mode = (string) $ledgerRow->mode;
        $account = (string) $ledgerRow->account_id;
        $token = trim((string) (new \FluentCartHungary\Core\Settings\CredentialStore())->get(self::PROVIDER, $mode, $account, DpdModule::TOKEN_KEY) ?? '');
        if ($token === '') {
            return ProviderCallResult::localError('MISSING_CREDENTIALS', 'A visszavonáshoz az eredeti DPD fiók (mód/fiók) tokenje nem érhető el.');
        }

        $shipmentId = ctype_digit($externalId) ? (int) $externalId : $externalId;
        $payload = [
            'buCode' => $buCode,
            'customerId' => $customerId,
            'shipmentIdList' => [$shipmentId],
        ];
        $request = ['operation' => 'cancelShipment', 'shipmentId' => $externalId];

        $client = new DpdClient($mode, $token, $buCode);
        $response = $client->cancelShipment($payload);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('DPD_HTTP_' . $response->status, $request);
        }
        if (!$response->isSuccess()) {
            // Documented 401/403/404: an explicit refusal at dispatch time.
            return ProviderCallResult::rejected('DPD_CANCEL_REJECTED', 'HTTP ' . $response->status, $request);
        }

        $decoded = json_decode((string) $response->body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $results = $decoded['cancelResultList'] ?? $decoded['cancelResult'] ?? null;
        if (is_array($results)) {
            foreach ($results as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $id = (string) ($entry['shipmentId'] ?? '');
                if ($id !== '' && ($id === $externalId || (string) (int) $id === (string) (int) $externalId)) {
                    return ProviderCallResult::success([
                        'external_id' => $externalId,
                        'external_ref2' => $externalId,
                        'provider_doc_type' => 'cancellation',
                        'cancel_state' => 'cancelled',
                    ], $request, ['transactionId' => (string) ($decoded['transactionId'] ?? '')]);
                }
            }
        }
        return ProviderCallResult::uncertain('DPD_CANCEL_UNPROVEN', $request, ['body_keys' => array_slice(array_keys($decoded), 0, 8)]);
    }

    private function client(): DpdClient
    {
        return new DpdClient($this->settings->mode());
    }

    // ---- snapshot helpers ---------------------------------------------------------------------------

    /**
     * COD amount in domain minor units (HUF = forint) for a FluentCart COD order
     * (payment_method `offline_payment`). FC stores amount×100; the boundary conversion is mandatory.
     */
    private function codAmount(object $order): int
    {
        if ((string) ($order->payment_method ?? '') !== DpdModule::COD_METHOD) {
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

    /** @return float total consignment weight in kg from order items (`other_info.weight`), settings fallback. */
    private function totalWeightKg(object $order): float
    {
        $totalG = 0;
        $defaultUnit = strtolower((string) DpdModule::setting('weight_unit', 'kg'));
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
            $totalG += (int) round($grams * max(1, $quantity));
        }
        if ($totalG <= 0) {
            $totalG = max(0, (int) DpdModule::setting('default_weight_g', 1000));
        }
        return $totalG > 0 ? $totalG / 1000.0 : 0.0;
    }

    /** Pickup snapshot from order meta `_fch_pickup_point` (constraints §2). @return array<string,mixed> */
    private function pickupSnapshotForRow(array $snapshot): ?array
    {
        $pudoId = trim((string) ($snapshot['service']['additional_service']['pudo_id'] ?? ''));
        if ($pudoId === '') {
            return null;
        }
        return [
            'carrier' => self::CARRIER,
            'deliveryMode' => (string) ($snapshot['delivery_mode'] ?? 'PUDO'),
            'pudoId' => $pudoId,
        ];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $payload @return array<string,mixed> */
    private function requestSummary(array $snapshot, array $payload): array
    {
        $shipment = (array) (($payload['shipments'][0] ?? []) ?: []);
        return [
            'buCode' => (string) ($payload['buCode'] ?? ''),
            'customerId' => (string) ($payload['customerId'] ?? ''),
            'numOrder' => (string) ($shipment['numOrder'] ?? ''),
            'mainServiceCode' => (string) ($shipment['service']['mainServiceCode'] ?? ''),
            'pudoId' => (string) ($shipment['service']['additionalService']['pudoId'] ?? ''),
            'parcel_count' => count((array) ($shipment['parcels'] ?? [])),
            'cod_minor' => (int) ($shipment['service']['additionalService']['cod']['amount'] ?? 0),
            'currency' => (string) ($snapshot['currency'] ?? ''),
        ];
    }

    /**
     * Normalize a FluentCart relation/collection/array into a plain list. FluentCart's Collection::get() is
     * a KEY ACCESSOR (requires an argument), so toArray()/iteration is used instead.
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
        if (isset($data['shipmentResults']) && is_array($data['shipmentResults']) && array_is_list($data['shipmentResults'])) {
            return array_values(array_filter($data['shipmentResults'], 'is_array'));
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['results', 'items'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return [];
    }

    /**
     * @param list<array<string,mixed>> $results
     * @param list<string>             $references idempotency key (reference1) / numOrder
     * @return array<string,mixed>|null
     */
    private static function matchResult(array $results, array $references): ?array
    {
        $references = array_values(array_filter(array_map('strval', $references), static fn(string $v): bool => $v !== ''));
        if ($references !== []) {
            foreach ($results as $index => $result) {
                foreach (['reference1', 'numOrder'] as $key) {
                    $value = trim((string) ($result[$key] ?? ''));
                    if ($value !== '' && in_array($value, $references, true)) {
                        return $result;
                    }
                }
                if (isset($results[$index]['shipment']['reference1'])) {
                    $value = trim((string) $results[$index]['shipment']['reference1']);
                    if ($value !== '' && in_array($value, $references, true)) {
                        return $result;
                    }
                }
            }
        }
        return count($results) === 1 ? $results[0] : null;
    }

    /** @param list<array<string,mixed>> $results @return list<array{code:string,message:string}> */
    private static function firstErrors(array $results): array
    {
        foreach ($results as $result) {
            $raw = $result['errors'] ?? [];
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            $errors = [];
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    $errors[] = [
                        'code' => (string) ($entry['code'] ?? $entry['errorCode'] ?? 'DPD_ERROR'),
                        'message' => (string) ($entry['message'] ?? $entry['errorMessage'] ?? ''),
                    ];
                } elseif (is_scalar($entry)) {
                    $errors[] = ['code' => 'DPD_ERROR', 'message' => (string) $entry];
                }
            }
            if ($errors !== []) {
                return $errors;
            }
        }
        return [];
    }

    /** Flatten a DPD validation object (field→message) to a bounded, secret-free string. */
    private static function flattenMessages(array $data): string
    {
        $parts = [];
        array_walk_recursive($data, static function ($value, $key) use (&$parts): void {
            if (is_scalar($value) && $value !== '') {
                $parts[] = (string) $key . ': ' . (string) $value;
            }
        });
        $message = implode('; ', array_slice($parts, 0, 20));
        return substr($message, 0, 500);
    }

    /** @param mixed $parcels @return list<string> */
    private static function parcelNumbers(mixed $parcels): array
    {
        if (!is_array($parcels)) {
            return [];
        }
        $out = [];
        foreach ($parcels as $parcel) {
            if (is_array($parcel) && isset($parcel['parcelNumber']) && (string) $parcel['parcelNumber'] !== '') {
                $out[] = (string) $parcel['parcelNumber'];
            } elseif (is_scalar($parcel) && (string) $parcel !== '') {
                $out[] = (string) $parcel;
            }
        }
        return $out;
    }

    /** Decode a `labelFile` value (raw base64 or a data URI) to binary. @return string|null */
    private static function extractLabelBinary(string $labelFile): ?string
    {
        $labelFile = trim($labelFile);
        if ($labelFile === '') {
            return null;
        }
        if (str_contains($labelFile, ',')) {
            $parts = explode(',', $labelFile, 2);
            if (str_contains($parts[0], 'base64')) {
                $labelFile = $parts[1];
            }
        }
        $binary = base64_decode($labelFile, true);
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
