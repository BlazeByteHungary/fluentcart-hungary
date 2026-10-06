<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CancelDispatcherRegistry;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;
use FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\DpdPoints;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

/**
 * shipping/dpd module: wires the label job, tracking job and the two create triggers.
 *
 * Hook surface (constraints §3; same verified lifecycle as MplModule):
 *  - `fluent_cart/order_paid_done` (async paid moment) and
 *  - `fluent_cart/order_status_changed` (`processing`/`completed`) for COD orders (invariant §1.7).
 *
 * Explicitly NOT registered (documented blockers):
 *  - **No reconciler** — there is no documented query-by-reference endpoint (04_DPD §7, DPD-906, DPD-U8),
 *    so an UNCERTAIN create stays on the manual reconciliation queue (see {@see DpdReconciler}).
 *  - **JWT acquisition** is undocumented (G10/DPD-U1): the bearer is provisioned into the CredentialStore
 *    key `token`; `probe()` reports the `jwt_scheme_unknown` block when it is missing.
 *  - **PUDO directory** is MISSING_DOC (DPD-U3): the pickup feed serves only operator-supplied static data
 *    ({@see DpdPoints}), never invented inventory.
 *
 * Pickup snapshot meta keys (constraints §2): `_fch_pickup_point`, `_fch_carrier`, `_fch_delivery_mode`;
 * the `pudoId` from the snapshot becomes `service.additionalService.pudoId`.
 */
final class DpdModule implements ModuleInterface
{
    public const ID = 'shipping/dpd';
    public const MODULE = 'shipping';
    public const PROVIDER = 'dpd';
    public const CARRIER = 'dpd';
    public const SETTINGS_SCOPE = 'dpd';

    /** FluentCart Core COD payment-method slug: `offline_payment` (Cod.php:40 slug/route; :91 check). */
    public const COD_METHOD = 'offline_payment';

    /** CredentialStore / ModuleSettings keys. */
    public const TOKEN_KEY = 'token';
    public const BU_CODE_KEY = 'bu_code';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('DPD', 'fluentcart-hungary');
    }

    public function requires(): array
    {
        return ['core'];
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    public function registerHooks(): void
    {
        JobRunner::instance()->registerHandler(new CreateLabelJob());
        JobRunner::instance()->registerHandler(new SyncTrackingJob());

        add_action('fluent_cart/order_paid_done', [$this, 'onOrderPaidDone'], 10, 1);
        add_action('fluent_cart/order_status_changed', [$this, 'onOrderStatusChanged'], 10, 1);

        CarrierRegistry::register(
            self::CARRIER,
            'DPD',
            __('DPD címke', 'fluentcart-hungary'),
            CreateLabelJob::HOOK,
            SyncTrackingJob::HOOK,
            self::SETTINGS_SCOPE,
            self::ID
        );
        PickupFeedRegistry::register(new DpdPoints());

        // ACCOUNT_DEPENDENT COD maxima (04_DPD §3.6 / DPD-U11): no built-in numeric cap; settings decide.
        CodValueLimits::register(self::CARRIER, ['min' => 0, 'max' => 0]);

        // No ReconcilerRegistry::register('dpd', …) — see DpdReconciler / DPD-906.
        // W4 logistics lifecycle: ledger-routed external cancellation (DPD-004). No auto read-back exists
        // (the label_create gap is unchanged): an UNCERTAIN cancellation stays on the operator queue.
        CancelDispatcherRegistry::register(self::CARRIER, static function (object $ledgerRow): ProviderCallResult {
            return (new DpdShipmentService())->dispatchCancel($ledgerRow);
        });
    }

    /** @param mixed $data hook payload */
    public function onOrderPaidDone($data): void
    {
        $orderId = self::orderIdFromPayload($data);
        if ($orderId <= 0) {
            return;
        }
        if (!in_array(self::createOn(), ['paid_or_cod', 'paid'], true)) {
            return;
        }
        $this->enqueueCreateLabel($orderId);
    }

    /** @param mixed $data hook payload */
    public function onOrderStatusChanged($data): void
    {
        $orderId = self::orderIdFromPayload($data);
        if ($orderId <= 0) {
            return;
        }
        $newStatus = self::newStatusFromPayload($data);
        if (!in_array($newStatus, ['processing', 'completed'], true)) {
            return;
        }
        if (!in_array(self::createOn(), ['paid_or_cod', 'cod'], true)) {
            return;
        }
        if (!self::isCodOrder($orderId)) {
            return;
        }
        $this->enqueueCreateLabel($orderId);
    }

    /**
     * Capability probe. Reports readiness plus the explicit DPD blockers (matrix ids) so the admin/
     * diagnostics never imply a capability that the contract does not document.
     *
     * @return array{ok:bool,message:string,blocked:list<string>,unknowns:array<string,string>,reconciler:array{registered:bool,reason:string},pickup_feed:string}
     */
    public function probe(): array
    {
        $fluentCartActive = defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\Models\\Order');
        $hasToken = trim((string) self::token()) !== '';
        $hasBuCode = trim((string) self::buCode()) !== '';

        $blocked = [];
        if (!$fluentCartActive) {
            $blocked[] = 'fluentcart';
        }
        if (!$hasToken) {
            // G10 / DPD-U1: the acquisition endpoint/scheme is not documented; token must be provisioned.
            $blocked[] = 'jwt_scheme_unknown';
        }

        $ready = $fluentCartActive && $hasToken;

        return [
            'ok' => $ready,
            'message' => $ready
                ? ('dpd ready' . ($hasBuCode ? '' : ' (bu_code not set: use DPD-BU-ID endpoints only when configured)'))
                : ('degraded: ' . implode(', ', $blocked)),
            'blocked' => $blocked,
            'unknowns' => self::unknowns(),
            'reconciler' => ['registered' => false, 'reason' => DpdReconciler::REASON],
            'pickup_feed' => 'static_option_only',
        ];
    }

    /**
     * The documented DPD unknowns, keyed by matrix id. Pure; used by probe() and the final report.
     *
     * @return array<string,string>
     */
    public static function unknowns(): array
    {
        return [
            'G10' => 'DPD JWT acquisition endpoint/scheme undocumented; bearer must be provisioned manually.',
            'DPD-U1' => 'JWT acquisition (client_id/secret, token URL) not documented.',
            'DPD-U2' => 'Real Back-Office API host unknown (doc shows localhost:8086/nst) — master data unavailable.',
            'DPD-U3' => 'PUDO directory/list API MISSING_DOC — selector cannot be fed from DPD docs.',
            'DPD-U7' => 'shipment.status integer enum unknown — never mapped; STATUSDATA scan codes only.',
            'DPD-U8' => 'No query-by-reference endpoint (matrix DPD-906) — create timeout is a manual queue.',
            'DPD-U9' => 'No live rate/price API — shipping fee is shop-configured.',
            'DPD-U11' => 'COD/insurance max amounts are BU-configured (ACCOUNT_DEPENDENT); settings-driven.',
        ];
    }

    // ---- shared, non-secret settings accessors ------------------------------------------------------

    public static function setting(string $key, mixed $default = null): mixed
    {
        if (!function_exists('get_option')) {
            return $default;
        }
        return (new ModuleSettings())->get(self::SETTINGS_SCOPE, $key, $default);
    }

    /** Optional integer setting; null when unset/non-numeric (CodValueLimits semantics). */
    public static function settingInt(string $key, ?int $default = null): ?int
    {
        $raw = self::setting($key, null);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return is_numeric($raw) ? (int) $raw : $default;
    }

    public static function mode(): string
    {
        if (!function_exists('get_option')) {
            return 'test';
        }
        return (new ModuleSettings())->mode();
    }

    public static function accountId(): string
    {
        if (!function_exists('get_option')) {
            return 'default';
        }
        return (new ModuleSettings())->accountId(self::SETTINGS_SCOPE);
    }

    public static function token(): ?string
    {
        if (!function_exists('get_option')) {
            return null;
        }
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, self::TOKEN_KEY);
    }

    public static function buCode(): ?string
    {
        $value = self::setting(self::BU_CODE_KEY, '');
        return is_scalar($value) ? (string) $value : '';
    }

    /** DPD "credentials" = a provisioned bearer token (bu_code is optional, §2.1). */
    public static function hasCredentials(): bool
    {
        return trim((string) self::token()) !== '';
    }

    /** `create_on` ∈ {paid_or_cod (default), paid, cod}. */
    public static function createOn(): string
    {
        $value = (string) self::setting('create_on', 'paid_or_cod');
        return in_array($value, ['paid_or_cod', 'paid', 'cod'], true) ? $value : 'paid_or_cod';
    }

    public static function isCodOrder(int $orderId): bool
    {
        $order = self::loadOrder($orderId);
        return is_object($order) && (string) ($order->payment_method ?? '') === self::COD_METHOD;
    }

    /** FluentCart Order or null. FC symbol is only referenced inside the method (constraints §3). */
    public static function loadOrder(int $orderId): ?object
    {
        $class = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($class)) {
            return null;
        }
        $order = $class::find($orderId);
        return is_object($order) ? $order : null;
    }

    /**
     * Pickup snapshot from order meta (constraints §2): `_fch_pickup_point` carries the canonical point,
     * `_fch_carrier` and `_fch_delivery_mode` the carrier/mode; `pudoId` is the DPD point id.
     *
     * @return array{carrier:string,delivery_mode:string,point_id:string,name:string,address:string,type:string}
     */
    public static function pickupPoint(object $order): array
    {
        $empty = ['carrier' => '', 'delivery_mode' => '', 'point_id' => '', 'name' => '', 'address' => '', 'type' => ''];
        if (!method_exists($order, 'getMeta')) {
            return $empty;
        }

        $raw = $order->getMeta('_fch_pickup_point', []);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            return $empty;
        }

        $carrier = (string) ($raw['carrier'] ?? $order->getMeta('_fch_carrier', ''));
        if ($carrier !== '' && strtolower($carrier) !== self::CARRIER) {
            return $empty;
        }
        $deliveryMode = strtoupper((string) ($raw['type'] ?? $order->getMeta('_fch_delivery_mode', '')));

        return [
            'carrier' => $carrier !== '' ? $carrier : self::CARRIER,
            'delivery_mode' => $deliveryMode,
            'point_id' => (string) ($raw['point_id'] ?? $raw['pudoId'] ?? $raw['id'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'address' => (string) ($raw['address'] ?? ''),
            'type' => $deliveryMode,
        ];
    }

    /**
     * Enqueue the label job once per order/shipment. Duplicate triggers collapse on the stable job key; an
     * already-created/active shipment suppresses a second label.
     */
    private function enqueueCreateLabel(int $orderId): void
    {
        $order = self::loadOrder($orderId);
        if ($order === null) {
            return;
        }
        $guard = \FluentCartHungary\Core\Shipping\CarrierBinding::guardForCarrier($order, self::CARRIER);
        if (!$guard['allow']) {
            return; // routing gate: only the bound carrier may enqueue (unresolved orders get an admin state)
        }
        if (!self::hasCredentials()) {
            return;
        }

        $mode = self::mode();
        $account = self::accountId();
        $repo = new ShipmentRepository();
        foreach ($repo->listForOrder($orderId) as $row) {
            if ((string) ($row->carrier ?? '') !== self::CARRIER || (string) ($row->mode ?? '') !== $mode) {
                continue;
            }
            $state = (string) ($row->state ?? '');
            if (in_array($state, [
                ExternalOperation::IN_PROGRESS,
                ExternalOperation::EXTERNAL_CONFIRMED,
                ExternalOperation::UNCERTAIN,
                ExternalOperation::AWAITING_RECONCILIATION,
            ], true)) {
                return;
            }
            if ($state === ExternalOperation::SUPERSEDED && trim((string) ($row->cancelled_at ?? '')) !== '') {
                // W4: a withdrawn consignment is closed — a late paid/label event never re-labels it
                // silently; an explicit merchant re-create is a separate, deliberate action.
                return;
            }
        }

        $shipmentNo = $repo->nextShipmentNo($orderId, self::CARRIER, $mode, $account);
        JobRunner::instance()->enqueue(new JobSpec(
            sprintf('fch:%d:dpd:label_job:%d', $orderId, $shipmentNo),
            CreateLabelJob::HOOK,
            ['order_id' => $orderId, 'shipment_no' => $shipmentNo],
            10,
            3,
            self::ID
        ));
    }

    /** @param mixed $data @return int */
    private static function orderIdFromPayload($data): int
    {
        if (is_object($data) && isset($data->order)) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            $order = $data['order'] ?? null;
            if (is_object($order) && isset($order->id)) {
                return (int) $order->id;
            }
            if (is_array($order) && isset($order['id'])) {
                return (int) $order['id'];
            }
            if (isset($data['order_id'])) {
                return (int) $data['order_id'];
            }
        }
        return 0;
    }

    /** @param mixed $data @return string */
    private static function newStatusFromPayload($data): string
    {
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            return (string) ($data['new_status'] ?? '');
        }
        return '';
    }
}
