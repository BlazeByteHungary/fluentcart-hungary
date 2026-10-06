<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\CoreShipping;

use FluentCartHungary\Core\ModuleInterface;

/**
 * shipping module — wave-1 core shipping mechanics (W1.1 legacy `HS-001..008`), inert without configuration.
 *
 * Hook surface (all verified, constraints §3; 03_VERIFIED_EXTENSION_POINTS §2/§3):
 *  - COD fee: `fluent_cart/cart/fees` (app/Models/Cart.php:914) returns validated fee items in FluentCart
 *    cents; fees merge last-wins by `source:key`. The shipping-charge recalculation path is
 *    `fluent_cart/checkout/shipping_data_changed` (app/Helpers/CartHelper.php:468) and the FC ShippingModule
 *    patch filter at priority 9 (app/Modules/Shipping/ShippingModule.php:20).
 *  - per-method payment restriction + weight limit: `fluent_cart/checkout/validate_before_process`
 *    (api/Checkout/CheckoutApi.php:115) returning WP_Error with the exact UX §4.3 message.
 *
 * FC COD slug is `offline_payment` (app/Modules/PaymentMethods/Cod/Cod.php:39-40, :91). Money is integer
 * cents only; `Helper::toCent()` scales by 100 even for HUF (app/Helpers/Helper.php:591-597). We never touch
 * FluentCart tax configuration.
 */
final class ShippingCoreModule implements ModuleInterface
{
    public const ID = 'shipping';
    public const COD_METHOD = 'offline_payment';
    public const SOURCE = 'fch-core-shipping';

    /** UX §4.3 exact HU notice (verbatim). */
    public const MSG_PAYMENT_RESTRICTED = 'Ehhez a szállítási módhoz az utánvét nem választható. Válasszon másik szállítási módot vagy fizetési módot.';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Shipping (core mechanics)', 'fluentcart-hungary');
    }

    public function requires(): array
    {
        return ['core'];
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function registerHooks(): void
    {
        add_filter('fluent_cart/cart/fees', [$this, 'addCodFee'], 25, 2);
        add_action('fluent_cart/checkout/shipping_data_changed', [$this, 'onShippingDataChanged'], 10, 1);
        add_filter('fluent_cart/checkout/validate_before_process', [$this, 'validateBeforeProcess'], 20, 2);
        (new ShippingMethodSettings())->registerAdmin();
        ShippingAdminScreen::register();
    }

    public function probe(): array
    {
        $fcActive = defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\Helpers\\CartHelper');
        return [
            'ok' => $fcActive,
            'message' => $fcActive ? 'shipping core ready' : 'degraded: missing fluentcart',
        ];
    }

    // ---- COD fee -----------------------------------------------------------------------------------

    /**
     * @param mixed $fees
     * @param mixed $context
     * @return array<int,array<string,mixed>>
     */
    public function addCodFee($fees, $context = []): array
    {
        if (!is_array($fees)) {
            $fees = [];
        }
        if (!is_array($context)) {
            return $fees;
        }

        $checkoutData = is_array($context['checkout_data'] ?? null) ? $context['checkout_data'] : [];
        $payment = (string) ($context['payment_method'] ?? '');
        if ($payment === '') {
            $payment = (string) ($checkoutData['payment_method'] ?? $checkoutData['form_data']['_fct_pay_method'] ?? '');
        }
        if ($payment !== self::COD_METHOD) {
            return $fees;
        }

        $methodId = $this->shippingMethodId($context['cart'] ?? null, $checkoutData);
        if ($methodId <= 0) {
            return $fees;
        }

        $settings = new ShippingMethodSettings();
        $config = $settings->codFeeConfig($methodId);
        if ($config['type'] === '' || $config['amount'] <= 0) {
            return $fees;
        }

        $subtotal = (int) ($context['cart_subtotal'] ?? 0);
        $currency = $this->storeCurrency();
        $fee = (new CodFeeCalculator())->calculate($subtotal, $config, true, $currency);
        if ($fee <= 0) {
            return $fees;
        }

        $fees[] = [
            'key' => 'cod_fee',
            'label' => function_exists('__') ? (string) __('Utánvét díja', 'fluentcart-hungary') : 'Utánvét díja',
            'amount' => $fee,
            'taxable' => false,
            'inclusive' => false,
            'source' => self::SOURCE,
            'meta' => ['method_id' => $methodId, 'currency' => $currency, 'rounded' => CodFeeCalculator::appliesHufRounding($currency)],
        ];

        return $fees;
    }

    /** @param mixed $data `['cart'=>Cart]` — clear FC's per-request fee cache after a shipping recalculation. */
    public function onShippingDataChanged($data): void
    {
        $cart = is_array($data) ? ($data['cart'] ?? null) : null;
        if (is_object($cart) && method_exists($cart, 'clearFeeCache')) {
            $cart->clearFeeCache();
        }
    }

    // ---- validation (payment restriction + weight limit) -------------------------------------------

    /**
     * @param mixed $valid
     * @param mixed $data full checkout placement request
     * @return mixed true|WP_Error
     */
    public function validateBeforeProcess($valid, $data = [])
    {
        if (!is_array($data)) {
            return $valid;
        }

        $methodId = (int) ($data['fc_selected_shipping_method'] ?? $data['shipping_method_id'] ?? 0);
        if ($methodId <= 0) {
            return $valid;
        }

        $settings = new ShippingMethodSettings();

        // HS-008: per-method payment restriction.
        $payment = (string) ($data['_fct_pay_method'] ?? '');
        if ($payment !== '') {
            $disabled = $settings->disabledPayments($methodId);
            if (in_array($payment, $disabled, true)) {
                $message = function_exists('__') ? (string) __(self::MSG_PAYMENT_RESTRICTED, 'fluentcart-hungary') : self::MSG_PAYMENT_RESTRICTED;
                return $this->wpError('fch_payment_restricted', $message);
            }
        }

        // HS-005: cart weight limit against the method's max_weight_g (FC's own weight helpers:
        // Helper::convertWeight app/Helpers/Helper.php:244, Helper::getPackageBySlug :300,
        // variation other_info.weight/weight_unit consumed by CartHelper.php:373-407).
        $maxWeight = $settings->maxWeightG($methodId);
        if ($maxWeight > 0) {
            $grams = $this->cartWeightGrams();
            if ($grams > $maxWeight) {
                $message = sprintf(
                    'A kosár súlya (%.2f kg) meghaladja a kiválasztott szállítási mód limitjét (max. %.2f kg).',
                    $grams / 1000,
                    $maxWeight / 1000
                );
                if (function_exists('__')) {
                    $message = (string) __($message, 'fluentcart-hungary');
                }
                return $this->wpError('fch_weight_limit', $message);
            }
        }

        return $valid;
    }

    // ---- helpers -----------------------------------------------------------------------------------

    private function wpError(string $code, string $message): mixed
    {
        $class = 'WP_Error';
        return class_exists($class) ? new $class($code, $message) : false;
    }

    /** @param mixed $cart @param array<string,mixed> $checkoutData */
    private function shippingMethodId($cart, array $checkoutData): int
    {
        $data = $checkoutData;
        if (is_object($cart) && isset($cart->checkout_data) && is_array($cart->checkout_data)) {
            $data = $cart->checkout_data;
        }
        $candidate = $data['shipping_data']['shipping_method_id'] ?? null;
        return is_numeric($candidate) ? (int) $candidate : 0;
    }

    private function storeCurrency(): string
    {
        $class = 'FluentCart\\Api\\StoreSettings';
        if (class_exists($class)) {
            try {
                $currency = (string) (new $class())->getCurrency();
                if ($currency !== '') {
                    return strtoupper($currency);
                }
            } catch (\Throwable $e) {
                // fall through to the HU default
            }
        }
        return 'HUF';
    }

    /**
     * Sum cart weight in grams using FluentCart's own helpers (mirrors CartHelper.php:371-410).
     * Returns 0 when FC is unavailable or the cart is empty.
     */
    public function cartWeightGrams(): int
    {
        $cartHelper = 'FluentCart\\App\\Helpers\\CartHelper';
        if (!class_exists($cartHelper) || !method_exists($cartHelper, 'getCart')) {
            return 0;
        }
        $cart = $cartHelper::getCart();
        if (!is_object($cart) || !is_array($cart->cart_data ?? null)) {
            return 0;
        }

        $helperClass = 'FluentCart\\App\\Helpers\\Helper';
        $variationClass = 'FluentCart\\App\\Models\\ProductVariation';
        $storeUnit = class_exists($helperClass) ? (string) ($helperClass::shopConfig('weight_unit') ?: 'kg') : 'kg';

        $totalGrams = 0.0;
        foreach ($cart->cart_data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = (int) ($item['quantity'] ?? 1);
            $variationId = (int) ($item['object_id'] ?? $item['variation_id'] ?? 0);
            if ($variationId <= 0 || !class_exists($variationClass)) {
                continue;
            }
            $variation = $variationClass::query()->find($variationId);
            if (!$variation) {
                continue;
            }
            $otherInfo = is_array($variation->other_info ?? null) ? $variation->other_info : [];
            $productWeight = (float) ($otherInfo['weight'] ?? 0);
            $productUnit = (string) ($otherInfo['weight_unit'] ?? $storeUnit);

            $grams = class_exists($helperClass)
                ? (float) $helperClass::convertWeight($productWeight, $productUnit, 'g')
                : $productWeight;

            $packageGrams = 0.0;
            if (class_exists($helperClass) && method_exists($helperClass, 'getPackageBySlug')) {
                $package = $helperClass::getPackageBySlug((string) ($otherInfo['package_slug'] ?? ''));
                if (is_array($package)) {
                    $packageGrams = (float) $helperClass::convertWeight(
                        (float) ($package['weight'] ?? 0),
                        (string) ($package['weight_unit'] ?? $storeUnit),
                        'g'
                    );
                }
            }

            $totalGrams += ($grams + $packageGrams) * $quantity;
        }

        return (int) round($totalGrams);
    }
}
