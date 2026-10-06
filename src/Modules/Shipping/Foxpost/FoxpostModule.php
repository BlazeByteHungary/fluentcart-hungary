<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

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
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\FoxpostPoints;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

/**
 * shipping/foxpost module: wires the label job, tracking job, the reconciler (documented barcode/
 * uniqueBarcode read-back) and the create triggers, and registers its carrier + pickup capabilities.
 *
 * Hook surface (constraints §3; 03_VERIFIED_EXTENSION_POINTS §lifecycle):
 *  - `fluent_cart/order_paid_done` — async paid moment; registration only enqueues a job.
 *  - `fluent_cart/order_status_changed` — typed order-status hook used for COD orders (invariant §1.7:
 *    `paid` must not be the only trigger).
 *  - `fluent_cart/shipping_status_changed[_to_*]` is produced by the tracking path, not consumed here.
 *  - The reconciler is registered only because Foxpost documents a barcode/uniqueBarcode tracking lookup
 *    (04_FOXPOST §4 op 13, §7); HD create-timeouts stay manual.
 *
 * Credentials: username/password/api_key via CredentialStore (never logged, never echoed).
 * Pickup meta keys: `_fch_carrier`, `_fch_delivery_mode`, `_fch_pickup_point` (constraints §2).
 *
 * Probe (FX-U8 / FX-U3): ZPL is account-dependent and default-off, `isPortrait` is test-spec-only and
 * default-off, and the push callback contract is MISSING_DOC → PULL only.
 */
final class FoxpostModule implements ModuleInterface
{
    public const ID = 'shipping/foxpost';
    public const MODULE = 'shipping';
    public const PROVIDER = 'foxpost';
    public const CARRIER = 'foxpost';
    public const SETTINGS_SCOPE = 'foxpost';

    /** FluentCart Core COD payment-method slug: `offline_payment`. */
    public const COD_METHOD = 'offline_payment';

    public const META_CARRIER = '_fch_carrier';
    public const META_DELIVERY_MODE = '_fch_delivery_mode';
    public const META_PICKUP_POINT = '_fch_pickup_point';
    public const META_UNIQUE_BARCODE = '_fch_foxpost_barcode';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Foxpost', 'fluentcart-hungary');
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
            __('Foxpost', 'fluentcart-hungary'),
            __('Foxpost címke', 'fluentcart-hungary'),
            CreateLabelJob::HOOK,
            SyncTrackingJob::HOOK,
            self::SETTINGS_SCOPE,
            self::ID
        );

        PickupFeedRegistry::register(new FoxpostPoints());

        // Register a reconciler only because a barcode/uniqueBarcode lookup is documented (04_FOXPOST §7).
        if (FoxpostReconciler::supportsLookup()) {
            ReconcilerRegistry::register(self::PROVIDER, new FoxpostReconciler());

            // W4 logistics lifecycle: ledger-routed external cancellation (removeParcel), tracking read-back.
            CancelDispatcherRegistry::register(self::CARRIER, static function (object $ledgerRow): ProviderCallResult {
                return (new FoxpostParcelService())->dispatchCancel($ledgerRow);
            });
        }
    }

    /**
     * @param mixed $data hook payload
     */
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

    /**
     * COD / non-paid trigger: on the verified order-status hook for `processing`/`completed`, only when the
     * order is a FluentCart COD order and `create_on` allows it (invariant §1.7).
     *
     * @param mixed $data hook payload
     */
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

    public function probe(): array
    {
        $fluentCartActive = defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\Models\\Order');
        $hasCredentials = self::hasCredentials();
        $zpl = (bool) self::setting('zpl_enabled', false);
        $isPortrait = (bool) self::setting('label_is_portrait', false);

        $unknowns = [
            'zpl' => $zpl ? 'enabled_by_setting' : 'account_dependent',
            'isPortrait' => $isPortrait ? 'enabled_by_setting' : 'test_spec_only',
            'push_callback' => 'MISSING_DOC',
            'international_destinations' => 'FX-U1_unknown',
        ];

        if ($fluentCartActive && $hasCredentials) {
            return [
                'ok' => true,
                'message' => 'foxpost ready; zpl=' . $unknowns['zpl'] . ', isPortrait=' . $unknowns['isPortrait'] . ', push=MISSING_DOC',
                'unknowns' => $unknowns,
            ];
        }

        $missing = [];
        if (!$fluentCartActive) {
            $missing[] = 'fluentcart';
        }
        if (!self::hasUsername()) {
            $missing[] = 'username';
        }
        if (!self::hasPassword()) {
            $missing[] = 'password';
        }
        if (!self::hasApiKey()) {
            $missing[] = 'api_key';
        }

        return [
            'ok' => false,
            'message' => 'degraded: missing ' . implode(', ', $missing),
            'unknowns' => $unknowns,
        ];
    }

    // ---- shared, non-secret settings accessors -------------------------------------------------------

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

    public static function username(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'username');
    }

    public static function password(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'password');
    }

    public static function apiKey(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'api_key');
    }

    public static function hasUsername(): bool
    {
        return trim((string) self::username()) !== '';
    }

    public static function hasPassword(): bool
    {
        return trim((string) self::password()) !== '';
    }

    public static function hasApiKey(): bool
    {
        return trim((string) self::apiKey()) !== '';
    }

    public static function hasCredentials(): bool
    {
        return self::hasUsername() && self::hasPassword() && self::hasApiKey();
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

    /** FluentCart Order or null. FC symbol is only referenced inside the method (constraints §3, spike B1). */
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
     * Enqueue the label job once per order/shipment. Duplicate triggers collapse on the stable job key; an
     * already-created/active shipment suppresses a second label (concurrency: one shipment per ledger key).
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
            sprintf('fch:%d:foxpost_label_job:%d', $orderId, $shipmentNo),
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
