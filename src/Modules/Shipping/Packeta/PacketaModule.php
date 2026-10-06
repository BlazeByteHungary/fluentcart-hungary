<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CancelDispatcherRegistry;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PacketaPoints;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

/**
 * shipping/packeta module: wires the label job, tracking job, reconciler, pickup feed and the create
 * triggers. Provider contract: 04_PACKETA_CONTRACT.md.
 *
 * Hook surface (constraints §3; 03_VERIFIED_EXTENSION_POINTS §lifecycle):
 *  - `fluent_cart/order_paid_done` — async paid moment, payload `['order','transaction','customer'…]`.
 *  - `fluent_cart/order_status_changed` — typed status hook (`processing`/`completed`) used for COD,
 *    because `paid` must not be the only trigger (invariant §1.7).
 *  - Reconciler registered under provider 'packeta': a lost create response is resolved by
 *    `packetInfo` read-back, never by re-POSTing `createPacket` (invariant §1.4; 04_PACKETA §7).
 *
 * G1 (04_UNKNOWNS_AND_BLOCKERS): Packeta has **no sandbox**; testing uses production credentials and a
 * dedicated testing sender. `probe()` therefore gates on live mode and always reports this explicitly.
 *
 * Credentials (CredentialStore field names only): `api_password` (32 chars, every API method),
 * `api_key` (16 chars, widget + PUDO feeds), `signing_key` (push-tracking HMAC). They are never logged.
 */
final class PacketaModule implements ModuleInterface
{
    public const ID = 'shipping/packeta';
    public const MODULE = 'shipping';
    public const PROVIDER = 'packeta';
    public const CARRIER = 'packeta';
    public const SETTINGS_SCOPE = 'packeta';

    /** FluentCart Core COD payment-method slug: `offline_payment` (Cod.php:40/91). */
    public const COD_METHOD = 'offline_payment';

    /** Order-meta keys (constraints §2 pickup snapshot). */
    public const META_CARRIER = '_fch_carrier';
    public const META_DELIVERY_MODE = '_fch_delivery_mode';
    public const META_PICKUP_POINT = '_fch_pickup_point';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Packeta', 'fluentcart-hungary');
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

        ReconcilerRegistry::register(self::PROVIDER, new PacketaReconciler());

        // W4 logistics lifecycle: ledger-routed external cancellation (PKT-005), read-back via packetStatus.
        CancelDispatcherRegistry::register(self::CARRIER, static function (object $ledgerRow): ProviderCallResult {
            return (new PacketaPacketService())->dispatchCancel($ledgerRow);
        });

        CarrierRegistry::register(
            self::CARRIER,
            __('Packeta', 'fluentcart-hungary'),
            __('Packeta címke', 'fluentcart-hungary'),
            CreateLabelJob::HOOK,
            SyncTrackingJob::HOOK,
            self::SETTINGS_SCOPE,
            self::ID
        );

        PickupFeedRegistry::register(new PacketaPoints());
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
     * Explicit G1 report: Packeta has no sandbox, so the module only claims readiness in `live` mode
     * with an api_password; a dedicated testing sender is required for any production test.
     */
    public function probe(): array
    {
        $fluentCartActive = defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\Models\\Order');
        $hasPassword = trim((string) self::apiPassword()) !== '';
        $hasApiKey = trim((string) self::apiKey()) !== '';
        $mode = self::mode();

        $gate = 'no sandbox — prod-only gating (G1): use live mode with a dedicated testing sender';

        if (!$fluentCartActive) {
            return ['ok' => false, 'message' => 'degraded: missing fluentcart; ' . $gate];
        }
        if (!$hasPassword) {
            return ['ok' => false, 'message' => 'degraded: missing api_password; ' . $gate];
        }
        if ($mode !== 'live') {
            return ['ok' => false, 'message' => 'degraded: mode must be live; ' . $gate];
        }
        if (!$hasApiKey) {
            return ['ok' => true, 'message' => 'packeta label API ready; pickup feed disabled (missing api_key); ' . $gate];
        }

        return ['ok' => true, 'message' => 'packeta ready; ' . $gate];
    }

    // ---- settings / credentials ---------------------------------------------------------------------

    public static function setting(string $key, mixed $default = null): mixed
    {
        return (new ModuleSettings())->get(self::SETTINGS_SCOPE, $key, $default);
    }

    public static function mode(): string
    {
        return (new ModuleSettings())->mode();
    }

    public static function accountId(): string
    {
        return (new ModuleSettings())->accountId(self::SETTINGS_SCOPE);
    }

    /** 32-char apiPassword used by every API method (04 §2.1). */
    public static function apiPassword(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'api_password');
    }

    /** 16-char apiKey used by the widget + PUDO feeds (never the apiPassword — 04 §2.1). */
    public static function apiKey(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'api_key');
    }

    /** Signing Key for the push-tracking webhook HMAC (04 §6). */
    public static function signingKey(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'signing_key');
    }

    /** Label creation needs only the apiPassword. */
    public static function hasCredentials(): bool
    {
        return trim((string) self::apiPassword()) !== '';
    }

    /** Pickup feed needs the apiKey. */
    public static function hasApiKey(): bool
    {
        return trim((string) self::apiKey()) !== '';
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

    /** FluentCart Order or null (FC symbol only referenced inside the method — constraints §3). */
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
     * Enqueue the label job once per order/shipment. Duplicate triggers collapse on the stable job key;
     * an already-created/active shipment suppresses a second label.
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
            sprintf('fch:%d:packeta:label_job:%d', $orderId, $shipmentNo),
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
