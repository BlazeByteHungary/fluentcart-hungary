<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * billing/szamlazz module: wires the invoice job, the reconciler and the (default-off) IPN ingress.
 *
 * Hook surface (constraints §3; 03_VERIFIED_EXTENSION_POINTS §lifecycle):
 *  - `fluent_cart/order_paid_done` is the ONLY lifecycle event consumed (payload
 *    `['order','transaction','customer'[, 'subscription']]`, app/Hooks/actions.php:174-187). Registration
 *    here is an enqueue only; the actual provider call happens in IssueInvoiceJob.
 *  - the Számlázz reconciler is registered by provider id so a lost invoice response is resolved by
 *    read-back, never by re-sending (invariant §1.4).
 *  - the IPN ingress is registered only when `ipn_enabled` is truthy (default off).
 */
final class SzamlazzModule implements ModuleInterface
{
    public const ID = 'billing/szamlazz';
    public const PROVIDER = 'szamlazz';
    public const SETTINGS_SCOPE = 'szamlazz';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Számlázz.hu', 'fluentcart-hungary');
    }

    public function requires(): array
    {
        return ['core'];
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    /** Prepaid policy: invoice once the payment condition is verified (`fluent_cart/order_paid_done`). */
    public const INVOICE_ON_PAYMENT = 'payment';

    /** COD policy: invoice when a shipping label is requested (before dispatch), payment stays pending. */
    public const INVOICE_ON_BEFORE_DISPATCH = 'before_dispatch';

    /** FluentCart Core COD payment-method slug (app/Modules/PaymentMethods/Cod/Cod.php:39-40, :91). */
    private const COD_METHOD = 'offline_payment';

    public function registerHooks(): void
    {
        JobRunner::instance()->registerHandler(new IssueInvoiceJob());

        add_action('fluent_cart/order_paid_done', [$this, 'onOrderPaidDone'], 10, 1);

        // COD "before dispatch" policy: the shipping module announces label requests; we enqueue only.
        add_action('fch/shipping/label_started', [$this, 'onLabelStarted'], 10, 1);

        ReconcilerRegistry::register(self::PROVIDER, new SzamlazzReconciler());

        // W4 corrections: storno / corrective dispatcher registered for the shared correction service.
        SzamlazzCorrectionService::register();

        // Default-off optional ingress; registered only when the merchant enabled it.
        (new SzamlazzWebhookHandler())->register();
    }

    /**
     * Enqueue (never inline) the invoice job with a stable per-order job key. The atomic claim / duplicate
     * safety lives in the ledger + document unique keys (invariants §1.3/§1.5), not in this handler.
     *
     * @param mixed $data hook payload
     */
    public function onOrderPaidDone($data): void
    {
        $orderId = $this->orderIdFromPayload($data);
        if ($orderId <= 0) {
            return;
        }

        $this->enqueueInvoice($orderId);
    }

    /**
     * COD policy hook: when `cod_invoice_event = before_dispatch`, a label request for an unpaid COD
     * order enqueues the invoice *before* the parcel is dispatched. The invoice service never mutates the
     * order's payment status, so the order stays unpaid (merchant policy requirement).
     *
     * @param mixed $orderId
     */
    public function onLabelStarted($orderId): void
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || self::invoiceOn() !== self::INVOICE_ON_BEFORE_DISPATCH) {
            return;
        }
        $order = $this->loadOrder($orderId);
        if ($order === null || (string) ($order->payment_method ?? '') !== self::COD_METHOD) {
            return;
        }
        // A confirmed invoice already exists for this mode/account: a later label request must not
        // reopen or re-run anything (reprint stays a strict read).
        if ($this->hasConfirmedInvoice($orderId)) {
            return;
        }
        // The stable key dedupes concurrent/queued runs, but a TERMINAL row may be an earlier no-op
        // (e.g. a skip under the old policy) and must not swallow the label trigger; reopen it.
        $this->enqueueInvoice($orderId, true);
    }

    private function enqueueInvoice(int $orderId, bool $rerunnable = false): void
    {
        $spec = new JobSpec(
            sprintf('fch:%d:invoice_job', $orderId),
            IssueInvoiceJob::HOOK,
            ['order_id' => $orderId],
            10,
            3,
            self::ID
        );
        if ($rerunnable) {
            JobRunner::instance()->enqueueRerunnable($spec);
            return;
        }
        JobRunner::instance()->enqueue($spec);
    }

    /** True when this provider already has a confirmed automatic invoice for the active mode/account. */
    private function hasConfirmedInvoice(int $orderId): bool
    {
        try {
            $settings = new ModuleSettings();
            $mode = $settings->mode();
            $account = $settings->accountId(self::SETTINGS_SCOPE);
            foreach ((new InvoiceDocumentRepository())->listForOrder($orderId, 20) as $row) {
                if ((string) ($row->provider ?? '') !== self::PROVIDER) {
                    continue;
                }
                if ((string) ($row->mode ?? '') !== $mode || (string) ($row->account_id ?? '') !== $account) {
                    continue;
                }
                if ((string) ($row->state ?? '') === ExternalOperation::EXTERNAL_CONFIRMED) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false; // fail-open to the service's own idempotency (never blocks the policy)
        }
        return false;
    }

    public function probe(): array
    {
        $fluentCartActive = defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\Models\\Order');
        $hasAgentKey = trim((string) self::agentKey()) !== '';

        if ($fluentCartActive && $hasAgentKey) {
            return ['ok' => true, 'message' => 'szamlazz ready'];
        }

        $missing = [];
        if (!$fluentCartActive) {
            $missing[] = 'fluentcart';
        }
        if (!$hasAgentKey) {
            $missing[] = 'agent_key';
        }
        return ['ok' => false, 'message' => 'degraded: missing ' . implode(', ', $missing)];
    }

    // ---- shared, non-secret settings accessors (used by service/reconciler/taxpayer/webhook) ------------

    public static function setting(string $key, mixed $default = null): mixed
    {
        return (new ModuleSettings())->get(self::SETTINGS_SCOPE, $key, $default);
    }

    public static function agentKey(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'agent_key');
    }

    public static function accountId(): string
    {
        return (new ModuleSettings())->accountId(self::SETTINGS_SCOPE);
    }

    public static function mode(): string
    {
        return (new ModuleSettings())->mode();
    }

    /**
     * Single-invoicer setting: only the selected provider may auto-issue (invariant §1.5).
     * Since W3 the selection is shared across both billing providers (InvoicerSelection); the legacy
     * `szamlazz.selected_invoicer` value stays authoritative until the shared selector is saved.
     */
    public static function isSelectedInvoicer(): bool
    {
        return \FluentCartHungary\Modules\Billing\InvoicerSelection::isSelected(self::PROVIDER);
    }

    /**
     * COD invoice policy ∈ {payment (default), before_dispatch}. Shared across providers since W3:
     * `billing.cod_invoice_event`, falling back to the legacy `szamlazz.cod_invoice_event` value.
     */
    public static function invoiceOn(): string
    {
        return \FluentCartHungary\Modules\Billing\InvoicerSelection::codInvoiceEvent();
    }

    /** FluentCart Order or null; FC symbols are only referenced inside the method (constraints §3, spike B1). */
    private function loadOrder(int $orderId): ?object
    {
        $class = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($class)) {
            return null;
        }
        $order = $class::find($orderId);
        return is_object($order) ? $order : null;
    }

    /** @param mixed $data @return int */
    private function orderIdFromPayload($data): int
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
}
