<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\Ledger\ReconcilerRegistry;
use FluentCartHungary\Core\Payments\RefundDispatcherRegistry;
use FluentCartHungary\Core\Payments\RefundService;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Barion payment module (W1; 05_IMPLEMENTATION_WAVES §W1.4). Registers the gateway on the verified
 * FluentCart action and the reconciler on our registry. Disabled by default so a missing/invalid POS key
 * never affects other modules (invariant §0/§1.10; ADR-001).
 */
final class BarionModule implements ModuleInterface
{
    public function id(): string
    {
        return 'payments/barion';
    }

    public function label(): string
    {
        return function_exists('__') ? __('Barion', 'fluentcart-hungary') : 'Barion';
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
        ReconcilerRegistry::register(BarionClient::PROVIDER, [new BarionReconciler(), 'reconcile']);

        // W4 refunds: one-call dispatch + the FluentCart availability filter that subtracts our open
        // reservations BEFORE FluentCart creates a refund transaction (W4_EXECUTION_PLAN §3/§4).
        RefundDispatcherRegistry::register(BarionClient::PROVIDER, [new BarionRefundService(), 'dispatch']);
        add_filter('fluent_cart/transaction/max_refundable_amount', static function ($base, $transaction) {
            return (new RefundService())->filterMaxRefundable($base, $transaction);
        }, 10, 2);

        // Gateway registration on the verified action fired at init (GlobalPaymentHandler.php:36-38).
        // Guarded on the FluentCart symbol so a missing/renamed FluentCart degrades instead of fataling.
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
            $data['gatewayManager']->register(BarionClient::PROVIDER, new BarionGateway());
        }, 10, 1);
    }

    public function probe(): array
    {
        if (!FluentCartCompat::isFcActive()) {
            return ['ok' => false, 'message' => 'fluentcart_inactive'];
        }

        $settings = new ModuleSettings();
        $posKey = $settings->secret(BarionClient::PROVIDER, BarionClient::CREDENTIAL_FIELD);
        if (!is_string($posKey) || $posKey === '') {
            return ['ok' => false, 'message' => 'pos_key_missing'];
        }

        return [
            'ok' => true,
            'message' => 'barion ready (' . $settings->mode() . '/' . $settings->accountId(BarionClient::PROVIDER) . ')',
        ];
    }
}
