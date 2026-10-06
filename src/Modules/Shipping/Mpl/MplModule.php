<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CancelDispatcherRegistry;

/**
 * shipping/mpl module: wires the label job, tracking job, reconciler and the two create triggers.
 *
 * Hook surface (constraints §3; 03_VERIFIED_EXTENSION_POINTS §lifecycle):
 *  - `fluent_cart/order_paid_done` — async paid moment, payload `['order','transaction','customer'[,'subscription']]`
 *    (app/Hooks/actions.php:174-187). Registration is an enqueue only.
 *  - `fluent_cart/order_status_changed` — the typed order-status hook whose `new_status` is `processing`/
 *    `completed` (app/Events/Order/OrderStatusUpdated.php:90-92). Used for COD orders, because `paid` must
 *    not be the only trigger (invariant §1.7).
 *  - `fluent_cart/shipping_status_changed[_to_*]` is produced by the tracking path, not consumed here.
 *  - The MPL reconciler is registered by provider id so a lost create response is resolved by
 *    `GET /shipments` read-back, never by re-sending (invariant §1.4; 04_MPL §7).
 */
final class MplModule implements ModuleInterface
{
    public const ID = 'shipping/mpl';
    public const MODULE = 'shipping';
    public const PROVIDER = 'mpl';
    public const CARRIER = 'mpl';
    public const SETTINGS_SCOPE = 'mpl';

    /** FluentCart Core COD payment-method slug: `offline_payment` (Cod.php:40 slug/route; :91 check). */
    public const COD_METHOD = 'offline_payment';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('MPL', 'fluentcart-hungary');
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

        ReconcilerRegistry::register(self::PROVIDER, new MplReconciler());

        // W4 logistics lifecycle: ledger-routed external cancellation (MPL-011), read-back via getShipment.
        CancelDispatcherRegistry::register(self::CARRIER, static function (object $ledgerRow): ProviderCallResult {
            return (new MplShipmentService())->dispatchCancel($ledgerRow);
        });
    }

    /**
     * Paid trigger for every payment method (prepaid and COD alike, subject to the `create_on` rule).
     *
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
     * COD / non-paid trigger: on the verified order-status hook for `processing`/`completed`, but only when
     * the order is a FluentCart COD order and `create_on` allows it (invariant §1.7).
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
        $hasKey = trim((string) self::key()) !== '';
        $hasSecret = trim((string) self::secret()) !== '';

        if ($fluentCartActive && $hasKey && $hasSecret) {
            return ['ok' => true, 'message' => 'mpl ready'];
        }

        $missing = [];
        if (!$fluentCartActive) {
            $missing[] = 'fluentcart';
        }
        if (!$hasKey) {
            $missing[] = 'api_key';
        }
        if (!$hasSecret) {
            $missing[] = 'api_secret';
        }
        return ['ok' => false, 'message' => 'degraded: missing ' . implode(', ', $missing)];
    }

    // ---- shared, non-secret settings accessors (used by service/tracking/reconciler/jobs) --------------

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

    public static function key(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'api_key');
    }

    public static function secret(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'api_secret');
    }

    public static function hasCredentials(): bool
    {
        return trim((string) self::key()) !== '' && trim((string) self::secret()) !== '';
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
            sprintf('fch:%d:label_job:%d', $orderId, $shipmentNo),
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
