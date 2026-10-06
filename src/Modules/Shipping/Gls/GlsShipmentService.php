<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

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
 * Orchestrates MyGLS label creation and reprint for one FluentCart order.
 *
 * FluentCart reads go ONLY through verified APIs (no raw SQL, no core patching):
 *  - `FluentCart\App\Models\Order::find($id)`, `$order->order_items`, `$order->shipping_address`,
 *    `$order->billing_address`, `$order->getMeta()/updateMeta()` (same pattern as MplShipmentService).
 *
 * Claim + exactly-once local effects:
 *  - `ShipmentRepository::getOrCreate()` with carrier `gls` and idempotency key
 *    `fch:{order}:gls:label_create:{n}` — the kind segment MUST carry the carrier: the ledger and
 *    shipment tables have a GLOBAL unique key on `idempotency_key`, so the canonical
 *    `fch:{order}:label_create:{n}` form would collide with an MPL row on the same order;
 *  - `OperationRunner::run()` with `kind=label_create`, `max_attempts=1` — MyGLS has NO server-side
 *    idempotency key (GLS-UNK-05) and an auto create-retry can duplicate ParcelIds/parcel numbers (§7
 *    verdict: "Auto create-retry is risky"). A timeout becomes `UNCERTAIN` + a reconciliation job, never
 *    a re-POST;
 *  - `ClientReference` = the configurable business reference (default FluentCart order uuid, GLS-005/§9) so
 *    a lost create can be matched in `GetParcelList` output (§7).
 *
 * The client credentials and the raw request/response bodies never reach a ProviderCallResult payload, a
 * log, or an exception. Amounts are integer ISO minor units (HUF = forint).
 */
final class GlsShipmentService
{
    public const PROVIDER = GlsModule::PROVIDER;
    public const MODULE = GlsModule::MODULE;
    public const CARRIER = GlsModule::CARRIER;

    /** 04_GLS §7: create is not retry-safe (no idempotency key, retry guard error 31 is not idempotency). */
    public const MAX_ATTEMPTS = 1;

    /** Carrier-scoped ledger/shipment key (global unique key on idempotency_key — never a bare kind). */
    public static function idempotencyKey(int $orderId, int $sequence): string
    {
        return sprintf('fch:%d:gls:label_create:%d', $orderId, max(1, $sequence));
    }

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
     * Create (or confirm) one GLS shipment for an order. Idempotent through the shipment + ledger unique
     * keys: a duplicate run performs no external call.
     *
     * @return array{status:string,state:string,order_id:int,shipment_id:?int,tracking_number:?string,label_ref:?string,reason?:string,errors?:list<string>}
     */
    public function createForOrder(int $orderId, int $sequence = 1): array
    {
        $order = GlsModule::loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'order_not_found');
        }

        $guard = CarrierBinding::guardForCarrier($order, self::CARRIER);
        if (!$guard['allow']) {
            $reason = $guard['reason'] === 'not_applicable' ? 'carrier_not_applicable' : $guard['reason'];
            do_action('fch/shipping/label_skipped', $orderId, $reason);
            return $this->summary('skipped', ExternalOperation::NOT_STARTED, $orderId, null, null, null, $reason);
        }
        if (!GlsModule::hasCredentials()) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'missing_credentials');
        }

        $snapshot = $this->buildSnapshot($order, $sequence);
        if ($snapshot === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'snapshot_failed');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(GlsModule::SETTINGS_SCOPE);
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

        $built = GlsMapper::build($snapshot);
        if (!$built['ok']) {
            // Local validation failure BEFORE dispatch: the ledger is not touched; the shipment stays
            // NOT_STARTED so a corrected payload/re-run is possible (LOCAL_ERROR, not an external state).
            return $this->summary('validation_error', ExternalOperation::NOT_STARTED, $orderId, $shipmentId, null, null, 'label_validation_failed', $built['errors']);
        }

        // COD value guard BEFORE the ledger. CODAmount >= 0 per §3.6/§6.2 (error 8); per-country ranges are
        // NOT in the current doc (GLS-UNK-02 / GLS-004 / GLS-904) so only the >=0 default applies unless the
        // operator configures cod_min_minor/cod_max_minor.
        $cod = self::totalCodMinor($snapshot);
        $check = CodValueLimits::check(
            self::CARRIER,
            $cod,
            $this->nullableInt(GlsModule::setting('cod_min_minor', null)),
            $this->nullableInt(GlsModule::setting('cod_max_minor', null))
        );
        if (!$check['ok']) {
            return $this->summary('validation_error', ExternalOperation::NOT_STARTED, $orderId, $shipmentId, null, null, (string) ($check['reason'] ?? 'cod_invalid'));
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
            'business_reference' => (string) ($snapshot['client_reference'] ?? ''),
            'currency' => (string) ($snapshot['currency'] ?? ''),
            // FC stores amount×100 (even HUF) → convert to our ISO minor units at the boundary.
            'amount_minor' => FluentCartMoney::fromCartCents(
                (int) ($order->total_amount ?? 0),
                (string) ($snapshot['currency'] ?? 'HUF')
            ),
            'max_attempts' => self::MAX_ATTEMPTS,
        ], function (object $ledgerRow) use ($client, $built, $snapshot, $request): ProviderCallResult {
            $response = $client->printLabels($built['payload']);
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
     * Read-only label reprint (invariant §1.6, 04_GLS §3.4). NEVER calls the provider and never inserts a
     * row or changes state/label_state: it only reads the stored `label_ref`.
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
     * Build the immutable snapshot the pure mapper consumes. Amounts are integer ISO minor units taken
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

            $billing = $order->billing_address ?? null;
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

            $street = trim((string) ($address->address_1 ?? ''));
            $houseNumber = trim((string) ($address->address_2 ?? ''));
            if ($houseNumber !== '' && !ctype_digit($houseNumber)) {
                // address_2 is not a pure house number: keep it as street info, not HouseNumber.
                $street = trim($street . ' ' . $houseNumber);
                $houseNumber = '';
            }

            $pickup = $this->pickupPoint($order);
            $deliveryMode = 'HOME';
            if ($pickup !== []) {
                // §3.2: any GLS delivery to a point uses service PSD.
                $deliveryMode = 'PSD';
            } elseif (in_array(strtoupper((string) GlsModule::setting('delivery_mode', 'HOME')), GlsMapper::DELIVERY_MODES, true)) {
                $deliveryMode = strtoupper((string) GlsModule::setting('delivery_mode', 'HOME'));
            }

            $currency = strtoupper((string) ($order->currency ?? 'HUF'));
            $clientReference = $this->clientReference($order);
            $cod = $this->codAmount($order);

            $services = (array) GlsModule::setting('services', []);
            if ($cod > 0) {
                $services[] = 'COD';
            }

            $dims = (array) GlsModule::setting('dims_mm', []);

            return [
                'order_id' => (int) ($order->id ?? 0),
                'idempotency_key' => self::idempotencyKey((int) ($order->id ?? 0), $sequence),
                'client_reference' => $clientReference,
                'mode' => $this->settings->mode(),
                'webshop_engine' => (string) GlsModule::setting('webshop_engine', 'FluentCart'),
                'currency' => $currency,
                'delivery_mode' => $deliveryMode,
                'pickup_point' => $pickup,
                'recipient' => [
                    'name' => (string) ($address->name ?? ''),
                    'phone' => $phone,
                    'email' => $email,
                    'country' => $country,
                    'address' => [
                        'street' => $street,
                        'city' => (string) ($address->city ?? ''),
                        'zip' => (string) ($address->postcode ?? ''),
                        'house_number' => $houseNumber,
                        'house_number_info' => trim((string) ($address->address_2 ?? '')),
                    ],
                ],
                'parcels' => [[
                    'count' => max(1, (int) GlsModule::setting('count', 1)),
                    'weight_g' => $this->totalWeightG($order),
                    'content' => (string) GlsModule::setting('content', ''),
                    'package_type' => (int) GlsModule::setting('package_type', 1),
                    'cod_minor' => $cod,
                    'cod_reference' => $clientReference,
                    'cod_currency' => $currency,
                    'value_minor' => max(0, (int) GlsModule::setting('declared_value_minor', 0)),
                    'xxl' => (bool) GlsModule::setting('xxl', false),
                    'dims_mm' => $dims === [] ? null : $dims,
                ]],
                'services' => array_values(array_unique($services)),
                'type_of_printer' => (string) GlsModule::setting('type_of_printer', 'A4_2x2'),
                'print_position' => (int) GlsModule::setting('print_position', 1),
                'show_print_dialog' => (bool) GlsModule::setting('show_print_dialog', false),
                'hide_phone_number' => (bool) GlsModule::setting('hide_phone_number', false),
            ];
        } catch (\Throwable $e) {
            do_action('fch/gls/snapshot_failed', (int) ($order->id ?? 0), $e);
            return null;
        }
    }

    /**
     * Interpret the GLS-017 `PrintLabels` response. Errors prove rejection; a lost/empty response after
     * dispatch is UNCERTAIN and never auto-retried (§7).
     *
     * @param array<string,mixed> $request secret-free request summary
     */
    private function interpretCreateResponse(Response $response, array $snapshot, array $request): ProviderCallResult
    {
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('GLS_HTTP_' . $response->status, $request);
        }

        $data = $response->json();
        if ($data === []) {
            return ProviderCallResult::uncertain('GLS_EMPTY_RESPONSE', $request, ['status' => $response->status]);
        }

        $interp = GlsMapper::interpretPrintResponse($data);
        if ($interp['errors'] !== []) {
            // Definite business rejection (Appendix A codes, §6.2); never retry as create.
            $first = $interp['errors'][0];
            return ProviderCallResult::rejected((string) $first['code'], (string) $first['message'], $request);
        }
        if (!$interp['ok']) {
            return ProviderCallResult::uncertain('GLS_EMPTY_RESPONSE', $request, ['status' => $response->status]);
        }

        $clientReference = (string) ($snapshot['client_reference'] ?? '');
        $match = self::matchParcel($interp['parcels'], $clientReference);
        if ($match === null || (string) ($match['parcel_number'] ?? '') === '') {
            // A 2xx without a parcel identity cannot be proved as created.
            return ProviderCallResult::uncertain('GLS_NO_PARCEL_NUMBER', $request, ['clientReference' => $clientReference]);
        }

        $parcelNumber = (string) $match['parcel_number'];
        $parcelNumbers = [];
        $parcelIds = [];
        foreach ($interp['parcels'] as $parcel) {
            if ((string) ($parcel['parcel_number'] ?? '') !== '') {
                $parcelNumbers[] = (string) $parcel['parcel_number'];
            }
            if ((int) ($parcel['parcel_id'] ?? 0) > 0) {
                $parcelIds[] = (int) $parcel['parcel_id'];
            }
        }
        if ($parcelNumbers === []) {
            $parcelNumbers = [$parcelNumber];
        }

        $fields = [
            'external_id' => $parcelNumber,
            'external_ref2' => $clientReference,
            'external_ref3' => (string) ($snapshot['idempotency_key'] ?? ''),
            'barcode' => (string) ($match['barcode'] ?? $parcelNumber),
            'tracking_number' => $parcelNumber,
            'carrier_reference' => $clientReference,
            'parcels' => $parcelNumbers,
            'parcel_ids' => $parcelIds,
            'package_count' => max(1, count($parcelNumbers)),
        ];

        $binary = GlsMapper::extractLabelBinary($interp['label_base64']);
        if ($binary !== null && $binary !== '') {
            try {
                $stored = (new CarrierLabelStore(self::CARRIER))->store('gls-label-' . $parcelNumber, $binary, 'pdf');
                $fields['label_ref'] = $stored['ref'];
                $fields['label_sha256'] = $stored['sha256'];
            } catch (\Throwable $e) {
                // Label storage is best-effort; the proved shipment must not be downgraded by it.
            }
        }

        return ProviderCallResult::success($fields, $request, ['clientReference' => $clientReference, 'parcelNumber' => $parcelNumber]);
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

    private function client(): GlsClient
    {
        return new GlsClient($this->settings->mode());
    }

    // ---- snapshot helpers ---------------------------------------------------------------------------

    /**
     * Configurable `ClientReference` (GLS-005/§9): operator template with `{order_id}`/`{uuid}` placeholders,
     * else the FluentCart order uuid, else `FCH-{id}`. Never a secret.
     */
    private function clientReference(object $order): string
    {
        $template = trim((string) GlsModule::setting('client_reference', ''));
        $orderId = (int) ($order->id ?? 0);
        $uuid = trim((string) ($order->uuid ?? ''));

        if ($template !== '') {
            return str_replace(['{order_id}', '{uuid}'], [(string) $orderId, $uuid], $template);
        }
        if ($uuid !== '') {
            return $uuid;
        }
        return 'FCH-' . $orderId;
    }

    /**
     * COD amount in our ISO minor units (HUF = forint) for a FluentCart COD order (payment_method
     * `offline_payment`). FluentCart stores amount×100; GLS `CODAmount` is a domain integer — the boundary
     * conversion is mandatory here (ADR-007; 04_GLS §3.6).
     */
    private function codAmount(object $order): int
    {
        if ((string) ($order->payment_method ?? '') !== GlsModule::COD_METHOD) {
            return 0;
        }
        return max(0, FluentCartMoney::fromCartCents(
            (int) ($order->total_amount ?? 0),
            (string) ($order->currency ?? 'HUF')
        ));
    }

    /** @return int total COD across the snapshot parcels (domain minor units). */
    public static function totalCodMinor(array $snapshot): int
    {
        $total = 0;
        foreach ((array) ($snapshot['parcels'] ?? []) as $parcel) {
            $parcel = (array) $parcel;
            $total += max(0, (int) ($parcel['cod_minor'] ?? 0));
        }
        return $total;
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

    /** @return int total shipment weight in grams from order items (`other_info.weight`), settings fallback. */
    private function totalWeightG(object $order): int
    {
        $total = 0;
        $defaultUnit = strtolower((string) GlsModule::setting('weight_unit', 'kg'));
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
            $total = max(0, (int) GlsModule::setting('default_weight_g', 1000));
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
            'point_id' => (string) ($raw['point_id'] ?? $raw['id'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'address' => (string) ($raw['address'] ?? ''),
            'zip' => (string) ($raw['zip'] ?? ''),
            'city' => (string) ($raw['city'] ?? ''),
            'type' => strtoupper((string) ($raw['type'] ?? '')),
            'contact_name' => (string) ($raw['contact_name'] ?? $raw['name'] ?? ''),
            'contact_phone' => (string) ($raw['contact_phone'] ?? ''),
            'contact_email' => (string) ($raw['contact_email'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function pickupSnapshotForRow(array $snapshot): ?array
    {
        $pickup = (array) ($snapshot['pickup_point'] ?? []);
        $site = trim((string) ($pickup['point_id'] ?? ''));
        if ($site === '') {
            return null;
        }
        return ['pointId' => $site, 'deliveryMode' => (string) ($snapshot['delivery_mode'] ?? '')];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $payload @return array<string,mixed> */
    private function requestSummary(array $snapshot, array $payload): array
    {
        $parcels = (array) ($payload['ParcelList'] ?? []);
        $cod = 0;
        $weight = 0;
        foreach ($parcels as $parcel) {
            $parcel = (array) $parcel;
            $cod += (int) ($parcel['CODAmount'] ?? 0);
            $properties = (array) ($parcel['ParcelPropertyList'][0] ?? []);
            $weight += (int) round(((float) ($properties['Weight'] ?? 0)) * 1000);
        }
        return [
            'operation' => 'PrintLabels',
            'ClientReference' => (string) ($snapshot['client_reference'] ?? ''),
            'WebshopEngine' => (string) ($payload['WebshopEngine'] ?? ''),
            'DeliveryMode' => (string) ($snapshot['delivery_mode'] ?? ''),
            'parcel_count' => count($parcels),
            'cod_huf' => $cod,
            'weight_g' => $weight,
        ];
    }

    /**
     * Normalize a FluentCart relation/collection/array into a plain list. FluentCart's Collection::get()
     * is a KEY ACCESSOR (requires an argument) unlike a Laravel-style no-arg getter, so toArray()/iteration
     * is used instead (same contract as MplShipmentService::toList).
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

    /**
     * Prefer the response parcel whose ClientReference matches ours; otherwise accept the single parcel.
     *
     * @param list<array<string,mixed>> $parcels
     * @return array<string,mixed>|null
     */
    private static function matchParcel(array $parcels, string $clientReference): ?array
    {
        if ($clientReference !== '') {
            foreach ($parcels as $parcel) {
                if (trim((string) ($parcel['client_reference'] ?? '')) === $clientReference) {
                    return $parcel;
                }
            }
        }
        foreach ($parcels as $parcel) {
            if ((string) ($parcel['parcel_number'] ?? '') !== '') {
                return $parcel;
            }
        }
        return $parcels[0] ?? null;
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

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
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
