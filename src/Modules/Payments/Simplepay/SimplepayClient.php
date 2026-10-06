<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Simplepay;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * SimplePay API v2 JSON client (04_OTP_SIMPLE_CONTRACT.md §2, §5; SimplePay_2.x_Payment doc §3).
 *
 * - Environment is host-only: sandbox `https://sandbox.simplepay.hu/payment`, live
 *   `https://secure.simplepay.hu/payment` (doc §1.7; SDK `$api`).
 * - Every request is signed with the currency-specific `SECRET_KEY`: `Signature: base64(HMAC-SHA384(RAW
 *   body, secret))` (doc §3.2). Credentials are loaded per mode+account from the encrypted store; a
 *   currency without both `merchant_*` and `secret_key_*` is simply unavailable.
 * - All transport goes through BaseClient, so the `fch/http/pre_request` mock seam works without network.
 * - Bounded timeouts: IPN handling has a 20 s provider budget (doc §3.14), so query calls default to 8 s.
 */
final class SimplepayClient extends BaseClient
{
    public const PROVIDER = 'simplepay';

    public const LIVE_BASE = 'https://secure.simplepay.hu/payment';
    public const SANDBOX_BASE = 'https://sandbox.simplepay.hu/payment';

    public const START_PATH = '/v2/start';
    public const QUERY_PATH = '/v2/query';
    public const CANCEL_PATH = '/v2/transactionCancel';
    public const REFUND_PATH = '/v2/refund';

    private string $mode;

    private string $account;

    /** @var array<string,array{merchant:string,secret:string}> currency => credentials */
    private array $accounts = [];

    /**
     * @param string|null $account       credential account slot; null = current settings account
     * @param array<string,array{merchant:string,secret:string}>|null $credentials explicit override (tests)
     */
    public function __construct(
        string $mode = 'test',
        ?string $account = null,
        ?array $credentials = null,
        ?string $baseUrl = null
    ) {
        $this->mode = $mode === 'live' ? 'live' : 'test';
        $settings = new ModuleSettings();
        $this->account = $account !== null && $account !== '' ? $account : $settings->accountId(self::PROVIDER);

        parent::__construct($baseUrl ?? ($this->mode === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE));
        $this->setTimeouts(5, 8);
        $this->setHeader('Content-Type', 'application/json');
        $this->setHeader('Accept', 'application/json');

        $this->accounts = $credentials ?? self::loadCredentials($this->mode, $this->account);
    }

    /** Credential field names (SettingsPage contract): merchant is an identifier, secret_key is secret. */
    public static function credentialFields(): array
    {
        $fields = [];
        foreach (SimplepayStatus::CURRENCIES as $currency) {
            $lower = strtolower($currency);
            $fields['merchant_' . $lower] = [
                'currency' => $currency,
                'secret' => false,
                'label' => sprintf('Merchant (%s)', $currency),
            ];
            $fields['secret_key_' . $lower] = [
                'currency' => $currency,
                'secret' => true,
                'label' => sprintf('Secret key (%s)', $currency),
            ];
        }
        return $fields;
    }

    /** @return array<string,array{merchant:string,secret:string}> */
    public static function loadCredentials(string $mode, string $account): array
    {
        $store = new CredentialStore();
        $out = [];
        foreach (SimplepayStatus::CURRENCIES as $currency) {
            $lower = strtolower($currency);
            $merchant = $store->get(self::PROVIDER, $mode, $account, 'merchant_' . $lower);
            $secret = $store->get(self::PROVIDER, $mode, $account, 'secret_key_' . $lower);
            if (is_string($merchant) && trim($merchant) !== '' && is_string($secret) && trim($secret) !== '') {
                $out[$currency] = ['merchant' => trim($merchant), 'secret' => trim($secret)];
            }
        }
        return $out;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function account(): string
    {
        return $this->account;
    }

    public function hasCredentialsFor(string $currency): bool
    {
        return isset($this->accounts[strtoupper($currency)]);
    }

    public function hasAnyCredentials(): bool
    {
        return $this->accounts !== [];
    }

    public function merchantFor(string $currency): string
    {
        return $this->accounts[strtoupper($currency)]['merchant'] ?? '';
    }

    public function secretFor(string $currency): string
    {
        return $this->accounts[strtoupper($currency)]['secret'] ?? '';
    }

    /** Resolve a merchant account id (IPN `merchant`, back `m`) to its currency. */
    public function currencyForMerchant(string $merchant): ?string
    {
        $merchant = trim($merchant);
        if ($merchant === '') {
            return null;
        }
        foreach ($this->accounts as $currency => $creds) {
            if (hash_equals($creds['merchant'], $merchant)) {
                return $currency;
            }
        }
        return null;
    }

    /**
     * POST /v2/start (doc §3.3/§3.8). Payload must be built from server-side order data only.
     *
     * @param array<string,mixed> $payload
     */
    public function start(array $payload, string $currency): Response
    {
        return $this->postSigned(self::START_PATH, $payload, $currency);
    }

    /**
     * POST /v2/query (doc §3.17) — the documented server-side reconciliation primitive.
     * With `$refunds = true` the response carries `refunds[]` for each transaction (W4 read-back path).
     *
     * @param list<string> $transactionIds
     * @param list<string> $orderRefs
     */
    public function query(array $transactionIds, array $orderRefs, string $currency, bool $refunds = false): Response
    {
        $payload = [
            'salt' => SimplepaySignature::salt(),
            'merchant' => $this->merchantFor($currency),
            'sdkVersion' => SimplepaySignature::sdkVersion(),
        ];
        if ($transactionIds !== []) {
            $payload['transactionIds'] = array_values($transactionIds);
        }
        if ($orderRefs !== []) {
            $payload['orderRefs'] = array_values($orderRefs);
        }
        if ($refunds) {
            $payload['refunds'] = true;
        }
        if ($transactionIds === [] && $orderRefs === []) {
            return new Response(0, [], '', 'simplepay_query_ids_missing');
        }
        return $this->postSigned(self::QUERY_PATH, $payload, $currency);
    }

    /**
     * POST /v2/refund (OpenAPI 2.1.0 `/v2/refund`; 04_OTP §3.16). No idempotency key exists — the caller
     * dispatches once and resolves a lost response through `query(refunds: true)`, never by repeating.
     *
     * @param array<string,mixed> $payload
     */
    public function refund(array $payload, string $currency): Response
    {
        return $this->postSigned(self::REFUND_PATH, $payload, $currency);
    }

    /** POST /v2/transactionCancel — only transactions still in INIT are accepted (doc §3.18). */
    public function cancelTransaction(?string $transactionId, ?string $orderRef, string $currency): Response
    {
        $transactionId = $transactionId !== null ? trim($transactionId) : '';
        $orderRef = $orderRef !== null ? trim($orderRef) : '';
        if ($transactionId === '' && $orderRef === '') {
            return new Response(0, [], '', 'simplepay_cancel_ids_missing');
        }
        $payload = [
            'salt' => SimplepaySignature::salt(),
            'merchant' => $this->merchantFor($currency),
            'sdkVersion' => SimplepaySignature::sdkVersion(),
            'currency' => strtoupper($currency),
        ];
        if ($transactionId !== '') {
            $payload['transactionId'] = $transactionId;
        }
        if ($orderRef !== '') {
            $payload['orderRef'] = $orderRef;
        }
        return $this->postSigned(self::CANCEL_PATH, $payload, $currency);
    }

    /** Verify a provider response/IPN: `Signature` header over the RAW body (doc §3.2). */
    public static function isSignedResponse(Response $response, string $secret): bool
    {
        $signature = $response->header('Signature');
        if ($signature === null) {
            return false;
        }
        return SimplepaySignature::verify($response->body, $signature, $secret);
    }

    /** @param array<string,mixed> $payload */
    private function postSigned(string $path, array $payload, string $currency): Response
    {
        $currency = strtoupper($currency);
        $secret = $this->secretFor($currency);
        if ($secret === '') {
            return new Response(0, [], '', 'simplepay_secret_missing_for_' . $currency);
        }

        $body = SimplepaySignature::encode($payload);
        return $this->request('POST', $this->baseUrl . $path, [
            'body' => $body,
            'headers' => ['Signature' => SimplepaySignature::sign($body, $secret)],
        ]);
    }
}
