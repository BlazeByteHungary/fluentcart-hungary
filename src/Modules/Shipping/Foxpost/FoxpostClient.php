<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * FoxWeb API v1.2.14 HTTP client (04_FOXPOST_CONTRACT.md §2 auth/environments, §4 operations).
 *
 * - Base path `/api` (guide §3): prod `https://webapi.foxpost.hu/api`, test
 *   `https://webapi-test.foxpost.hu/api` (SPEC_VERIFIED `servers[0].url`). The default instance is the
 *   test host; the environment is selected purely by host (there is no in-spec sandbox flag, §2).
 * - Auth is HTTP Basic (global `securitySchemes.basicAuth`) **plus** an `api-key` header on every call
 *   (§2, SPEC_VERIFIED). Both are attached here so every BaseClient helper carries them; secrets are
 *   never logged and never placed in the URL or body.
 * - All transport goes through BaseClient, so the `fch/http/pre_request` mock seam works (no network in
 *   tests) exactly like MplClient.
 * - Sandbox rule (§2): parcel creation in the test environment must be sent with `isWeb=false`; the
 *   service owns that decision (`createParcels($parcels, $isWeb)`).
 * - ZPL labels exist in the production spec only and are ACCOUNT_DEPENDENT (§1 FX-U8 / X5); the client
 *   exposes `getZplLabel()` but the label service gates it behind an account-dependent setting.
 * - Explicit bounded timeouts; `sslverify` stays on in BaseClient (legacy plugin disabled it — do not
 *   port, §8 F-002).
 */
final class FoxpostClient extends BaseClient
{
    public const PROVIDER = 'foxpost';

    public const LIVE_BASE = 'https://webapi.foxpost.hu/api';
    public const TEST_BASE = 'https://webapi-test.foxpost.hu/api';

    public const PARCEL_PATH = '/parcel';
    public const LABEL_PATH = '/label';
    public const LABEL_INFO_PATH = '/label/info';
    public const LABEL_ZPL_PATH = '/label/zpl';
    public const TRACKING_PATH = '/tracking';
    public const ADDRESS_PATH = '/address';

    /**
     * Machine contract page sizes (SPEC_VERIFIED prod+test `pageSize` enum). The prose contract §3 item 14
     * lists only A6/A7/_85X85; the OpenAPI adds A5. The machine contract wins here (documented in report).
     */
    public const PAGE_SIZES = ['A5', 'A6', 'A7', '_85X85'];

    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 30;

    private string $mode;
    private string $username;
    private string $password;
    private string $apiKey;

    /**
     * @param string      $mode     'live'|'test' (anything else is treated as test)
     * @param string|null $username Basic auth username; read from CredentialStore when null
     * @param string|null $password Basic auth password; read from CredentialStore when null
     * @param string|null $apiKey   `api-key` header; read from CredentialStore when null
     * @param string|null $baseUrl  explicit override (tests only)
     */
    public function __construct(
        string $mode = 'test',
        ?string $username = null,
        ?string $password = null,
        ?string $apiKey = null,
        ?string $baseUrl = null
    ) {
        $this->mode = $mode === 'live' ? 'live' : 'test';

        if ($username === null || $password === null || $apiKey === null) {
            $settings = new ModuleSettings();
            $username ??= (string) ($settings->secret(self::PROVIDER, 'username') ?? '');
            $password ??= (string) ($settings->secret(self::PROVIDER, 'password') ?? '');
            $apiKey ??= (string) ($settings->secret(self::PROVIDER, 'api_key') ?? '');
        }

        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->apiKey = (string) $apiKey;

        parent::__construct($baseUrl ?? ($this->mode === 'live' ? self::LIVE_BASE : self::TEST_BASE));
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);

        foreach ($this->authHeaders() as $name => $value) {
            $this->setHeader($name, $value);
        }
        $this->setHeader('Accept', 'application/json');
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Secret-bearing auth headers. Returns the exact wire shape used on every call; callers must never
     * persist or log the result.
     *
     * @return array<string,string>
     */
    public function authHeaders(): array
    {
        $headers = [];
        if ($this->username !== '' || $this->password !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }
        if ($this->apiKey !== '') {
            $headers['api-key'] = $this->apiKey;
        }
        return $headers;
    }

    public function hasCredentials(): bool
    {
        return trim($this->username) !== ''
            && trim($this->password) !== ''
            && trim($this->apiKey) !== '';
    }

    // ---- operations (SPEC_VERIFIED operationIds; 04_FOXPOST §4) --------------------------------------

    /**
     * `setParcels_1` POST /api/parcel — batch create. Query `isWeb` defaults true; sandbox requires
     * `isWeb=false` (§2). `isRedirect` is documented but left false.
     *
     * @param list<array<string,mixed>> $parcels one or more `CreateParcelRequest` items
     */
    public function createParcels(array $parcels, bool $isWeb = true): Response
    {
        return $this->json('POST', self::PARCEL_PATH, array_values($parcels), ['isWeb' => $isWeb ? 'true' : 'false']);
    }

    /**
     * `updateParcels` PUT /api/parcel — update by `barcode`; provider allows it only while status CREATE
     * (§4 op 2).
     *
     * @param list<array<string,mixed>> $parcels
     */
    public function updateParcels(array $parcels): Response
    {
        return $this->json('PUT', self::PARCEL_PATH, array_values($parcels));
    }

    /** `removeParcel` DELETE /api/parcel/{barcode} — one parcel per call; safe to repeat (§4 op 3, §7). */
    public function deleteParcel(string $barcode): Response
    {
        return $this->json('DELETE', self::PARCEL_PATH . '/' . $this->segment($barcode));
    }

    /**
     * `generatePdf` POST /api/label/{pageSize} — one PDF for the given clFox ids; re-runnable by barcode
     * (§4 op 9, §7). `$query` may carry `startPos` (A7 only) and the test-only `isPortrait`.
     *
     * @param list<string>         $clfoxIds
     * @param array<string,scalar> $query
     */
    public function generatePdf(array $clfoxIds, string $pageSize = 'A6', array $query = []): Response
    {
        $pageSize = strtoupper(trim($pageSize));
        if (!in_array($pageSize, self::PAGE_SIZES, true)) {
            $pageSize = 'A6';
        }
        return $this->json('POST', self::LABEL_PATH . '/' . rawurlencode($pageSize), array_values($clfoxIds), $query);
    }

    /** `generateBillOfDeliveryPdf` POST /api/label/deliveryNote. */
    public function generateBillOfDelivery(array $payload): Response
    {
        return $this->json('POST', self::LABEL_PATH . '/deliveryNote', $payload);
    }

    /** `getLabelInfo` GET /api/label/info/{barcode} — custom-label design data (read-only). */
    public function getLabelInfo(string $barcode): Response
    {
        return $this->json('GET', self::LABEL_INFO_PATH . '/' . $this->segment($barcode));
    }

    /**
     * `getZPLLabel` GET /api/label/zpl/{barcode} — **production spec only** (§1). Callers must gate this
     * behind the account-dependent setting (FX-U8); the client does not assume availability.
     */
    public function getZplLabel(string $barcode, ?int $dpi = null): Response
    {
        $query = $dpi !== null && $dpi > 0 ? ['dpi' => $dpi] : [];
        return $this->json('GET', self::LABEL_ZPL_PATH . '/' . $this->segment($barcode), [], $query);
    }

    /** `getTrackings` GET /api/tracking/{barcode} — clFox id or `uniqueBarcode` (§4 op 13). */
    public function getTracking(string $barcode): Response
    {
        return $this->json('GET', self::TRACKING_PATH . '/' . $this->segment($barcode));
    }

    /** `getTracks` GET /api/tracking/tracks/{barcode} — all statuses (§4 op 14). */
    public function getTracks(string $barcode): Response
    {
        return $this->json('GET', self::TRACKING_PATH . '/tracks/' . $this->segment($barcode));
    }

    /**
     * `getBatchTracks` POST /api/tracking/tracks — batch statuses (§4 op 15).
     *
     * @param list<string> $barcodes
     */
    public function getBatchTracks(array $barcodes): Response
    {
        return $this->json('POST', self::TRACKING_PATH . '/tracks', array_values($barcodes));
    }

    /** `getAddresses` GET /api/address — saved return addresses (read-only). */
    public function getAddresses(): Response
    {
        return $this->json('GET', self::ADDRESS_PATH);
    }

    /** `saveAddress` POST /api/address — create a return address (§4 op 18). */
    public function saveAddress(array $address): Response
    {
        return $this->json('POST', self::ADDRESS_PATH, $address);
    }

    /** `deleteByAddressName` DELETE /api/address/{name}. */
    public function deleteAddress(string $name): Response
    {
        return $this->json('DELETE', self::ADDRESS_PATH . '/' . $this->segment($name));
    }

    // ---- transport -----------------------------------------------------------------------------------

    /**
     * @param array<string,mixed>  $body
     * @param array<string,scalar> $query
     */
    private function json(string $method, string $path, array $body = [], array $query = []): Response
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $args = ['headers' => $this->authHeaders()];
        if (in_array($method, ['POST', 'PUT'], true)) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body);
            $args['body'] = is_string($encoded) ? $encoded : '[]';
            $args['headers']['Content-Type'] = 'application/json';
        }

        return $this->request($method, $url, $args);
    }

    /**
     * Guard a path segment. clFox ids are alphanumeric; uniqueBarcode may contain `/`, so it is
     * percent-encoded rather than stripped. An empty segment is rejected so we never hit `/tracking/`.
     */
    private function segment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return rawurlencode('__missing__');
        }
        return rawurlencode($value);
    }
}
