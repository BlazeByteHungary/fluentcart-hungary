<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Payments\Barion;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Barion shop-level REST client (04_BARION_CONTRACT.md §2, §5).
 *
 * - Base URL by mode only; there is no in-request environment field (§2):
 *   live https://api.barion.com ; sandbox https://api.test.barion.com (§2, §1 table).
 * - Auth is the shop POS key in the `x-pos-key` HTTP header on every call (§2). The key is NEVER placed
 *   in a request body and never logged (design constraints §1; 04 CRED).
 * - Explicit bounded timeouts (< 30 s provider max; SDK itself sets none — 04_BARION §6).
 * - All transport goes through BaseClient so the `fch/http/pre_request` mock seam works (no network in tests).
 * - Endpoints: POST /v2/Payment/Start ; GET /v4/payment/<PaymentId>/paymentstate (04_BARION §5 rows 1-2).
 *   GuestCheckout is intentionally never sent (removed in SDK 3.0.0, 04_BARION §7).
 */
final class BarionClient extends BaseClient
{
    public const PROVIDER = 'barion';
    public const CREDENTIAL_FIELD = 'pos_key';

    public const LIVE_BASE = 'https://api.barion.com';
    public const TEST_BASE = 'https://api.test.barion.com';

    public const START_PATH = '/v2/Payment/Start';

    private string $mode;

    private bool $hasPosKey = false;

    /**
     * @param string      $mode    'live'|'test' (anything else is treated as test — 04_BARION §2)
     * @param string|null $posKey  POS key; when null it is read from CredentialStore for the current mode/account
     * @param string|null $baseUrl explicit override (tests only)
     */
    public function __construct(string $mode = 'test', ?string $posKey = null, ?string $baseUrl = null)
    {
        $this->mode = $mode === 'live' ? 'live' : 'test';

        if ($posKey === null) {
            $settings = new ModuleSettings();
            $posKey = $settings->secret(self::PROVIDER, self::CREDENTIAL_FIELD);
        }

        parent::__construct($baseUrl ?? ($this->mode === 'live' ? self::LIVE_BASE : self::TEST_BASE));

        // Explicit bounded timeouts; provider max is 30 s and the SDK sets none (04_BARION §6).
        $this->setTimeouts(5, 15);

        $this->setHeader('Accept', 'application/json');
        if (is_string($posKey) && $posKey !== '') {
            // §2: v4 requires the header; never a body field.
            $this->setHeader('x-pos-key', $posKey);
            $this->hasPosKey = true;
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasCredentials(): bool
    {
        return $this->hasPosKey;
    }

    /**
     * POST /v2/Payment/Start (04_BARION §5 row 1). Payload must already exclude any secret; the POS key
     * travels only in the x-pos-key header.
     *
     * @param array<string,mixed> $payload
     */
    public function startPayment(array $payload): Response
    {
        if (!$this->hasPosKey) {
            return new Response(0, [], '', 'barion_pos_key_missing');
        }

        return $this->postJson(self::START_PATH, $payload);
    }

    /** GET /v4/payment/<PaymentId>/paymentstate (preferred; 04_BARION §5 row 2, §3 item 4). */
    public function getPaymentState(string $paymentId): Response
    {
        $paymentId = (string) preg_replace('/[^A-Za-z0-9\-]/', '', $paymentId);
        if ($paymentId === '') {
            return new Response(0, [], '', 'barion_invalid_payment_id');
        }
        if (!$this->hasPosKey) {
            return new Response(0, [], '', 'barion_pos_key_missing');
        }

        return $this->get('/v4/payment/' . $paymentId . '/paymentstate');
    }

    /**
     * POST /v2/Payment/Refund (04_BARION §5 row 4). The POS key stays in the header; the payload carries
     * `PaymentId` + `TransactionsToRefund[]` (`TransactionId`, `POSTransactionId`, `AmountToRefund`,
     * `Comment`). There is no documented idempotency key — the caller must never blind-retry.
     *
     * @param array<string,mixed> $payload
     */
    public function refundPayment(array $payload, array $headers = []): Response
    {
        if (!$this->hasPosKey) {
            return new Response(0, [], '', 'barion_pos_key_missing');
        }

        return $this->postJson('/v2/Payment/Refund', $payload, $headers);
    }

    /**
     * Legacy v2 status endpoint, kept for reference only (deprecated; 04_BARION §5 row 3).
     */
    public function getPaymentStateLegacy(string $paymentId): Response
    {
        if (!$this->hasPosKey) {
            return new Response(0, [], '', 'barion_pos_key_missing');
        }
        return $this->get('/v2/Payment/GetPaymentState', ['PaymentId' => $paymentId]);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    private function postJson(string $path, array $payload, array $headers = []): Response
    {
        $body = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        return $this->request('POST', $this->baseUrl . $path, [
            'body' => (string) $body,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $headers),
        ]);
    }
}
