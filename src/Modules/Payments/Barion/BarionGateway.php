<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Barion one-time payment gateway for FluentCart.
 *
 * Implements the real FluentCart interface directly (spike pattern) instead of extending
 * AbstractPaymentGateway, whose constructor requires a BaseGatewaySettings subclass; FluentCart internals
 * additionally read `$gateway->settings->get()`, `isCurrencySupported()`, `validatePaymentMethod()`,
 * `prepare()`/`render()` and `getMeta()` (GlobalPaymentHandler.php:110-141; PaymentMethods.php:52-74;
 * CheckoutRenderer.php:932-934), so those compatibility methods are provided here without any FluentCart
 * base class.
 *
 * Flow (04_BARION_CONTRACT.md §3, §5; design constraints §1.1/§1.4):
 *   PaymentAttemptRepository::getOrCreate -> OperationRunner::run(kind 'payment_start')
 *   -> dispatch BarionClient::startPayment -> success => redirect to GatewayUrl.
 * A browser redirect NEVER settles the order; only BarionIpnHandler / BarionReconciler may, via the
 * POS-key status API (04_BARION §3 item 4, §7).
 */
final class BarionGateway implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface
{
    public const ROUTE = 'barion';

    public array $supportedFeatures = ['payment', 'refund'];

    /** @var object settings adapter exposing get()/getMode()/isActive() to FluentCart internals */
    public object $settings;

    public mixed $storeSettings = null;

    private ?BarionClient $client;

    private ModuleSettings $moduleSettings;

    public function __construct(?BarionClient $client = null, ?ModuleSettings $settings = null)
    {
        $this->moduleSettings = $settings ?? new ModuleSettings();
        $this->client = $client ?? new BarionClient($this->moduleSettings->mode());

        // Minimal settings adapter (FluentCart reads $gateway->settings->get()/is_active; PaymentMethods.php:52-74).
        $this->settings = new class($this->moduleSettings) {
            public function __construct(private ModuleSettings $s)
            {
            }

            /** @return array<string,mixed> */
            public function get(string $key = ''): mixed
            {
                $all = $this->s->allFor('barion');
                $all['is_active'] = $this->isActive() ? 'yes' : 'no';
                $all['mode'] = $this->s->mode();
                $all['checkout_label'] = (string) ($all['checkout_label'] ?? 'Barion');
                if ($key !== '') {
                    return $all[$key] ?? '';
                }
                return $all;
            }

            public function getMode(): string
            {
                return $this->s->mode();
            }

            public function isActive(): bool
            {
                return (bool) $this->s->get('barion', 'enabled', false);
            }

            public function methodHandler(): string
            {
                return 'fch_barion_settings';
            }
        };
    }

    // ---------------------------------------------------------------- interface

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    /** @return array<string,mixed> all keys GatewayManager::getAllMeta() requires (GatewayManager.php:156-183). */
    public function meta(): array
    {
        return [
            'title' => function_exists('__') ? __('Barion', 'fluentcart-hungary') : 'Barion',
            'route' => self::ROUTE,
            'slug' => self::ROUTE,
            'description' => function_exists('__')
                ? __('Pay securely with Barion (bank card or Barion wallet).', 'fluentcart-hungary')
                : 'Pay securely with Barion.',
            'logo' => '',
            'icon' => '',
            'brand_color' => '#0079d3',
            'upcoming' => false,
            'status' => $this->isEnabled(),
        ];
    }

    /**
     * @param \FluentCart\App\Services\Payments\PaymentInstance $paymentInstance
     * @return array{status:string,message?:string,redirect_to?:string}
     */
    public function makePaymentFromPaymentInstance(\FluentCart\App\Services\Payments\PaymentInstance $paymentInstance)
    {
        try {
            // Real accessors: PaymentInstance::$order (PaymentInstance.php:18/26-30).
            $order = $paymentInstance->order;
            if (!$order) {
                return ['status' => 'failed', 'message' => 'barion_order_missing'];
            }

            $orderId = (int) $order->id;
            // FluentCart stores amount×100 for every currency (Helper::toCent() at Helper.php:590-597, even
            // for HUF). Convert at this boundary to our ISO minor units so the Barion decimal `Total` is the
            // real forint amount (4 240, not 424 000) and the status-API amount comparison matches.
            $currency = strtoupper((string) $order->currency);
            $amountMinor = FluentCartMoney::fromCartCents((int) $order->total_amount, $currency);
            $orderUuid = (string) ($order->uuid ?? '');

            $redirectUrl = $this->successUrl($paymentInstance);
            $callbackUrl = $this->callbackUrl();

            return $this->startPaymentAttempt($orderId, $orderUuid, $amountMinor, $currency, $redirectUrl, $callbackUrl);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'barion_start_exception: ' . $e->getMessage()];
        }
    }

    /** IPN target for /?fluent-cart=fct_payment_listener_ipn&method=barion (GlobalPaymentHandler.php:41-67). */
    /**
     * FluentCart refund seam (`Refund::processRefund` -> `$gateway->processRefund`). All lifecycle,
     * reservation, dedup and FluentCart state mirroring lives in the shared RefundService.
     *
     * @param object $transaction FluentCart OrderTransaction (charge)
     * @param int    $amount      refund amount in FluentCart cents
     * @param array  $args        refund info (+ optional `fch_refund_intent` idempotency token)
     * @return string|\WP_Error
     */
    public function processRefund($transaction, $amount, $args = [])
    {
        return (new \FluentCartHungary\Core\Payments\RefundService())
            ->handleGatewayRefund(self::ROUTE, $transaction, (int) $amount, is_array($args) ? $args : []);
    }

    public function handleIPN()
    {
        return (new BarionIpnHandler($this->client, $this->moduleSettings))->handle();
    }

    public function getOrderInfo(array $data)
    {
        // Browser return is intentionally a no-op: it never proves payment (invariant §1.1).
        return [
            'payment_method' => self::ROUTE,
            'requires_verification' => true,
        ];
    }

    public function fields(): array
    {
        // Non-secret checkout presentation only; the POS key is managed by the plugin's secure settings.
        return [
            'barion_payee_email' => [
                'label' => function_exists('__') ? __('Payee e-mail', 'fluentcart-hungary') : 'Payee e-mail',
                'type' => 'text',
                'value' => (string) $this->moduleSettings->get('barion', 'payee_email', ''),
                'visible' => 'yes',
            ],
            'barion_info' => [
                'label' => function_exists('__') ? __('Security', 'fluentcart-hungary') : 'Security',
                'type' => 'html_attr',
                'value' => '<p>' . (function_exists('esc_html__')
                    ? esc_html__('The Barion POS key is stored encrypted in the FluentCart Hungary settings, never in this form.', 'fluentcart-hungary')
                    : 'The Barion POS key is stored encrypted in the plugin settings.') . '</p>',
                'visible' => 'yes',
            ],
        ];
    }

    // ---------------------------------------------------------------- FluentCart compatibility surface

    /** AbstractPaymentGateway::getMeta() equivalent; required by GatewayManager::getAllMeta()/getRoutes(). */
    public function getMeta(string $key = ''): mixed
    {
        $meta = $this->meta();
        if ($key !== '') {
            return $meta[$key] ?? '';
        }
        return $meta;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->moduleSettings->get('barion', 'enabled', false);
    }

    public function isUpcoming(): bool
    {
        return false;
    }

    public function isCurrencySupported(): bool
    {
        return true;
    }

    public function setStoreSettings($settings): void
    {
        $this->storeSettings = $settings;
    }

    /** Called by PaymentHelper::validateAndGetPayMethod (PaymentHelper.php:106-121). */
    public function validatePaymentMethod($data): array
    {
        $isZeroPayment = is_array($data) && !empty($data['isZeroPayment']);
        if (!$this->isEnabled() && !$isZeroPayment) {
            return ['isValid' => false, 'reason' => 'Barion is not active.'];
        }
        if (!$this->isConfigured()) {
            return ['isValid' => false, 'reason' => 'Barion is missing its POS key or payee e-mail.'];
        }
        return ['isValid' => true];
    }

    /** Called by CheckoutRenderer.php:932-934 / ModalCheckoutRenderer.php:799-801. */
    public function prepare($mode, $hasSubscription): void
    {
        $this->render($mode);
        if (function_exists('do_action')) {
            do_action('fluent_cart/after_render_payment_method_' . self::ROUTE);
        }
    }

    public function render($mode = 'logo'): void
    {
        if ($mode === 'radio') {
            echo '<span class="checkmark"></span>';
            return;
        }
        if ($mode !== 'logo') {
            echo function_exists('esc_html') ? esc_html((string) $this->getMeta('title')) : 'Barion';
        }
    }

    /** Minimal settings persistence for the FluentCart admin path (PaymentMethodController.php:53-64). */
    public function updateSettings($data): array
    {
        $data = is_array($data) ? $data : [];
        $sanitize = static function (mixed $value): string {
            $value = (string) $value;
            return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim($value);
        };

        foreach (['payee_email', 'locale', 'checkout_label', 'checkout_instructions', 'barion_pixel_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->moduleSettings->set('barion', $key, $sanitize($data[$key]));
            }
        }
        if (array_key_exists('is_active', $data)) {
            $this->moduleSettings->set('barion', 'enabled', $data['is_active'] === 'yes');
        }

        // Never accept a plaintext POS key through this path (04 CRED §3; secrets go through CredentialStore).
        return (array) $this->settings->get();
    }

    public function getSettings(): object
    {
        return $this->settings;
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function webHookPaymentMethodName(): string
    {
        return self::ROUTE;
    }

    // ---------------------------------------------------------------- start flow

    /**
     * Core start flow, separated from PaymentInstance so it is directly exercisable (integration harness).
     * Returns the FluentCart transaction shape: ['status'=>'success','redirect_to'=>GatewayUrl] or failed.
     *
     * @return array{status:string,message?:string,redirect_to?:string,payment_id?:string}
     */
    public function startPaymentAttempt(
        int $orderId,
        string $orderUuid,
        int $amountMinor,
        string $currency,
        string $redirectUrl,
        string $callbackUrl
    ): array {
        if (!$this->isEnabled()) {
            return ['status' => 'failed', 'message' => 'barion_not_enabled'];
        }

        $amountCheck = BarionStatus::validateAmount($amountMinor, $currency);
        if (!$amountCheck['ok']) {
            return ['status' => 'failed', 'message' => 'barion_amount_' . $amountCheck['reason']];
        }

        $payeeEmail = trim((string) $this->moduleSettings->get('barion', 'payee_email', ''));
        if ($payeeEmail === '') {
            return ['status' => 'failed', 'message' => 'barion_payee_email_missing'];
        }
        if ($this->client === null || !$this->client->hasCredentials()) {
            return ['status' => 'failed', 'message' => 'barion_pos_key_missing'];
        }

        $mode = $this->moduleSettings->mode();
        $account = $this->moduleSettings->accountId('barion');
        $locale = (string) $this->moduleSettings->get('barion', 'locale', 'hu-HU');

        $repo = new PaymentAttemptRepository();
        if ($repo->hasConfirmed($orderId)) {
            return ['status' => 'failed', 'message' => 'barion_order_already_paid'];
        }

        $attemptNo = $repo->nextAttemptNo($orderId, self::ROUTE, $mode, $account);
        $idempotencyKey = BarionStatus::idempotencyKey($orderId, $attemptNo - 1);

        $attempt = $repo->getOrCreate([
            'order_id' => $orderId,
            'order_uuid' => $orderUuid,
            'provider' => self::ROUTE,
            'mode' => $mode,
            'account_id' => $account,
            'attempt_no' => $attemptNo,
            'idempotency_key' => $idempotencyKey,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_type' => 'Immediate',
            'callback_url' => $callbackUrl,
        ]);
        $attemptRow = $attempt['row'];
        $attemptId = (int) $attemptRow->id;

        // Duplicate submission of the same attempt: reuse the existing gateway redirect if we have one.
        if (!$attempt['created'] && (string) $attemptRow->redirect_url !== '') {
            return [
                'status' => 'success',
                'redirect_to' => (string) $attemptRow->redirect_url,
                'payment_id' => (string) ($attemptRow->external_payment_id ?? ''),
            ];
        }

        $paymentRequestId = substr('fch-' . $orderId . '-' . $attemptNo . '-' . bin2hex(random_bytes(4)), 0, 100);
        // Provider takes a decimal Total per currency; HUF = 0 decimals (04_BARION §4). Integer math only.
        $totalDecimal = Money::fromMinor($amountMinor, $currency);
        $payload = [
            // NO GuestCheckout (removed in SDK 3.0.0 — 04_BARION §7 delta).
            'PaymentType' => 'Immediate',
            'PaymentRequestId' => $paymentRequestId,
            'Currency' => $currency,
            'Locale' => $locale,
            'RedirectUrl' => $redirectUrl,
            'CallbackUrl' => $callbackUrl,
            'FundingSources' => ['All'],
            'Transactions' => [[
                'POSTransactionId' => $paymentRequestId,
                'Payee' => $payeeEmail,
                'Total' => $totalDecimal,
                'Items' => [[
                    'Name' => 'Order ' . $orderId,
                    'Description' => 'Order ' . $orderId,
                    'Quantity' => 1,
                    'UnitPrice' => $totalDecimal,
                    'ItemTotal' => $totalDecimal,
                    'SKU' => 'order-' . $orderId,
                ]],
            ]],
        ];

        $gatewayUrl = null;
        $paymentId = null;
        $gatewayStatus = null;

        $runner = new OperationRunner();
        $run = $runner->run([
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'order_uuid' => $orderUuid,
            'module' => 'payments',
            'provider' => self::ROUTE,
            'kind' => 'payment_start',
            'mode' => $mode,
            'account_id' => $account,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'business_reference' => $orderUuid !== '' ? $orderUuid : (string) $orderId,
            'owner_type' => 'payment_attempt',
            'owner_id' => $attemptId,
            // Barion Start auto-retry is NOT_SAFE (04_BARION §6): one dispatch only, then reconcile.
            'max_attempts' => 1,
        ], function (object $ledgerRow) use (
            &$gatewayUrl,
            &$paymentId,
            &$gatewayStatus,
            $payload,
            $paymentRequestId,
            $orderId,
            $attemptId,
            $mode,
            $repo
        ): ProviderCallResult {
            return $this->dispatchStart($ledgerRow, $payload, $paymentRequestId, $orderId, $attemptId, $mode, $repo, $gatewayUrl, $paymentId, $gatewayStatus);
        });

        if (!$run['dispatched']) {
            if ((string) $run['state'] === ExternalOperation::EXTERNAL_CONFIRMED) {
                return ['status' => 'failed', 'message' => 'barion_attempt_already_confirmed'];
            }
            $existing = $repo->getById($attemptId);
            if ($existing !== null && (string) $existing->redirect_url !== '') {
                return ['status' => 'success', 'redirect_to' => (string) $existing->redirect_url];
            }
            return ['status' => 'failed', 'message' => 'barion_start_not_dispatched'];
        }

        if (($run['result'] ?? null) instanceof ProviderCallResult && $run['result']->isSuccess()) {
            return [
                'status' => 'success',
                'redirect_to' => (string) ($gatewayUrl ?? ''),
                'payment_id' => (string) ($paymentId ?? ''),
            ];
        }

        // rejected / uncertain / local error: FluentCart sees a failure; the ledger holds the real state.
        if (($run['result'] ?? null) instanceof ProviderCallResult) {
            $this->mirrorFailure($repo, $attemptId, $run['result'], (int) $run['row']->id);
        }
        $message = 'barion_start_failed';
        if (($run['result'] ?? null) instanceof ProviderCallResult && $run['result']->code) {
            $message = 'barion_start_' . $run['result']->code;
        }
        return ['status' => 'failed', 'message' => $message, 'state' => (string) $run['state']];
    }

    /**
     * Mirror a dispatched non-success outcome onto the attempt (05_DATA_SCHEMA §7.2):
     * IN_PROGRESS -> UNCERTAIN (lost response / unprovable) or IN_PROGRESS -> PROVEN_REJECTED (definitive).
     * The create call is never retried (invariant §1.4).
     */
    private function mirrorFailure(PaymentAttemptRepository $repo, int $attemptId, ProviderCallResult $result, int $ledgerId): void
    {
        $row = $repo->getById($attemptId);
        if ($row === null) {
            return;
        }
        $isUncertain = $result->isUncertain();
        $isRejected = $result->status === ProviderCallResult::REJECTED;
        if (!$isUncertain && !$isRejected) {
            // LOCAL_ERROR before/without a provider outcome: leave the mirror NOT_STARTED.
            return;
        }

        $state = (string) $row->state;
        if ($state === ExternalOperation::NOT_STARTED) {
            $repo->markStarted($attemptId, ['operation_id' => $ledgerId]);
        }

        if ($isUncertain) {
            $repo->markUncertain($attemptId, (string) ($result->code ?? 'UNCERTAIN'));
            return;
        }
        $repo->markRejected($attemptId, [
            'verification_source' => 'status_api',
            'raw_state' => (string) ($result->code ?? 'REJECTED'),
        ]);
    }

    // ---------------------------------------------------------------- dispatch

    /**
     * @param array<string,mixed> $payload
     */
    private function dispatchStart(
        object $ledgerRow,
        array $payload,
        string $paymentRequestId,
        int $orderId,
        int $attemptId,
        string $mode,
        PaymentAttemptRepository $repo,
        ?string &$gatewayUrl,
        ?string &$paymentId,
        ?string &$gatewayStatus
    ): ProviderCallResult {
        if (!$this->client instanceof BarionClient) {
            return ProviderCallResult::localError('BARION_CLIENT_MISSING', 'Barion client not configured.');
        }

        // Secret-free request summary (POS key never present; 04 CRED §3).
        $requestSummary = [
            'PaymentType' => 'Immediate',
            'PaymentRequestId' => $paymentRequestId,
            'Currency' => (string) ($payload['Currency'] ?? ''),
            'Total' => (string) ((($payload['Transactions'][0]['Total'] ?? '')) ?: ''),
            'RedirectUrl' => (string) ($payload['RedirectUrl'] ?? ''),
            'CallbackUrl' => (string) ($payload['CallbackUrl'] ?? ''),
            'TransactionCount' => count((array) ($payload['Transactions'] ?? [])),
        ];

        $response = $this->client->startPayment($payload);

        if ($response->isTimeout()) {
            return ProviderCallResult::uncertain('BARION_HTTP_TIMEOUT', $requestSummary);
        }

        $body = $response->json();
        $responseSummary = $this->summarizeStartResponse($body);

        if (!$response->isSuccess()) {
            $status = (int) $response->status;
            $code = $this->firstErrorCode($body);
            // 408/429/5xx may have been processed server-side => UNCERTAIN, never retried (04_BARION §6).
            if ($status === 0 || $status === 408 || $status === 429 || $status >= 500) {
                return ProviderCallResult::uncertain('BARION_HTTP_' . ($status ?: 'TRANSPORT'), $requestSummary, $responseSummary);
            }
            return ProviderCallResult::rejected($code, (string) ($body['Errors'][0]['Title'] ?? 'Barion rejected the payment start.'), $requestSummary, $responseSummary);
        }

        $pid = (string) ($body['PaymentId'] ?? '');
        $gw = (string) ($body['GatewayUrl'] ?? '');
        $providerStatus = (string) ($body['Status'] ?? '');

        if ($pid === '') {
            // 2xx but no PaymentId: outcome cannot be proven by id => UNCERTAIN (no by-ref lookup; 04_BARION §6).
            return ProviderCallResult::uncertain('BARION_NO_PAYMENT_ID', $requestSummary, $responseSummary);
        }

        // Mirror the attempt (external id + redirect url); NOT a payment proof (invariant §1.1).
        $repo->markStarted($attemptId, [
            'external_payment_id' => $pid,
            'external_request_id' => $paymentRequestId,
            'redirect_url' => $gw,
            'payment_type' => 'Immediate',
            'raw_state' => $providerStatus,
            'operation_id' => (int) $ledgerRow->id,
        ]);

        $this->stampTransaction($orderId, $pid, $providerStatus, $mode);

        $gatewayUrl = $gw;
        $paymentId = $pid;
        $gatewayStatus = $providerStatus;

        return ProviderCallResult::success([
            'external_id' => $pid,
            'external_ref2' => $providerStatus,
            'business_reference' => $paymentRequestId,
        ], $requestSummary, $responseSummary);
    }

    /**
     * Best-effort stamping of the FluentCart charge transaction with the Barion PaymentId.
     * CODE_VERIFIED: OrderTransaction fillable vendor_charge_id/meta (OrderTransaction.php:36-54);
     * latest charge lookup mirrors PaymentInstance.php:34-38.
     */
    private function stampTransaction(int $orderId, string $paymentId, string $providerStatus, string $mode): void
    {
        if (!class_exists('\FluentCart\App\Models\OrderTransaction')) {
            return;
        }
        try {
            $transaction = \FluentCart\App\Models\OrderTransaction::query()
                ->where('order_id', $orderId)
                ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE)
                ->orderBy('id', 'desc')
                ->first();
            if (!$transaction) {
                return;
            }
            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $meta['barion_payment_id'] = $paymentId;
            $meta['barion_status'] = $providerStatus;
            $transaction->vendor_charge_id = $paymentId;
            $transaction->payment_method = self::ROUTE;
            $transaction->payment_mode = $mode;
            $transaction->meta = $meta;
            $transaction->save();
        } catch (\Throwable $e) {
            // Best effort only; the attempt mirror is the source of truth for verification.
        }
    }

    // ---------------------------------------------------------------- helpers

    private function isConfigured(): bool
    {
        if ($this->client === null || !$this->client->hasCredentials()) {
            return false;
        }
        return trim((string) $this->moduleSettings->get('barion', 'payee_email', '')) !== '';
    }

    private function callbackUrl(): string
    {
        if (class_exists('\FluentCart\App\Services\Payments\PaymentHelper')) {
            // listenerUrl() is an INSTANCE method taking the slug in the constructor (PaymentHelper.php:15-31).
            $helper = new \FluentCart\App\Services\Payments\PaymentHelper(self::ROUTE);
            $data = $helper->listenerUrl();
            if (is_array($data) && !empty($data['listener_url'])) {
                return (string) $data['listener_url'];
            }
        }
        $base = function_exists('home_url') ? home_url('/') : '/';
        return rtrim($base, '/') . '/?fluent-cart=fct_payment_listener_ipn&method=' . self::ROUTE;
    }

    private function successUrl(\FluentCart\App\Services\Payments\PaymentInstance $paymentInstance): string
    {
        $transaction = $paymentInstance->transaction ?? null; // PaymentInstance.php:20
        if ($transaction && method_exists($transaction, 'getSuccessUrl')) {
            return (string) $transaction->getSuccessUrl(); // OrderTransaction.php:232-235
        }
        return function_exists('home_url') ? home_url('/') : '/';
    }

    /** @param array<string,mixed> $body */
    private function firstErrorCode(array $body): string
    {
        $errors = $body['Errors'] ?? [];
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            return (string) ($errors[0]['ErrorCode'] ?? 'BARION_ERROR');
        }
        return 'BARION_ERROR';
    }

    /**
     * Secret-free response summary (never echo AuthData/headers; 04 CRED §3).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function summarizeStartResponse(array $body): array
    {
        $summary = [
            'Status' => (string) ($body['Status'] ?? ''),
            'PaymentId' => (string) ($body['PaymentId'] ?? ''),
            'GatewayUrl' => (string) ($body['GatewayUrl'] ?? ''),
        ];
        $errors = $body['Errors'] ?? [];
        if (is_array($errors)) {
            $summary['ErrorCodes'] = array_values(array_map(
                static fn($e): string => is_array($e) ? (string) ($e['ErrorCode'] ?? '') : (string) $e,
                $errors
            ));
        }
        return $summary;
    }
}
