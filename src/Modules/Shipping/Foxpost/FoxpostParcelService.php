<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

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
 * Orchestrates Foxpost parcel creation (and read-only reprint) for one FluentCart order.
 *
 * Skeleton mirrors MplShipmentService:
 *  - `ShipmentRepository::getOrCreate()` with carrier `foxpost` and
 *    `idempotency_key fch:{order}:foxpost:label_create:{n}` (provider-namespaced so several carriers can
 *    coexist on one order without key collision; 05_DATA_SCHEMA §5.4);
 *  - local validation BEFORE the ledger (recipient fields, COD range, size enum) so a fixable payload
 *    never dispatches;
 *  - `OperationRunner::run()` with `kind=label_create`.
 *
 * Retry safety (04_FOXPOST_CONTRACT.md §7, FX-U7):
 *  - APM **with** a pre-generated `uniqueBarcode` → `max_attempts = 2` (partial dedupe: a repeat returns
 *    `DUPLICATED_UNIQUE_BARCODE`, §6.1/§7);
 *  - HD (address delivery) has no client unique key → `max_attempts = 1`; auto create-retry is NOT safe.
 *  A transport timeout is `UNCERTAIN` + a read-back reconcile job (never a blind re-POST).
 *
 * Money is integer domain minor units (HUF = forint): every FluentCart amount passes through
 * FluentCartMoney at the boundary; no global ×100/÷100, no floats.
 */
final class FoxpostParcelService
{
    public const PROVIDER = FoxpostModule::PROVIDER;
    public const MODULE = FoxpostModule::MODULE;
    public const CARRIER = FoxpostModule::CARRIER;

    /** FluentCart Core COD payment-method slug. */
    public const COD_METHOD = FoxpostModule::COD_METHOD;

    /** 04_FOXPOST §5: size XS/S/M/L/XL (guide) / spec single-string enum defect (FX-U10). */
    public const SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    public const DELIVERY_MODES = ['APM', 'HD'];

    /** 04_FOXPOST §5: cod integer 0..1 000 000. */
    public const COD_MIN = 0;
    public const COD_MAX = 1000000;

    /** HU mobile regex (guide §6.1). */
    public const HU_PHONE_REGEX = '/^(\+36|36)(20|30|31|70|50|51)\d{7}$/';

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
     * @return array{status:string,state:string,order_id:int,shipment_id:?int,tracking_number:?string,label_ref:?string,reason?:string,errors?:list<string>}
     */
    public function createForOrder(int $orderId, int $sequence = 1): array
    {
        $order = FoxpostModule::loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'order_not_found');
        }

        $guard = CarrierBinding::guardForCarrier($order, self::CARRIER);
        if (!$guard['allow']) {
            $reason = $guard['reason'] === 'not_applicable' ? 'carrier_not_applicable' : $guard['reason'];
            do_action('fch/shipping/label_skipped', $orderId, $reason);
            return $this->summary('skipped', ExternalOperation::NOT_STARTED, $orderId, null, null, null, $reason);
        }
        if (!FoxpostModule::hasCredentials()) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'missing_credentials');
        }

        $snapshot = $this->buildSnapshot($order, $sequence);
        if ($snapshot === null) {
            return $this->summary('error', ExternalOperation::NOT_STARTED, $orderId, null, null, null, 'snapshot_failed');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(FoxpostModule::SETTINGS_SCOPE);
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
            'currency' => (string) ($snapshot['currency'] ?? ''),
        ]);
        $row = $shipment['row'];
        $shipmentId = (int) $row->id;
        $state = (string) $row->state;

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary(
                'already_created',
                $state,
                $orderId,
                $shipmentId,
                $this->nullableString($row->tracking_number ?? null),
                $this->nullableString($row->label_ref ?? null)
            );
        }
        if (in_array($state, [ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED], true)) {
            return $this->summary('not_retryable', $state, $orderId, $shipmentId, null, null, 'shipment_' . strtolower($state));
        }

        $built = self::build($snapshot);
        if (!$built['ok']) {
            // Local validation BEFORE dispatch: the ledger is not touched; the shipment stays NOT_STARTED.
            return $this->summary('validation_error', ExternalOperation::NOT_STARTED, $orderId, $shipmentId, null, null, 'label_validation_failed', $built['errors']);
        }

        $client = $this->client();
        $request = $this->requestSummary($snapshot, $built['payload']);
        $maxAttempts = self::maxAttempts($snapshot);

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
            // APM: uniqueBarcode is the documented read-back handle (GET /tracking accepts it, §4 op 13).
            'business_reference' => (string) ($snapshot['unique_barcode'] ?? ''),
            'currency' => (string) ($snapshot['currency'] ?? ''),
            'amount_minor' => FluentCartMoney::fromCartCents(
                (int) ($order->total_amount ?? 0),
                (string) ($snapshot['currency'] ?? 'HUF')
            ),
            'max_attempts' => $maxAttempts,
        ], function (object $ledgerRow) use ($client, $built, $snapshot, $request, $mode): ProviderCallResult {
            // Sandbox rule (04_FOXPOST §2): test environment must send isWeb=false.
            $response = $client->createParcels([$built['payload']], $mode !== 'test');
            return $this->interpretCreateResponse($response, $snapshot, $request);
        });

        $state = (string) $run['state'];
        $fields = is_array($run['result']?->fields) ? $run['result']->fields : [];

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            $fields['operation_id'] = (int) $run['row']->id;
            $this->finalizeConfirmed($shipmentId, $fields);
            // Create returns no inline label (contrast MPL); generate and store it now, best-effort.
            // The failure is surfaced on the result (`label_error`) so the operator/job log can see it —
            // label storage must never silently vanish.
            $labelError = null;
            try {
                $labelResult = (new FoxpostLabelService($client))->generateLabel($shipmentId);
                if (($labelResult['ok'] ?? false) !== true) {
                    $labelError = (string) ($labelResult['error'] ?? 'label_failed');
                }
            } catch (\Throwable $e) {
                $labelError = 'label_exception:' . $e->getMessage();
            }
            $fresh = $this->shipments->getById($shipmentId);
            $status = !empty($fields['already_created']) ? 'already_created' : 'created';
            $summary = $this->summary(
                $status,
                $state,
                $orderId,
                $shipmentId,
                $this->nullableString(($fresh->tracking_number ?? null) ?? ($fields['tracking_number'] ?? null)),
                $this->nullableString(($fresh->label_ref ?? null) ?? ($fields['label_ref'] ?? null))
            );
            if ($labelError !== null) {
                $summary['label_error'] = $labelError;
            }
            return $summary;
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
     * Read-only reprint (invariant §1.6): returns the stored label ref only. It never generates a new
     * label and never changes shipment/label state — "reprint = stored ref only" (task W2 Foxpost).
     *
     * @return array{shipment_id:int,source:string,label_ref:string,label:string}|null
     */
    public function reprintLabel(int $shipmentId): ?array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null || (string) ($row->carrier ?? '') !== self::CARRIER) {
            return null;
        }
        if ((string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return null;
        }
        $ref = trim((string) ($row->label_ref ?? ''));
        if ($ref === '') {
            return null;
        }
        $label = (new CarrierLabelStore(self::CARRIER))->read($ref);
        if ($label === null || $label === '') {
            return null;
        }
        return ['shipment_id' => $shipmentId, 'source' => 'stored', 'label_ref' => $ref, 'label' => $label];
    }

    // ---- pure snapshot validation + payload mapping (unit-testable, no WordPress) -------------------

    /**
     * @param array<string,mixed> $snapshot
     * @return array{ok:bool,errors:list<string>,payload:array<string,mixed>}
     */
    public static function build(array $snapshot): array
    {
        $errors = self::validate($snapshot);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values($errors), 'payload' => []];
        }
        return ['ok' => true, 'errors' => [], 'payload' => self::buildPayload($snapshot)];
    }

    /**
     * Validate a normalized snapshot against the documented limits (04_FOXPOST §5, §4 op 1); returns
     * stable machine codes, never throws, so the caller records a LOCAL_ERROR before any dispatch.
     *
     * @param array<string,mixed> $snapshot
     * @return list<string>
     */
    public static function validate(array $snapshot): array
    {
        $errors = [];

        if (trim((string) ($snapshot['idempotency_key'] ?? '')) === '') {
            $errors[] = 'missing_idempotency_key';
        }

        $recipient = (array) ($snapshot['recipient'] ?? []);
        $name = trim((string) ($recipient['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'MISSING_RECIPIENT_NAME';
        } elseif (strlen($name) > 150) {
            $errors[] = 'INVALID_NAME';
        }

        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email === '') {
            $errors[] = 'MISSING_RECIPIENT_EMAIL';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'INVALID_EMAIL';
        }

        $phone = trim((string) ($recipient['phone'] ?? ''));
        $phoneValidation = (bool) ($snapshot['phone_validation'] ?? true);
        if ($phone === '') {
            $errors[] = 'MISSING_RECIPIENT_PHONE';
        } elseif ($phoneValidation && !self::isHuMobile($phone)) {
            $errors[] = 'INVALID_PHONE';
        }

        $deliveryMode = strtoupper(trim((string) ($snapshot['delivery_mode'] ?? '')));
        if (!in_array($deliveryMode, self::DELIVERY_MODES, true)) {
            $errors[] = 'INVALID_DELIVERY_MODE';
        }

        if ($deliveryMode === 'APM') {
            if (trim((string) ($snapshot['destination'] ?? '')) === '') {
                $errors[] = 'INVALID_APM_ID';
            }
            $uniqueBarcode = trim((string) ($snapshot['unique_barcode'] ?? ''));
            if (strlen($uniqueBarcode) > 20) {
                $errors[] = 'INVALID_FORMAT'; // uniqueBarcode max 20 (04_FOXPOST §5)
            }
        }

        if ($deliveryMode === 'HD') {
            $city = trim((string) ($recipient['city'] ?? ''));
            $zip = trim((string) ($recipient['zip'] ?? ''));
            $address = trim((string) ($recipient['address'] ?? ''));
            if ($city === '') {
                $errors[] = 'MISSING_RECIPIENT_CITY';
            } elseif (strlen($city) > 25) {
                // OpenAPI recipientCity maxLength 25 (machine contract; prose §5 says 1..50 — spec wins).
                $errors[] = 'INVALID_FORMAT';
            }
            if ($zip === '') {
                $errors[] = 'MISSING_RECIPIENT_ZIP';
            } elseif (!self::isValidHuZip($zip)) {
                $errors[] = 'INVALID_ZIP'; // guide: 1000..9999
            }
            if ($address === '') {
                $errors[] = 'MISSING_RECIPIENT_ADDRESS';
            } elseif (strlen($address) > 150) {
                $errors[] = 'INVALID_FORMAT';
            }
        }

        $country = strtoupper(trim((string) ($recipient['country'] ?? 'HU')));
        if ($country === '') {
            $country = 'HU';
        }
        if (strlen($country) > 3) {
            $errors[] = 'INVALID_FORMAT';
        }

        // Size: optional at creation (guide: not enforced, final size assigned by warehouse), but an
        // explicit value must be one of XS/S/M/L/XL (FX-U10 spec-defect aware).
        $size = self::normalizeSize((string) ($snapshot['size'] ?? ''));
        if ($size !== '' && !in_array($size, self::SIZES, true)) {
            $errors[] = 'INVALID_SIZE';
        }

        // COD: integer 0..1 000 000 (04_FOXPOST §5; error INVALID_COD). CodValueLimits holds the shared rule.
        $cod = (int) ($snapshot['cod_minor'] ?? 0);
        $limit = CodValueLimits::check(self::CARRIER, $cod);
        if (!$limit['ok'] || $cod < self::COD_MIN || $cod > self::COD_MAX) {
            $errors[] = 'INVALID_COD';
        }

        $comment = (string) ($snapshot['comment'] ?? '');
        if (strlen($comment) > 50) {
            $errors[] = 'INVALID_FORMAT';
        }
        $refCode = (string) ($snapshot['ref_code'] ?? '');
        if (strlen($refCode) > 30) {
            $errors[] = 'INVALID_FORMAT';
        }
        $deliveryNote = (string) ($snapshot['delivery_note'] ?? '');
        if (strlen($deliveryNote) > 50) {
            $errors[] = 'INVALID_FORMAT';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Build the exact `CreateParcelRequest` for one parcel (04_FOXPOST §4 op 1). APM → `destination`
     * (+ optional `uniqueBarcode`); HD → `recipientCity`/`recipientZip`/`recipientAddress` (+ optional
     * `fragile`/`deliveryNote`). The caller wraps it in a one-element batch.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function buildPayload(array $snapshot): array
    {
        $recipient = (array) ($snapshot['recipient'] ?? []);
        $deliveryMode = strtoupper(trim((string) ($snapshot['delivery_mode'] ?? 'HD')));
        $country = strtoupper(trim((string) ($recipient['country'] ?? 'HU')));
        if ($country === '') {
            $country = 'HU';
        }
        $size = self::normalizeSize((string) ($snapshot['size'] ?? ''));

        $payload = [
            'recipientName' => (string) ($recipient['name'] ?? ''),
            'recipientPhone' => (string) ($recipient['phone'] ?? ''),
            'recipientEmail' => (string) ($recipient['email'] ?? ''),
            'recipientCountry' => $country,
            'cod' => (int) ($snapshot['cod_minor'] ?? 0),
        ];
        if ($size !== '') {
            $payload['size'] = $size;
        }
        $comment = (string) ($snapshot['comment'] ?? '');
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }
        $refCode = (string) ($snapshot['ref_code'] ?? '');
        if ($refCode !== '') {
            $payload['refCode'] = $refCode;
        }

        if ($deliveryMode === 'APM') {
            $payload['destination'] = (string) ($snapshot['destination'] ?? '');
            $uniqueBarcode = trim((string) ($snapshot['unique_barcode'] ?? ''));
            if ($uniqueBarcode !== '') {
                $payload['uniqueBarcode'] = $uniqueBarcode;
            }
        } else {
            $payload['recipientCity'] = (string) ($recipient['city'] ?? '');
            $payload['recipientZip'] = (string) ($recipient['zip'] ?? '');
            $payload['recipientAddress'] = (string) ($recipient['address'] ?? '');
            if (!empty($snapshot['fragile'])) {
                $payload['fragile'] = true;
            }
            $deliveryNote = (string) ($snapshot['delivery_note'] ?? '');
            if ($deliveryNote !== '') {
                $payload['deliveryNote'] = $deliveryNote;
            }
        }

        return $payload;
    }

    /**
     * Retry allowance (04_FOXPOST §7): 2 only for APM with a pre-generated uniqueBarcode (partial dedupe
     * via DUPLICATED_UNIQUE_BARCODE); HD → 1 because a repeat creates a duplicate parcel.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function maxAttempts(array $snapshot): int
    {
        $isApm = strtoupper(trim((string) ($snapshot['delivery_mode'] ?? ''))) === 'APM';
        $hasUnique = trim((string) ($snapshot['unique_barcode'] ?? '')) !== '';
        return ($isApm && $hasUnique) ? 2 : 1;
    }

    /**
     * Parse `FieldError[]` from a `CreateResponse.parcels[]` entry (or a raw `errors` value that may be a
     * JSON string). Returns `list<array{field:string,message:string}>`.
     *
     * @param array<string,mixed> $parcel
     * @return list<array{field:string,message:string}>
     */
    public static function parseFieldErrors(array $parcel): array
    {
        $raw = $parcel['errors'] ?? [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $errors = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $field = (string) ($entry['field'] ?? '');
                $message = (string) ($entry['message'] ?? $entry['error'] ?? '');
            } else {
                $field = '';
                $message = is_scalar($entry) ? (string) $entry : '';
            }
            if ($field !== '' || $message !== '') {
                $errors[] = ['field' => $field, 'message' => $message !== '' ? $message : 'ERROR'];
            }
        }
        return $errors;
    }

    /** Guide §6.1: HU mobile `^(\+36|36)(20|30|31|70|50|51)\d{7}$` (can be disabled by agreement). */
    public static function isHuMobile(string $phone): bool
    {
        return (bool) preg_match(self::HU_PHONE_REGEX, trim($phone));
    }

    /** Guide §6.1 / error INVALID_ZIP: HU zip is 1000..9999. */
    public static function isValidHuZip(string $zip): bool
    {
        $zip = trim($zip);
        if (!preg_match('/^\d{4}$/', $zip)) {
            return false;
        }
        $value = (int) $zip;
        return $value >= 1000 && $value <= 9999;
    }

    /** FX-U10: spec `size` enum is a single string defect; normalize case and trim. */
    public static function normalizeSize(string $size): string
    {
        return strtoupper(trim($size));
    }

    // ---- snapshot construction (FluentCart boundary) -------------------------------------------------

    /** @return array<string,mixed>|null */
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

            $pickup = $this->pickupPoint($order);
            $carrierMeta = strtolower(trim((string) $this->meta($order, FoxpostModule::META_CARRIER, '')));
            $modeMeta = strtoupper(trim((string) $this->meta($order, FoxpostModule::META_DELIVERY_MODE, '')));
            $pointId = (string) ($pickup['point_id'] ?? '');
            if ($carrierMeta !== '' && $carrierMeta !== self::CARRIER) {
                // The order's selected pickup carrier is not Foxpost; do not emit an APM destination.
                $modeMeta = 'HD';
                $pointId = '';
            } elseif (!in_array($modeMeta, self::DELIVERY_MODES, true)) {
                $modeMeta = $pointId !== '' ? 'APM' : strtoupper((string) FoxpostModule::setting('delivery_mode', 'HD'));
            }
            if (!in_array($modeMeta, self::DELIVERY_MODES, true)) {
                $modeMeta = 'HD';
            }

            $uniqueBarcode = $modeMeta === 'APM' ? $this->uniqueBarcode($order) : '';

            return [
                'order_id' => (int) ($order->id ?? 0),
                'idempotency_key' => 'fch:' . (int) ($order->id ?? 0) . ':' . self::CARRIER . ':label_create:' . $sequence,
                'mode' => $this->settings->mode(),
                'delivery_mode' => $modeMeta,
                'destination' => $modeMeta === 'APM' ? $pointId : '',
                'unique_barcode' => $uniqueBarcode,
                'ref_code' => substr('FCH-' . (int) ($order->id ?? 0), 0, 30),
                'recipient' => [
                    'name' => (string) ($address->name ?? ''),
                    'phone' => $phone,
                    'email' => $email,
                    'country' => 'HU',
                    'city' => (string) ($address->city ?? ''),
                    'zip' => (string) ($address->postcode ?? ''),
                    'address' => trim((string) ($address->address_1 ?? '') . ' ' . (string) ($address->address_2 ?? '')),
                ],
                'cod_minor' => $this->codAmount($order),
                'currency' => strtoupper((string) ($order->currency ?? 'HUF')),
                'size' => self::normalizeSize((string) FoxpostModule::setting('default_size', 'M')),
                'comment' => (string) FoxpostModule::setting('comment', ''),
                'fragile' => (bool) FoxpostModule::setting('fragile', false),
                'delivery_note' => (string) FoxpostModule::setting('delivery_note', ''),
                'phone_validation' => (bool) FoxpostModule::setting('phone_validation', true),
            ];
        } catch (\Throwable $e) {
            do_action('fch/foxpost/snapshot_failed', (int) ($order->id ?? 0), $e);
            return null;
        }
    }

    /**
     * Deterministic, order-scoped unique barcode for APM (≤20 chars; FX-U7 requires it to be generated and
     * persisted before the call). Default prefix has ≥4 letters per the guide's hybrid recommendation.
     */
    /**
     * `removeParcel` DELETE /api/parcel/{barcode} — ledger-bound cancellation dispatcher (W4 logistics).
     * Documented: deletable only while the parcel is in `CREATE` state; repeating a delete after a timeout
     * is a no-op (04 §6.1/§7). A 400 is an explicit refusal; a timeout/5xx resolves through tracking
     * read-back (404 = absent = deleted), never a second delete.
     *
     * @param object $ledgerRow shipment_cancel ledger row
     */
    public function dispatchCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::localError('MISSING_SHIPMENT_ROW', 'A szállítmánysor nem található.');
        }
        $barcode = trim((string) ($row->external_shipment_id ?? $row->barcode ?? $row->tracking_number ?? ''));
        if ($barcode === '') {
            return ProviderCallResult::localError('NO_BARCODE', 'Nincs visszavonható Foxpost vonalkód.');
        }
        $client = $this->boundClient($ledgerRow);
        if ($client === null) {
            return ProviderCallResult::localError('MISSING_CREDENTIALS', 'A visszavonáshoz az eredeti Foxpost fiók (mód/fiók) kulcsa nem érhető el.');
        }

        $request = ['operation' => 'removeParcel', 'barcode' => $barcode];
        $response = $client->deleteParcel($barcode);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->isSuccess()) {
            return self::cancelSuccess($request, $barcode, 'cancelled');
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('FOXPOST_HTTP_' . $response->status, $request);
        }

        // Documented 400: only CREATE can be deleted. Prove via tracking whether the parcel is gone.
        $exists = $this->readBackExists($client, $barcode);
        if ($exists === false) {
            return self::cancelSuccess($request, $barcode, 'already_deleted');
        }
        if ($exists === true) {
            return ProviderCallResult::rejected(
                'FOXPOST_CANCEL_REJECTED_ONLY_CREATE',
                'A küldemény már nem CREATE állapotú, ezért a Foxpost nem törölhető. Feladott csomag visszavonása a szolgáltatónál indítható (visszaküldés).',
                $request
            );
        }
        return ProviderCallResult::uncertain('FOXPOST_CANCEL_UNPROVEN', $request, ['http' => $response->status]);
    }

    /** Read-back: tracking 404 proves absence; a present tracking keeps the row UNCERTAIN (never rejects). */
    public function reconcileCancel(object $ledgerRow): ProviderCallResult
    {
        $row = $this->shipments->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($row === null) {
            return ProviderCallResult::uncertain('MISSING_SHIPMENT_ROW');
        }
        $barcode = trim((string) ($row->external_shipment_id ?? $row->barcode ?? $row->tracking_number ?? ''));
        if ($barcode === '') {
            return ProviderCallResult::uncertain('NO_BARCODE');
        }
        $client = $this->boundClient($ledgerRow);
        if ($client === null) {
            return ProviderCallResult::uncertain('MISSING_CREDENTIALS');
        }
        $request = ['operation' => 'getTracking', 'barcode' => $barcode];
        $exists = $this->readBackExists($client, $barcode);
        if ($exists === false) {
            return self::cancelSuccess($request, $barcode, 'cancelled');
        }
        if ($exists === true) {
            return ProviderCallResult::uncertain('QUERY_STILL_EXISTS_NOT_FINAL', $request);
        }
        return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request);
    }

    /** @return array<string,mixed> */
    private static function cancelSuccess(array $request, string $barcode, string $state): ProviderCallResult
    {
        return ProviderCallResult::success([
            'external_id' => $barcode,
            'external_ref2' => $barcode,
            'barcode' => $barcode,
            'provider_doc_type' => 'cancellation',
            'cancel_state' => $state,
        ], $request, ['barcode' => $barcode, 'state' => $state]);
    }

    /** true = present, false = proved absent, null = cannot tell. */
    private function readBackExists(FoxpostClient $client, string $barcode): ?bool
    {
        $response = $client->getTracks($barcode);
        if ($response->error !== null || $response->status === 0 || $response->status >= 500) {
            return null;
        }
        if ($response->status === 404) {
            return false;
        }
        if (!$response->isSuccess()) {
            return null;
        }
        return true;
    }

    /** Credentials of the ORIGINAL shipment binding (mode/account from the ledger row). */
    private function boundClient(object $ledgerRow): ?FoxpostClient
    {
        $mode = (string) $ledgerRow->mode;
        $account = (string) $ledgerRow->account_id;
        $credentials = new \FluentCartHungary\Core\Settings\CredentialStore();
        $username = (string) ($credentials->get(self::PROVIDER, $mode, $account, 'username') ?? '');
        $password = (string) ($credentials->get(self::PROVIDER, $mode, $account, 'password') ?? '');
        $apiKey = (string) ($credentials->get(self::PROVIDER, $mode, $account, 'api_key') ?? '');
        if (trim($username) === '' || trim($password) === '' || trim($apiKey) === '') {
            return null;
        }
        return new FoxpostClient($mode, $username, $password, $apiKey);
    }

    private function uniqueBarcode(object $order): string
    {
        $meta = trim((string) $this->meta($order, FoxpostModule::META_UNIQUE_BARCODE, ''));
        if ($meta !== '') {
            return substr($meta, 0, 20);
        }
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) FoxpostModule::setting('unique_barcode_prefix', 'FCHU')));
        if ($prefix === '') {
            $prefix = 'FCHU';
        }
        return substr($prefix . (int) ($order->id ?? 0), 0, 20);
    }

    // ---- provider response interpretation ------------------------------------------------------------

    /**
     * Interpret the `setParcels_1` response (CreateResponse{valid,parcels[]}, 04_FOXPOST §4 op 1, §7).
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $request secret-free request summary
     */
    private function interpretCreateResponse(Response $response, array $snapshot, array $request): ProviderCallResult
    {
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }
        if ($response->status === 401) {
            return ProviderCallResult::rejected('WRONG_USERNAME_OR_PASSWORD', 'Foxpost rejected the credentials.', $request);
        }
        if ($response->status >= 500) {
            return ProviderCallResult::uncertain('FOXPOST_HTTP_' . $response->status, $request);
        }

        $data = $response->json();
        if ($data === []) {
            return ProviderCallResult::uncertain('FOXPOST_EMPTY_RESPONSE', $request, ['status' => $response->status]);
        }
        if ($response->status === 400) {
            return ProviderCallResult::rejected((string) ($data['error'] ?? 'FOXPOST_HTTP_400'), 'Foxpost rejected the parcel data.', $request);
        }

        $parcels = is_array($data['parcels'] ?? null) ? array_values(array_filter($data['parcels'], 'is_array')) : [];
        $parcel = $parcels[0] ?? null;
        $errors = $parcel !== null ? self::parseFieldErrors($parcel) : self::parseFieldErrors($data);

        if ($errors !== []) {
            $messages = array_map(static fn(array $e): string => $e['message'], $errors);
            $isApm = strtoupper((string) ($snapshot['delivery_mode'] ?? '')) === 'APM';
            $uniqueBarcode = trim((string) ($snapshot['unique_barcode'] ?? ''));

            if (in_array('DUPLICATED_UNIQUE_BARCODE', $messages, true) && $isApm && $uniqueBarcode !== '') {
                // Partial dedupe (04_FOXPOST §7): the pre-generated uniqueBarcode already exists, proving a
                // previous create for this order. Treat as already-created; do not create a second parcel.
                $idempotencyKey = (string) ($snapshot['idempotency_key'] ?? '');
                $fields = [
                    'external_id' => $uniqueBarcode,
                    'external_ref2' => $uniqueBarcode,
                    'external_ref3' => $idempotencyKey,
                    'barcode' => $uniqueBarcode,
                    'tracking_number' => $uniqueBarcode,
                    'carrier_reference' => '',
                    'uniqueBarcode' => $uniqueBarcode,
                    'already_created' => true,
                    'parcels' => [['uniqueBarcode' => $uniqueBarcode, 'sendType' => 'APM']],
                    'package_count' => 1,
                ];
                return ProviderCallResult::success($fields, $request, ['duplicate' => true, 'message' => 'DUPLICATED_UNIQUE_BARCODE']);
            }

            return ProviderCallResult::rejected($messages[0], 'Foxpost field validation failed.', $request, ['errors' => $errors]);
        }

        if ($parcel === null) {
            return ProviderCallResult::uncertain('FOXPOST_NO_PARCEL', $request, ['status' => $response->status]);
        }

        $clFoxId = trim((string) ($parcel['clFoxId'] ?? $parcel['barcode'] ?? ''));
        $uniqueBarcode = trim((string) ($parcel['uniqueBarcode'] ?? $snapshot['unique_barcode'] ?? ''));
        if ($clFoxId === '' && $uniqueBarcode === '') {
            return ProviderCallResult::uncertain('FOXPOST_NO_PARCEL_ID', $request);
        }

        $barcodeTof = trim((string) ($parcel['barcodeTof'] ?? ''));
        $sendType = strtoupper((string) ($parcel['sendType'] ?? ($snapshot['delivery_mode'] ?? '')));
        $sendCode = (string) ($parcel['sendCode'] ?? '');
        $barcode = $clFoxId !== '' ? $clFoxId : $uniqueBarcode;
        // Tracking uses clFox id or uniqueBarcode for APM; the HD partner id (barcodeTof) for HD (§4 op 13).
        $tracking = ($sendType === 'HD' && $barcodeTof !== '') ? $barcodeTof : $barcode;

        $fields = [
            'external_id' => $clFoxId !== '' ? $clFoxId : $uniqueBarcode,
            'external_ref2' => $uniqueBarcode,
            'external_ref3' => (string) ($snapshot['idempotency_key'] ?? ''),
            'barcode' => $barcode,
            'tracking_number' => $tracking,
            'carrier_reference' => $sendCode,
            'clFoxId' => $clFoxId,
            'barcodeTof' => $barcodeTof,
            'sendCode' => $sendCode,
            'sendType' => $sendType,
            'uniqueBarcode' => $uniqueBarcode,
            'parcels' => [[
                'clFoxId' => $clFoxId,
                'barcodeTof' => $barcodeTof,
                'sendCode' => $sendCode,
                'uniqueBarcode' => $uniqueBarcode,
                'sendType' => $sendType,
            ]],
            'package_count' => 1,
        ];

        return ProviderCallResult::success($fields, $request, ['clFoxId' => $clFoxId, 'trackingNumber' => $tracking]);
    }

    /** Persist a proved create outcome onto the shipment mirror (05_DATA_SCHEMA §7.4). @param array<string,mixed> $fields */
    private function finalizeConfirmed(int $shipmentId, array $fields): void
    {
        self::ensureShipmentInProgress($this->shipments, $shipmentId);

        $extra = [
            'operation_id' => (int) ($fields['operation_id'] ?? 0),
            'external_shipment_id' => (string) ($fields['external_id'] ?? ''),
            'external_ref2' => (string) ($fields['external_ref2'] ?? ''),
            'external_ref3' => (string) ($fields['external_ref3'] ?? ''),
            'barcode' => (string) ($fields['barcode'] ?? ''),
            'tracking_number' => (string) ($fields['tracking_number'] ?? ''),
            'carrier_reference' => (string) ($fields['carrier_reference'] ?? ''),
            'parcels' => $this->jsonEncode((array) ($fields['parcels'] ?? [])),
            'package_count' => max(1, count((array) ($fields['parcels'] ?? []))),
        ];

        $this->shipments->markConfirmed($shipmentId, $extra);
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

    private function client(): FoxpostClient
    {
        return new FoxpostClient($this->settings->mode());
    }

    // ---- snapshot helpers ----------------------------------------------------------------------------

    /**
     * COD amount in domain minor units (HUF = forint) for a FluentCart COD order
     * (payment_method `offline_payment`). FluentCart stores amount×100 for HUF; the boundary conversion
     * is mandatory here so `cod` is the HUF integer the API expects (04_FOXPOST §5).
     */
    private function codAmount(object $order): int
    {
        if ((string) ($order->payment_method ?? '') !== self::COD_METHOD) {
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

    /** Read an order meta value (constraints §2: pickup snapshot keys are `_fch_*`). */
    private function meta(object $order, string $key, mixed $default = null): mixed
    {
        if (!method_exists($order, 'getMeta')) {
            return $default;
        }
        $value = $order->getMeta($key, $default);
        return $value ?? $default;
    }

    /** @return array<string,mixed> pickup-point snapshot from `_fch_pickup_point`. */
    private function pickupPoint(object $order): array
    {
        $raw = $this->meta($order, FoxpostModule::META_PICKUP_POINT, []);
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
            'point_id' => (string) ($raw['point_id'] ?? $raw['id'] ?? $raw['operator_id'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'address' => (string) ($raw['address'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function pickupSnapshotForRow(array $snapshot): ?array
    {
        if (strtoupper((string) ($snapshot['delivery_mode'] ?? '')) !== 'APM') {
            return null;
        }
        $destination = trim((string) ($snapshot['destination'] ?? ''));
        if ($destination === '') {
            return null;
        }
        return ['destination' => $destination, 'deliveryMode' => 'APM'];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $payload @return array<string,mixed> */
    private function requestSummary(array $snapshot, array $payload): array
    {
        return [
            'operation' => 'setParcels_1',
            'deliveryMode' => (string) ($snapshot['delivery_mode'] ?? ''),
            'destination' => (string) ($snapshot['destination'] ?? ''),
            'has_unique_barcode' => trim((string) ($snapshot['unique_barcode'] ?? '')) !== '',
            'refCode' => (string) ($payload['refCode'] ?? ''),
            'size' => (string) ($payload['size'] ?? ''),
            'cod_huf' => (int) ($payload['cod'] ?? 0),
            'item_count' => 1,
        ];
    }

    /**
     * Normalize a FluentCart relation/collection/array into a plain list (same contract as
     * MplShipmentService::toList — FC Collection::get() is a key accessor, so toArray/iteration is used).
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
