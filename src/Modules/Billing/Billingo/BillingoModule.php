<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\InvoicerSelection;
use FluentCartHungary\Modules\Billing\Szamlazz\IssueInvoiceJob;

/**
 * billing/billingo module (W3). Wires:
 *  - the provider-neutral IssueInvoiceJob (registered here too, because the job must exist when ONLY
 *    Billingo is enabled; both billing modules register the same hook and the job key dedupes intents),
 *  - the same lifecycle hooks as the W1 Számlázz module (`fluent_cart/order_paid_done`,
 *    `fch/shipping/label_started`) with the shared COD policy (InvoicerSelection::codInvoiceEvent),
 *  - the Billingo reconciler (vendor_id read-back).
 *
 * Environment note (04_BILLINGO_CONTRACT.md §2.1): there is a single API host; the API key's account
 * decides test vs live. `probe()` therefore reports mode/account and requires `api_key` AND `block_id`
 * (block_id is mandatory for document creation); the mere existence of a key does not prove a test
 * account — a demo account key is required for test-mode proofs (recorded in W3_BILLINGO_REPORT).
 */
final class BillingoModule implements ModuleInterface
{
    public const ID = 'billing/billingo';
    public const PROVIDER = 'billingo';
    public const SETTINGS_SCOPE = 'billingo';

    private const COD_METHOD = 'offline_payment';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Billingo', 'fluentcart-hungary');
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
        JobRunner::instance()->registerHandler(new IssueInvoiceJob());
        add_action('fluent_cart/order_paid_done', [$this, 'onOrderPaidDone'], 10, 1);
        add_action('fch/shipping/label_started', [$this, 'onLabelStarted'], 10, 1);
        ReconcilerRegistry::register(self::PROVIDER, new BillingoReconciler());

        // W4 corrections: cancel / modification dispatcher registered for the shared correction service.
        BillingoCorrectionService::register();
    }

    /** @param mixed $data hook payload */
    public function onOrderPaidDone($data): void
    {
        $orderId = $this->orderIdFromPayload($data);
        if ($orderId > 0) {
            $this->enqueueInvoice($orderId);
        }
    }

    /**
     * COD `before_dispatch` policy (shared): a label request for an unpaid COD order enqueues the
     * invoice before dispatch. Issuing never marks the order paid. The stable job key dedupes; a
     * confirmed invoice for the bound mode/account suppresses a later label trigger.
     *
     * @param mixed $orderId
     */
    public function onLabelStarted($orderId): void
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || InvoicerSelection::codInvoiceEvent() !== InvoicerSelection::INVOICE_ON_BEFORE_DISPATCH) {
            return;
        }
        $order = $this->loadOrder($orderId);
        if ($order === null || (string) ($order->payment_method ?? '') !== self::COD_METHOD) {
            return;
        }
        if ($this->hasConfirmedInvoice($orderId)) {
            return;
        }
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

    /** True when the BOUND provider already has a confirmed automatic invoice for the active mode/account. */
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
            return false;
        }
        return false;
    }

    public function probe(): array
    {
        if (!FluentCartCompat::isFcActive()) {
            return ['ok' => false, 'message' => 'fluentcart_inactive'];
        }
        $hasKey = trim((string) self::apiKey()) !== '';
        $blockId = (int) self::setting('block_id', 0);
        if ($hasKey && $blockId > 0) {
            return ['ok' => true, 'message' => 'billingo ready'];
        }
        $missing = [];
        if (!$hasKey) {
            $missing[] = 'api_key';
        }
        if ($blockId <= 0) {
            $missing[] = 'block_id';
        }
        return ['ok' => false, 'message' => 'degraded: missing ' . implode(', ', $missing)];
    }

    public static function apiKey(): ?string
    {
        return (new ModuleSettings())->secret(self::SETTINGS_SCOPE, 'api_key');
    }

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
