<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * SimplePay one-time payment gateway for FluentCart (W3; 05_IMPLEMENTATION_WAVES §W3).
 *
 * Same proven addon path as the W1 Barion gateway: implements the real FluentCart
 * `PaymentGatewayInterface` plus the compatibility surface FluentCart internals call, and registers on
 * `fluent_cart/register_payment_methods`. The card is entered on the SimplePay hosted page — no card data
 * ever reaches WordPress (04_OTP_SIMPLE_CONTRACT §3 item 14).
 *
 * Flow (04_OTP_SIMPLE_CONTRACT §3/§5):
 *   PaymentAttemptRepository::getOrCreate -> safety guard (reconcile open attempts) ->
 *   OperationRunner::run('payment_start') -> POST /v2/start -> redirect to `paymentUrl`.
 * The browser redirect NEVER settles the order; only a validated `FINISHED` transaction from the server-side
 * `/v2/query` (triggered by IPN / browser back / reconciler) may settle (invariant §1.1).
 */
final class SimplepayGateway implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface
{
    public const ROUTE = 'simplepay';

    public array $supportedFeatures = ['payment', 'refund'];

    /** @var object settings adapter exposing get()/getMode()/isActive() to FluentCart internals */
    public object $settings;

    public mixed $storeSettings = null;

    private ?SimplepayClient $client;

    private ModuleSettings $moduleSettings;

    private SimplepayAttemptService $attemptService;

    public function __construct(
        ?SimplepayClient $client = null,
        ?ModuleSettings $settings = null,
        ?SimplepayAttemptService $attemptService = null
    ) {
        $this->moduleSettings = $settings ?? new ModuleSettings();
        $this->client = $client ?? new SimplepayClient($this->moduleSettings->mode());
        $this->attemptService = $attemptService ?? new SimplepayAttemptService();

        $this->settings = new class($this->moduleSettings) {
            public function __construct(private ModuleSettings $s)
            {
            }

            /** @return array<string,mixed> */
            public function get(string $key = ''): mixed
            {
                $all = $this->s->allFor(SimplepayStatus::PROVIDER);
                $all['is_active'] = $this->isActive() ? 'yes' : 'no';
                $all['mode'] = $this->s->mode();
                $all['checkout_label'] = (string) ($all['checkout_label'] ?? 'SimplePay');
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
                return (bool) $this->s->get(SimplepayStatus::PROVIDER, 'enabled', false);
            }

            public function methodHandler(): string
            {
                return 'fch_simplepay_settings';
            }
        };
    }

    // ---------------------------------------------------------------- interface

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'title' => function_exists('__') ? __('SimplePay', 'fluentcart-hungary') : 'SimplePay',
            'route' => self::ROUTE,
            'slug' => self::ROUTE,
            'description' => function_exists('__')
                ? __('Pay by bank card on the SimplePay hosted page (OTP SimplePay).', 'fluentcart-hungary')
                : 'Pay by bank card on the SimplePay hosted page.',
            'logo' => '',
            'icon' => '',
            'brand_color' => '#003f7d',
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
            $order = $paymentInstance->order;
            if (!$order) {
                return ['status' => 'failed', 'message' => 'simplepay_order_missing'];
            }

            $orderId = (int) $order->id;
            $currency = strtoupper((string) $order->currency);
            // FluentCart stores amount×100 for every currency; convert at the boundary to ISO minor units.
            $amountMinor = FluentCartMoney::fromCartCents((int) $order->total_amount, $currency);
            $orderUuid = (string) ($order->uuid ?? '');

            return $this->startPaymentAttempt(
                $orderId,
                $orderUuid,
                $amountMinor,
                $currency,
                $this->returnUrl(),
                $this->callbackUrl()
            );
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'simplepay_start_exception: ' . $e->getMessage()];
        }
    }

    /** IPN target for /?fluent-cart=fct_payment_listener_ipn&method=simplepay (GlobalPaymentHandler). */
    /**
     * FluentCart refund seam (see BarionGateway::processRefund). Bound to the original payment via the
     * shared RefundService; INIT `transactionCancel` remains a separate operation (never a refund).
     *
     * @return string|\WP_Error
     */
    public function processRefund($transaction, $amount, $args = [])
    {
        return (new \FluentCartHungary\Core\Payments\RefundService())
            ->handleGatewayRefund(self::ROUTE, $transaction, (int) $amount, is_array($args) ? $args : []);
    }

    public function handleIPN()
    {
        $result = (new SimplepayIpnHandler(null, $this->moduleSettings))->handle();

        // The provider requires a signed reply; FluentCart ignores handleIPN()'s return value, so the
        // response is emitted here (doc §3.14; SDK runIpnConfirm).
        if (!headers_sent()) {
            header('Content-type: application/json');
            header('Accept-language: EN');
            header('Signature: ' . (string) ($result['confirm_signature'] ?? 'UNSUCCESSFUL VALIDATION'));
        }
        echo (string) ($result['confirm_body'] ?? 'UNSUCCESSFUL VALIDATION');
        exit;
    }

    public function getOrderInfo(array $data)
    {
        // Browser return is handled by our own endpoint and never proves payment (invariant §1.1).
        return [
            'payment_method' => self::ROUTE,
            'requires_verification' => true,
        ];
    }

    public function fields(): array
    {
        return [
            'simplepay_info' => [
                'label' => function_exists('__') ? __('Security', 'fluentcart-hungary') : 'Security',
                'type' => 'html_attr',
                'value' => '<p>' . (function_exists('esc_html__')
                    ? esc_html__('The SimplePay merchant id and secret key are stored encrypted in the FluentCart Hungary settings (per currency and mode); card data is entered only on the SimplePay hosted page.', 'fluentcart-hungary')
                    : 'SimplePay credentials are stored encrypted; card data stays on the SimplePay hosted page.') . '</p>',
                'visible' => 'yes',
            ],
        ];
    }

    // ---------------------------------------------------------------- compatibility surface

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
        return (bool) $this->moduleSettings->get(SimplepayStatus::PROVIDER, 'enabled', false);
    }

    public function isUpcoming(): bool
    {
        return false;
    }

    /** Only the documented currencies with a configured merchant account are supported (doc §4). */
    public function isCurrencySupported(): bool
    {
        return true;
    }

    public function setStoreSettings($settings): void
    {
        $this->storeSettings = $settings;
    }

    public function validatePaymentMethod($data): array
    {
        $isZeroPayment = is_array($data) && !empty($data['isZeroPayment']);
        if (!$this->isEnabled() && !$isZeroPayment) {
            return ['isValid' => false, 'reason' => 'SimplePay is not active.'];
        }
        if ($this->client === null || !$this->client->hasAnyCredentials()) {
            return ['isValid' => false, 'reason' => 'SimplePay is missing its merchant/secret credentials.'];
        }
        return ['isValid' => true];
    }

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
            $label = (string) $this->moduleSettings->get(SimplepayStatus::PROVIDER, 'checkout_label', 'SimplePay');
            echo function_exists('esc_html') ? esc_html($label !== '' ? $label : 'SimplePay') : 'SimplePay';
        }
    }

    public function updateSettings($data): array
    {
        $data = is_array($data) ? $data : [];
        $sanitize = static function (mixed $value): string {
            $value = (string) $value;
            return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim($value);
        };

        foreach (['locale', 'checkout_label', 'checkout_instructions', 'fallback_email'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->moduleSettings->set(SimplepayStatus::PROVIDER, $key, $sanitize($data[$key]));
            }
        }
        if (array_key_exists('timeout_minutes', $data)) {
            $timeout = (int) $data['timeout_minutes'];
            if ($timeout >= 5 && $timeout <= 10080) {
                $this->moduleSettings->set(SimplepayStatus::PROVIDER, 'timeout_minutes', $timeout);
            }
        }
        if (array_key_exists('cancel_init_on_retry', $data)) {
            $this->moduleSettings->set(SimplepayStatus::PROVIDER, 'cancel_init_on_retry', (bool) $data['cancel_init_on_retry']);
        }
        if (array_key_exists('is_active', $data)) {
            $this->moduleSettings->set(SimplepayStatus::PROVIDER, 'enabled', $data['is_active'] === 'yes');
        }

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
     * @return array{status:string,message?:string,redirect_to?:string,payment_id?:string,state?:string}
     */
    public function startPaymentAttempt(
        int $orderId,
        string $orderUuid,
        int $amountMinor,
        string $currency,
        string $returnUrl,
        string $callbackUrl
    ): array {
        if (!$this->isEnabled()) {
            return ['status' => 'failed', 'message' => 'simplepay_not_enabled'];
        }

        $currency = strtoupper($currency);
        if (!SimplepayStatus::isSupportedCurrency($currency)) {
            return ['status' => 'failed', 'message' => 'simplepay_currency_unsupported'];
        }
        if ($amountMinor <= 0) {
            return ['status' => 'failed', 'message' => 'simplepay_amount_invalid'];
        }

        $client = $this->client;
        if ($client === null || !$client->hasCredentialsFor($currency)) {
            return ['status' => 'failed', 'message' => 'simplepay_credentials_missing_' . strtolower($currency)];
        }

        $mode = $this->moduleSettings->mode();
        $account = $this->moduleSettings->accountId(SimplepayStatus::PROVIDER);
        $repo = new PaymentAttemptRepository();

        if ($repo->hasConfirmed($orderId)) {
            return ['status' => 'failed', 'message' => 'simplepay_order_already_paid'];
        }

        $email = $this->customerEmail($orderId);
        if ($email === '') {
            return ['status' => 'failed', 'message' => 'simplepay_customer_email_missing'];
        }

        // Safety guard: never start a second charge while an earlier attempt may still complete.
        $guard = $this->guardOpenAttempts($orderId, $currency, $mode, $account, $repo);
        if ($guard['blocked']) {
            return ['status' => 'failed', 'message' => 'simplepay_guard_' . $guard['reason']];
        }

        $attemptNo = $repo->nextAttemptNo($orderId, self::ROUTE, $mode, $account);
        $idempotencyKey = SimplepayStatus::idempotencyKey($orderId, $attemptNo - 1);
        $orderRef = SimplepayStatus::orderRef($orderId, $attemptNo);

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
            'external_request_id' => $orderRef,
            'external_ref2' => $client->merchantFor($currency),
            'callback_url' => $callbackUrl,
        ]);
        $attemptRow = $attempt['row'];
        $attemptId = (int) $attemptRow->id;

        if (!$attempt['created'] && (string) $attemptRow->redirect_url !== '') {
            return [
                'status' => 'success',
                'redirect_to' => (string) $attemptRow->redirect_url,
                'payment_id' => (string) ($attemptRow->external_payment_id ?? ''),
            ];
        }

        $payload = $this->buildStartPayload($orderId, $orderRef, $currency, $email, $returnUrl, $client, $amountMinor);

        $gatewayUrl = null;
        $transactionId = null;
        $requestSummary = [];

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
            'business_reference' => $orderRef,
            'owner_type' => 'payment_attempt',
            'owner_id' => $attemptId,
            // SimplePay has no idempotency key (contract §10 item 2): one dispatch, then reconcile.
            'max_attempts' => 1,
        ], function (object $ledgerRow) use (
            &$gatewayUrl,
            &$transactionId,
            &$requestSummary,
            $payload,
            $orderRef,
            $currency,
            $orderId,
            $attemptId,
            $mode,
            $repo,
            $client
        ): ProviderCallResult {
            return $this->dispatchStart(
                $ledgerRow,
                $payload,
                $orderRef,
                $currency,
                $orderId,
                $attemptId,
                $mode,
                $repo,
                $client,
                $gatewayUrl,
                $transactionId,
                $requestSummary
            );
        });

        if (!$run['dispatched']) {
            if ((string) $run['state'] === ExternalOperation::EXTERNAL_CONFIRMED) {
                return ['status' => 'failed', 'message' => 'simplepay_attempt_already_confirmed'];
            }
            $existing = $repo->getById($attemptId);
            if ($existing !== null && (string) $existing->redirect_url !== '') {
                return ['status' => 'success', 'redirect_to' => (string) $existing->redirect_url];
            }
            return ['status' => 'failed', 'message' => 'simplepay_start_not_dispatched'];
        }

        if (($run['result'] ?? null) instanceof ProviderCallResult && $run['result']->isSuccess()) {
            return [
                'status' => 'success',
                'redirect_to' => (string) ($gatewayUrl ?? ''),
                'payment_id' => (string) ($transactionId ?? ''),
            ];
        }

        if (($run['result'] ?? null) instanceof ProviderCallResult) {
            $this->mirrorFailure($repo, $attemptId, $run['result'], (int) $run['row']->id);
        }
        $message = 'simplepay_start_failed';
        if (($run['result'] ?? null) instanceof ProviderCallResult && $run['result']->code) {
            $message = 'simplepay_start_' . $run['result']->code;
        }
        return ['status' => 'failed', 'message' => $message, 'state' => (string) $run['state']];
    }

    // ---------------------------------------------------------------- guard

    /**
     * Reconcile every open attempt of this order before starting a new one. Prevents a second charge when
     * the previous attempt actually completed (late success) and closes never-started INIT transactions
     * with the documented cancel (doc §3.18: INIT only). A guard that cannot verify blocks the start.
     *
     * @return array{blocked:bool,reason:string}
     */
    private function guardOpenAttempts(int $orderId, string $currency, string $mode, string $account, PaymentAttemptRepository $repo): array
    {
        $open = $repo->findOpenByOrder($orderId);
        foreach ($open as $row) {
            if ((string) $row->provider !== self::ROUTE || (string) $row->mode !== $mode) {
                continue;
            }
            if (strtoupper((string) $row->currency) !== $currency) {
                continue;
            }
            $transactionId = trim((string) ($row->external_payment_id ?? ''));
            $orderRef = trim((string) ($row->external_request_id ?? ''));
            if ($transactionId === '' && $orderRef === '') {
                continue; // never dispatched to the provider
            }

            $rowClient = new SimplepayClient((string) $row->mode, (string) $row->account_id);
            if (!$rowClient->hasCredentialsFor($currency)) {
                return ['blocked' => true, 'reason' => 'unverified_credentials'];
            }
            $response = $rowClient->query(
                $transactionId !== '' ? [$transactionId] : [],
                $orderRef !== '' ? [$orderRef] : [],
                $currency
            );
            if (!$response->isSuccess() || !SimplepayClient::isSignedResponse($response, $rowClient->secretFor($currency))) {
                return ['blocked' => true, 'reason' => 'unverified_open_attempt'];
            }

            $tx = SimplepayStatus::selectQueryTransaction($response->json(), $transactionId, $orderRef);
            if ($tx === null) {
                continue; // authoritative: no transaction exists for this reference
            }
            $status = strtoupper((string) ($tx['status'] ?? ''));

            if (SimplepayStatus::isFulfillable($status)) {
                $this->attemptService->applyQueryOutcome($row, $tx, 'query', $rowClient->merchantFor($currency));
                return ['blocked' => true, 'reason' => 'previous_attempt_paid'];
            }
            if (SimplepayStatus::isFinalFailure($status)) {
                $this->attemptService->rejectAttempt($row, $status, 'query');
                continue;
            }
            if ($status === SimplepayStatus::STATUS_INIT) {
                if ((bool) $this->moduleSettings->get(SimplepayStatus::PROVIDER, 'cancel_init_on_retry', true)) {
                    $rowClient->cancelTransaction($transactionId !== '' ? $transactionId : null, $orderRef !== '' ? $orderRef : null, $currency);
                }
                continue;
            }
            // INPAYMENT / INFRAUD / AUTHORIZED: an in-flight charge exists; do not start another.
            $this->attemptService->observe((int) $row->id, $status, 'query');
            return ['blocked' => true, 'reason' => 'attempt_in_progress'];
        }
        return ['blocked' => false, 'reason' => 'ok'];
    }

    // ---------------------------------------------------------------- dispatch

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $requestSummary filled for diagnostics
     */
    private function dispatchStart(
        object $ledgerRow,
        array $payload,
        string $orderRef,
        string $currency,
        int $orderId,
        int $attemptId,
        string $mode,
        PaymentAttemptRepository $repo,
        SimplepayClient $client,
        ?string &$gatewayUrl,
        ?string &$transactionId,
        array &$requestSummary
    ): ProviderCallResult {
        $secret = $client->secretFor($currency);
        $requestSummary = [
            'orderRef' => $orderRef,
            'merchant' => $client->merchantFor($currency),
            'currency' => $currency,
            'total' => (string) ($payload['total'] ?? ''),
            'methods' => implode(',', (array) ($payload['methods'] ?? [])),
            'url' => (string) ($payload['url'] ?? ''),
        ];

        $response = $client->start($payload, $currency);
        if ($response->isTimeout()) {
            return ProviderCallResult::uncertain('SIMPLEPAY_HTTP_TIMEOUT', $requestSummary);
        }

        if ($response->isSuccess() && !SimplepayClient::isSignedResponse($response, $secret)) {
            // A 2xx with an invalid/missing signature is unprovable: never trust its identifiers.
            return ProviderCallResult::uncertain('SIMPLEPAY_RESPONSE_SIGNATURE_INVALID', $requestSummary);
        }

        $body = $response->json();
        $errorCodes = array_values(array_filter(array_map(
            static fn($c): string => is_scalar($c) ? (string) $c : '',
            is_array($body['errorCodes'] ?? null) ? $body['errorCodes'] : []
        )));
        $responseSummary = [
            'status' => (int) $response->status,
            'errorCodes' => $errorCodes,
            'transactionId' => (string) ($body['transactionId'] ?? ''),
            'merchant' => (string) ($body['merchant'] ?? ''),
        ];

        if (!$response->isSuccess() || $errorCodes !== []) {
            $status = (int) $response->status;
            $code = $errorCodes[0] ?? 'SIMPLEPAY_ERROR';
            if ($status === 0 || $status === 408 || $status === 429 || $status >= 500) {
                return ProviderCallResult::uncertain('SIMPLEPAY_HTTP_' . ($status ?: 'TRANSPORT'), $requestSummary, $responseSummary);
            }
            return ProviderCallResult::rejected('SIMPLEPAY_' . $code, 'SimplePay rejected the payment start (code ' . $code . ').', $requestSummary, $responseSummary);
        }

        $txId = trim((string) ($body['transactionId'] ?? ''));
        $paymentUrl = trim((string) ($body['paymentUrl'] ?? ''));
        if ($txId === '' || $paymentUrl === '') {
            return ProviderCallResult::uncertain('SIMPLEPAY_INCOMPLETE_RESPONSE', $requestSummary, $responseSummary);
        }

        $repo->markStarted($attemptId, [
            'external_payment_id' => $txId,
            'external_request_id' => $orderRef,
            'external_ref2' => $client->merchantFor($currency),
            'redirect_url' => $paymentUrl,
            'raw_state' => SimplepayStatus::STATUS_INIT,
            'operation_id' => (int) $ledgerRow->id,
        ]);

        $this->stampTransaction($orderId, $txId, $orderRef, SimplepayStatus::STATUS_INIT, $mode);

        $gatewayUrl = $paymentUrl;
        $transactionId = $txId;

        return ProviderCallResult::success([
            'external_id' => $txId,
            'external_ref2' => SimplepayStatus::STATUS_INIT,
            'business_reference' => $orderRef,
        ], $requestSummary, $responseSummary);
    }

    /**
     * Mirror a dispatched non-success outcome onto the attempt (05_DATA_SCHEMA §7.2). The start is never
     * retried (no idempotency key contract §10 item 2).
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

    // ---------------------------------------------------------------- payload

    /**
     * Build the signed `/v2/start` payload from server-side order data only (contract §5 row 1).
     *
     * @return array<string,mixed>
     */
    private function buildStartPayload(int $orderId, string $orderRef, string $currency, string $email, string $returnUrl, SimplepayClient $client, int $amountMinorFallback): array
    {
        $timeoutMinutes = (int) $this->moduleSettings->get(SimplepayStatus::PROVIDER, 'timeout_minutes', 1440);
        if ($timeoutMinutes < 5 || $timeoutMinutes > 10080) {
            $timeoutMinutes = 1440;
        }
        $locale = strtoupper((string) $this->moduleSettings->get(SimplepayStatus::PROVIDER, 'locale', 'HU'));
        if ($locale === '') {
            $locale = 'HU';
        }

        $payload = [
            'salt' => SimplepaySignature::salt(),
            'merchant' => $client->merchantFor($currency),
            'orderRef' => $orderRef,
            'currency' => $currency,
            'customerEmail' => $email,
            'language' => $locale,
            'sdkVersion' => SimplepaySignature::sdkVersion(),
            'methods' => ['CARD'],
            // Documented samples sign the amount as a string; HUF integer, EUR/USD two decimals.
            'total' => Money::fromMinor($this->orderAmountMinor($orderId, $amountMinorFallback), $currency),
            'timeout' => date('c', time() + $timeoutMinutes * 60),
            'url' => $returnUrl,
        ];

        $invoice = $this->invoiceBlock($orderId);
        if ($invoice !== []) {
            $payload['invoice'] = $invoice;
        }
        $payload['threeDSReqAuthMethod'] = $this->customerAuthMethod($orderId);

        return $payload;
    }

    /**
     * Re-derive the amount from the authoritative order row at dispatch time (never from request input).
     * Falls back to the request amount when FluentCart is unavailable (unit context).
     */
    private function orderAmountMinor(int $orderId, int $fallback): int
    {
        if (class_exists('\FluentCart\App\Models\Order')) {
            try {
                $order = \FluentCart\App\Models\Order::query()->find($orderId);
                if ($order) {
                    return FluentCartMoney::fromCartCents((int) $order->total_amount, strtoupper((string) $order->currency));
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return $fallback;
    }

    /** @return array<string,string> */
    private function invoiceBlock(int $orderId): array
    {
        if (!class_exists('\FluentCart\App\Models\Order')) {
            return [];
        }
        try {
            $order = \FluentCart\App\Models\Order::query()->find($orderId);
            if (!$order) {
                return [];
            }
            $address = method_exists($order, 'billing_address') ? $order->billing_address : null;
            if (!$address) {
                return [];
            }
            $name = trim((string) ($address->company ?? ''));
            if ($name === '') {
                $name = trim(((string) ($address->first_name ?? '')) . ' ' . ((string) ($address->last_name ?? '')));
            }
            if ($name === '') {
                return [];
            }
            $invoice = ['name' => $name];
            $map = [
                'country' => 'country',
                'state' => 'state',
                'city' => 'city',
                'zip' => 'zip',
                'address' => 'address_1',
                'address2' => 'address_2',
            ];
            foreach ($map as $target => $source) {
                $value = trim((string) ($address->{$source} ?? ''));
                if ($value !== '') {
                    $invoice[$target] = $value;
                }
            }
            if (isset($invoice['country'])) {
                $invoice['country'] = strtolower($invoice['country']);
            }
            $meta = is_array($address->meta ?? null) ? $address->meta : [];
            $phone = trim((string) ($meta['other_data']['phone'] ?? ''));
            if ($phone !== '') {
                $invoice['phone'] = $phone;
            }
            return $invoice;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function customerAuthMethod(int $orderId): string
    {
        if (!class_exists('\FluentCart\App\Models\Order')) {
            return '01';
        }
        try {
            $order = \FluentCart\App\Models\Order::query()->find($orderId);
            if ($order && (int) ($order->customer_id ?? 0) > 0) {
                return '02';
            }
        } catch (\Throwable $e) {
            // guest default
        }
        return '01';
    }

    private function customerEmail(int $orderId): string
    {
        if (class_exists('\FluentCart\App\Models\Order')) {
            try {
                $order = \FluentCart\App\Models\Order::query()->find($orderId);
                if ($order) {
                    $customer = method_exists($order, 'customer') ? $order->customer : null;
                    $email = trim((string) ($customer->email ?? ''));
                    if ($email !== '') {
                        return $email;
                    }
                }
            } catch (\Throwable $e) {
                // fall through to configured fallback
            }
        }
        $fallback = trim((string) $this->moduleSettings->get(SimplepayStatus::PROVIDER, 'fallback_email', ''));
        if ($fallback !== '' && (!function_exists('is_email') || is_email($fallback))) {
            return $fallback;
        }
        return '';
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Best-effort stamping of the FluentCart charge transaction with the SimplePay identifiers.
     */
    private function stampTransaction(int $orderId, string $transactionId, string $orderRef, string $providerStatus, string $mode): void
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
            $meta['simplepay_transaction_id'] = $transactionId;
            $meta['simplepay_order_ref'] = $orderRef;
            $meta['simplepay_status'] = $providerStatus;
            $transaction->vendor_charge_id = $transactionId;
            $transaction->payment_method = self::ROUTE;
            $transaction->payment_mode = $mode;
            $transaction->meta = $meta;
            $transaction->save();
        } catch (\Throwable $e) {
            // Best effort only; the attempt mirror is the source of truth.
        }
    }

    private function callbackUrl(): string
    {
        if (class_exists('\FluentCart\App\Services\Payments\PaymentHelper')) {
            $helper = new \FluentCart\App\Services\Payments\PaymentHelper(self::ROUTE);
            $data = $helper->listenerUrl();
            if (is_array($data) && !empty($data['listener_url'])) {
                return (string) $data['listener_url'];
            }
        }
        return $this->homeUrl() . '?fluent-cart=fct_payment_listener_ipn&method=' . self::ROUTE;
    }

    /** Browser return endpoint: `/v2/start` `url`; validates r/s and triggers the query job. */
    public function returnUrl(): string
    {
        return $this->homeUrl() . '?' . SimplepayReturnHandler::QUERY_VAR . '=1';
    }

    private function homeUrl(): string
    {
        return function_exists('home_url') ? rtrim((string) home_url('/'), '/') : '';
    }
}
