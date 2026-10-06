<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupCheckoutHandler;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointValidator;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\GlsPoints;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

/**
 * shipping/gls module: wires the label job, tracking job, reconciler, carrier registry, pickup feed and the
 * two create triggers (04_GLS_CONTRACT.md; constraints §3 lifecycle hooks).
 *
 * Hook surface (constraints §3; 03_VERIFIED_EXTENSION_POINTS §lifecycle):
 *  - `fluent_cart/order_paid_done` — async paid moment (registration is an enqueue only);
 *  - `fluent_cart/order_status_changed` — typed order-status hook for COD orders (`processing`/`completed`),
 *    because `paid` must not be the only trigger (invariant §1.7);
 *  - `fluent_cart/checkout/prepare_other_data` — guarded GLS-only pickup snapshot write to order meta
 *    `_fch_pickup_point` + `_fch_carrier` + `_fch_delivery_mode` when the pickup module did not already.
 *  - The GLS reconciler is registered by provider id so a lost create response is resolved by
 *    `GetParcelStatuses`/`GetParcelList` read-back, never by re-sending (invariant §1.4; 04_GLS §7).
 *
 * Credentials (CredentialStore, scope `gls`): keys `username`, `password` (SHA-512 hashed on send, §2.1);
 * the optional non-secret `webshop_engine` setting is required by PrepareLabels/PrintLabels (§2.1).
 */
final class GlsModule implements ModuleInterface
{
    public const ID = 'shipping/gls';
    public const MODULE = 'shipping';
    public const PROVIDER = 'gls';
    public const CARRIER = 'gls';
    public const SETTINGS_SCOPE = 'gls';

    /** FluentCart Core COD payment-method slug `offline_payment` (same value as MplModule). */
    public const COD_METHOD = 'offline_payment';

    /** Contract parts that are UNKNOWN/blocked and degrade gracefully (probe message). */
    private const UNKNOWN_PARTS = [
        'GLS-902 tracking webhook/URL',
        'GLS-903 per-country service matrix',
        'GLS-904 per-country COD ranges',
        'GLS-906 non-XXL weight/dim limits',
        'GLS-023..026 XXL dispatch lists',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('GLS', 'fluentcart-hungary');
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
        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'prepareOtherData'], 30, 1);

        CarrierRegistry::register(
            self::CARRIER,
            __('GLS', 'fluentcart-hungary'),
            __('GLS címke', 'fluentcart-hungary'),
            CreateLabelJob::HOOK,
            SyncTrackingJob::HOOK,
            self::SETTINGS_SCOPE,
            self::ID
        );
        PickupFeedRegistry::register(new GlsPoints());
        ReconcilerRegistry::register(self::PROVIDER, new GlsReconciler());
    }

    // ---- create triggers (mirror MplModule) ---------------------------------------------------------

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

    public function probe(): array
    {
        $fluentCartActive = defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\Models\\Order');
        $hasUsername = trim((string) self::username()) !== '';
        $hasPassword = trim((string) self::password()) !== '';

        if ($fluentCartActive && $hasUsername && $hasPassword) {
            return [
                'ok' => true,
                'message' => 'gls ready; graceful UNKNOWN parts: ' . implode(', ', self::UNKNOWN_PARTS),
            ];
        }

        $missing = [];
        if (!$fluentCartActive) {
            $missing[] = 'fluentcart';
        }
        if (!$hasUsername) {
            $missing[] = 'username';
        }
        if (!$hasPassword) {
            $missing[] = 'password';
        }
        return ['ok' => false, 'message' => 'degraded: missing ' . implode(', ', $missing)];
    }

    // ---- shared, non-secret settings accessors (used by service/tracking/reconciler/jobs) ------------

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

    /** Non-secret webshop engine identifier REQUIRED on PrepareLabels/PrintLabels (04_GLS §2.1). */
    public static function webshopEngine(): string
    {
        $value = trim((string) self::setting('webshop_engine', ''));
        return $value !== '' ? $value : 'FluentCart';
    }

    public static function hasCredentials(): bool
    {
        return trim((string) self::username()) !== '' && trim((string) self::password()) !== '';
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
     * Enqueue the label job once per order/shipment. Duplicate triggers (paid_done + status change) collapse
     * on the stable job key; an already-created/active shipment suppresses a second label.
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
            sprintf('fch:%d:gls:label_job:%d', $orderId, $shipmentNo),
            CreateLabelJob::HOOK,
            ['order_id' => $orderId, 'shipment_no' => $shipmentNo],
            10,
            3,
            self::ID
        ));
    }

    // ---- pickup snapshot (guarded, GLS-only) --------------------------------------------------------

    /**
     * Write the validated GLS pickup snapshot to order meta `_fch_pickup_point` + `_fch_carrier` +
     * `_fch_delivery_mode` (constraints §2) when the pickup module has not already. Guarded: only acts when
     * the selected shipping method resolves to the GLS carrier, and it never overwrites an existing snapshot.
     *
     * @param mixed $data `['order','cart','request_data',...]` from `fluent_cart/checkout/prepare_other_data`
     */
    public function prepareOtherData($data = []): void
    {
        if (!is_array($data)) {
            return;
        }
        $order = $data['order'] ?? null;
        if (!is_object($order) || !method_exists($order, 'updateMeta')) {
            return;
        }
        if (method_exists($order, 'getMeta')) {
            $existing = $order->getMeta(PickupCheckoutHandler::META_POINT, '');
            if ((is_string($existing) && $existing !== '') || (is_array($existing) && $existing !== [])) {
                return;
            }
        }

        $request = is_array($data['request_data'] ?? null) ? $data['request_data'] : [];
        try {
            $handler = new PickupCheckoutHandler();
            $selection = $handler->parseSelection($request[PickupCheckoutHandler::FIELD] ?? null);
        } catch (\Throwable $e) {
            return;
        }
        if ($selection === null || strtolower((string) ($selection['carrier'] ?? '')) !== self::CARRIER) {
            return;
        }

        $methodId = self::selectedMethodId($request, is_array($data['cart'] ?? null) ? $data['cart'] : null);
        $context = PickupPointsModule::methodContext($methodId);
        if (empty($context['required']) || strtolower((string) ($context['carrier'] ?? '')) !== self::CARRIER) {
            return;
        }

        try {
            $validator = new PickupPointValidator();
            $result = $validator->validate($selection, [
                'required' => true,
                'carrier' => self::CARRIER,
                'allowed_types' => (array) ($context['allowed_types'] ?? []),
                'directory' => new PickupDirectory(),
            ]);
        } catch (\Throwable $e) {
            return;
        }
        if (empty($result['ok']) || !is_array($result['snapshot'] ?? null)) {
            return;
        }

        $snapshot = $result['snapshot'];
        $deliveryMode = (string) ($selection['delivery_mode'] ?? $snapshot['type'] ?? '');
        $order->updateMeta(PickupCheckoutHandler::META_POINT, self::jsonEncode($snapshot));
        $order->updateMeta(PickupCheckoutHandler::META_CARRIER, (string) ($snapshot['carrier'] ?? self::CARRIER));
        $order->updateMeta(PickupCheckoutHandler::META_DELIVERY_MODE, $deliveryMode);
    }

    /** @param array<string,mixed> $request @param mixed $cart */
    private static function selectedMethodId(array $request, $cart): int
    {
        foreach ([
            $request['shipping_method_id'] ?? null,
            $request['fc_selected_shipping_method'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }
        if (is_object($cart) && isset($cart->checkout_data['shipping_data']['shipping_method_id'])) {
            $candidate = $cart->checkout_data['shipping_data']['shipping_method_id'];
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }
        if (is_array($cart)) {
            $candidate = $cart['checkout_data']['shipping_data']['shipping_method_id'] ?? null;
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }
        return 0;
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

    /** @param array<string,mixed> $value */
    private static function jsonEncode(array $value): string
    {
        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($value);
        }
        return (string) json_encode($value);
    }
}
