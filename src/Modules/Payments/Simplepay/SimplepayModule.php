<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Payments\RefundDispatcherRegistry;
use FluentCartHungary\Core\Payments\RefundService;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * SimplePay payment module (W3; 05_IMPLEMENTATION_WAVES §W3). Registers the gateway on the verified
 * FluentCart action, the reconciler on our registry, the confirmation job, and the browser-return
 * endpoint. Disabled by default so missing credentials never affect other modules (ADR-001).
 */
final class SimplepayModule implements ModuleInterface
{
    public function id(): string
    {
        return 'payments/simplepay';
    }

    public function label(): string
    {
        return function_exists('__') ? __('SimplePay (OTP)', 'fluentcart-hungary') : 'SimplePay';
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
        // Read-back reconciliation for UNCERTAIN starts (never re-dispatches create; invariant §1.4).
        ReconcilerRegistry::register(SimplepayStatus::PROVIDER, [new SimplepayReconciler(), 'reconcile']);

        // W4 refunds: dispatch once + subtract open reservations in FluentCart's availability filter.
        RefundDispatcherRegistry::register(SimplepayStatus::PROVIDER, [new SimplepayRefundService(), 'dispatch']);
        add_filter('fluent_cart/transaction/max_refundable_amount', static function ($base, $transaction) {
            return (new RefundService())->filterMaxRefundable($base, $transaction);
        }, 10, 2);

        // Async query-confirmation job (browser return / recovery).
        JobRunner::instance()->registerHandler(new SimplepayConfirmJob());

        // Browser-return endpoint registered before output; it validates r/s and schedules the query job.
        add_action('init', static function (): void {
            if (isset($_GET[SimplepayReturnHandler::QUERY_VAR])) {
                (new SimplepayReturnHandler())->dispatch();
            }
        }, 5);

        // Gateway registration on the verified action fired at init (GlobalPaymentHandler.php:36-38).
        add_action('fluent_cart/register_payment_methods', static function ($data): void {
            if (!class_exists('\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
                return;
            }
            if (!interface_exists('\FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface')) {
                return;
            }
            if (!is_array($data) || !isset($data['gatewayManager']) || !is_object($data['gatewayManager'])) {
                return;
            }
            if (!method_exists($data['gatewayManager'], 'register')) {
                return;
            }
            $data['gatewayManager']->register(SimplepayStatus::PROVIDER, new SimplepayGateway());
        }, 10, 1);
    }

    public function probe(): array
    {
        if (!FluentCartCompat::isFcActive()) {
            return ['ok' => false, 'message' => 'fluentcart_inactive'];
        }

        $settings = new ModuleSettings();
        $client = new SimplepayClient($settings->mode());
        if (!$client->hasAnyCredentials()) {
            return ['ok' => false, 'message' => 'simplepay_merchant_credentials_missing'];
        }

        $currencies = [];
        foreach (SimplepayStatus::CURRENCIES as $currency) {
            if ($client->hasCredentialsFor($currency)) {
                $currencies[] = $currency;
            }
        }

        return [
            'ok' => true,
            'message' => 'simplepay ready (' . $settings->mode() . '/' . $settings->accountId(SimplepayStatus::PROVIDER)
                . '; ' . implode(',', $currencies) . ')',
        ];
    }
}
