<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * MPL API v2 HTTP client (04_MPL_CONTRACT.md §2 auth/environments, §4 operations).
 *
 * - OAuth2 `client_credentials` (MPL-001): `POST {oauth2}/token`, HTTP Basic base64(API Key : API Secret),
 *   body `grant_type=client_credentials`, `Content-Type: application/x-www-form-urlencoded` (§2.1
 *   L663-702, reference L29-33). Credentials are NEVER sent in the body or URI (RFC 6749 2.3.1, §2.1).
 * - Token response `access_token`/`expires_in` (3600 s, §2.1 L86-94); cached in a transient keyed by
 *   mode+account with a refresh margin; a 401 triggers exactly one refresh (§7 token-expiry row).
 * - Bearer on every API call; per-request `X-Request-Id` (uuid, required) and `X-Accounting-Code`
 *   (vevőkód, required on v2) headers (§2.1 reference L167-176).
 * - Bases: test `https://sandbox.api.posta.hu`, live `https://core.api.posta.hu`; paths `/v2/mplapi/...`
 *   and tracking at `/v2/nyomkovetes/registered` (§2.2 L36-40, §4 MPL-013).
 * - Explicit bounded timeouts; no secret and no request/response body is ever logged.
 * - All transport goes through BaseClient so the `fch/http/pre_request` mock seam works (no network in tests).
 */
final class MplClient extends BaseClient
{
    public const PROVIDER = 'mpl';

    public const LIVE_BASE = 'https://core.api.posta.hu';
    public const TEST_BASE = 'https://sandbox.api.posta.hu';

    public const OAUTH_PATH = '/oauth2/token';
    public const SHIPMENTS_PATH = '/v2/mplapi/shipments';
    public const LABEL_PATH = '/v2/mplapi/shipments/label';
    public const CLOSE_PATH = '/v2/mplapi/shipments/close';
    public const TRACKING_REGISTERED_PATH = '/v2/nyomkovetes/registered';

    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 30;
    public const TOKEN_CONNECT_TIMEOUT = 5;
    public const TOKEN_TOTAL_TIMEOUT = 15;

    private string $mode;
    private string $key;
    private string $secret;
    private string $accountingCode;
    private string $accountId;

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    /**
     * @param string      $mode           'live'|'test' (anything else is treated as test — 04_MPL §2.2)
     * @param string|null $key            API Key; read from CredentialStore when null
     * @param string|null $secret         API Secret; read from CredentialStore when null
     * @param string|null $accountingCode X-Accounting-Code (Vevőkód); read from ModuleSettings when null
     * @param string|null $baseUrl        explicit override (tests only)
     */
    public function __construct(
        string $mode = 'test',
        ?string $key = null,
        ?string $secret = null,
        ?string $accountingCode = null,
        ?string $baseUrl = null
    ) {
        $this->mode = $mode === 'live' ? 'live' : 'test';
        $settings = new ModuleSettings();
        $this->accountId = $settings->accountId(self::PROVIDER);
        $this->key = (string) ($key ?? $settings->secret(self::PROVIDER, 'api_key') ?? '');
        $this->secret = (string) ($secret ?? $settings->secret(self::PROVIDER, 'api_secret') ?? '');
        $this->accountingCode = (string) ($accountingCode ?? $settings->get(self::PROVIDER, 'accounting_code', '') ?? '');

        parent::__construct($baseUrl ?? ($this->mode === 'live' ? self::LIVE_BASE : self::TEST_BASE));
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);
        $this->setHeader('Accept', 'application/json');
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasCredentials(): bool
    {
        return trim($this->key) !== '' && trim($this->secret) !== '';
    }

    // ---- MPL-006/007/009/010/011/012/013 ------------------------------------------------------------

    /**
     * MPL-006 `POST /v2/mplapi/shipments` — one call accepts up to 100 consignments (§4; reference L283-313).
     *
     * @param list<array<string,mixed>> $consignments
     */
    public function createShipments(array $consignments): Response
    {
        return $this->json('POST', self::SHIPMENTS_PATH, array_values($consignments));
    }

    /**
     * MPL-007 `GET /v2/mplapi/shipments` — lookup by fromDate/toDate/trackingNumbers/tag (§4; L315-354).
     *
     * @param array<string,mixed> $query
     */
    public function queryShipments(array $query): Response
    {
        return $this->json('GET', self::SHIPMENTS_PATH, [], $query);
    }

    /** MPL-010 `GET /v2/mplapi/shipments/{trackingNumber}` (§4; L429-459). */
    public function getShipment(string $trackingNumber): Response
    {
        return $this->json('GET', self::SHIPMENTS_PATH . '/' . rawurlencode($this->tokenize($trackingNumber)));
    }

    /**
     * MPL-009 `GET /v2/mplapi/shipments/label` — read-only label query (§4; L387-427, L797-836).
     * ZPL only supports A6/A5E/A5E_EXTRA (error 132).
     *
     * @param list<string> $trackingNumbers
     * @param array<string,mixed> $extra
     */
    public function getLabel(array $trackingNumbers, string $labelType = 'A4', string $labelFormat = 'PDF', array $extra = []): Response
    {
        $query = array_merge($extra, [
            'trackingNumbers' => array_values($trackingNumbers),
            'labelType' => $labelType,
            'labelFormat' => $labelFormat,
        ]);
        return $this->json('GET', self::LABEL_PATH, [], $query);
    }

    /**
     * MPL-012 `POST /v2/mplapi/shipments/close` — EFJ/manifest; `requestId` replays a previous close
     * response for timeout recovery (§4; L493-521, L1069-1130, §7 close row).
     *
     * @param array<string,mixed> $payload
     */
    public function closeShipments(array $payload): Response
    {
        return $this->json('POST', self::CLOSE_PATH, $payload);
    }

    /** MPL-011 `DELETE /v2/mplapi/shipments/{trackingNumber}` (§4; L461-491). */
    public function deleteShipment(string $trackingNumber): Response
    {
        return $this->json('DELETE', self::SHIPMENTS_PATH . '/' . rawurlencode($this->tokenize($trackingNumber)));
    }

    /**
     * MPL-013 PULL tracking, registered, one parcel (`POST /v2/nyomkovetes/registered`). The legacy flow
     * posted `{language: hu, ids, state: all}` (04_MPL §8), which matches the current request shape (§4).
     * Callers must not resubmit the same id within 4 h (§4; L1529-1549).
     *
     * @param list<string> $ids
     */
    public function pullTrackingRegistered(array $ids): Response
    {
        return $this->json('POST', self::TRACKING_REGISTERED_PATH, [
            'language' => 'hu',
            'ids' => array_values(array_map(fn($id): string => (string) $id, $ids)),
            'state' => 'all',
        ]);
    }

    /**
     * MPL-004 `POST /v2/mplapi/deliveryplace` — fixed-point (pickup) directory query.
     *
     * Request `DeliveryPlaceRequest{deliveryplacesQuery, servicePointType[]}` with service point types
     * `PM|CS|PP`; an empty query returns all fixed points (04_MPL §3.2, §4 MPL-004; reference L221-250,
     * L628-706). Read-only and domestic only. Response is an array of `DeliveryPlaceResult` with
     * `id`/`deliveryplace`/`postCode`/`postCodeOnLabel`/`city`/`address`/`geocodeLat`/`geocodeLong`.
     *
     * @param array{postCode?:string,postCodeOnLabel?:string,city?:string} $query
     * @param list<string> $servicePointTypes subset of PM|CS|PP
     */
    public function queryDeliveryPlaces(array $query, array $servicePointTypes = ['PM', 'CS', 'PP']): Response
    {
        $types = array_values(array_filter(array_map(
            static fn($type): string => strtoupper(trim((string) $type)),
            $servicePointTypes
        ), static fn(string $type): bool => in_array($type, ['PM', 'CS', 'PP'], true)));

        return $this->json('POST', '/v2/mplapi/deliveryplace', [
            'deliveryplacesQuery' => $query,
            'servicePointType' => $types === [] ? ['PM', 'CS', 'PP'] : $types,
        ]);
    }

    // ---- auth + transport ---------------------------------------------------------------------------

    /**
     * Perform an authorized call with a Bearer token and the mandatory X-Request-Id / X-Accounting-Code
     * headers; on 401 refresh the token exactly once and retry (04_MPL §2.1, §7).
     *
     * @param array{body?:mixed,headers?:array<string,string>} $args
     */
    private function authorized(string $method, string $url, array $args): Response
    {
        $token = $this->accessToken();
        if ($token === null) {
            return new Response(0, [], '', 'mpl_oauth_failed');
        }

        $request = $this->buildAuthorizedArgs($token, $args);
        $response = $this->request($method, $url, $request);

        if ($response->status === 401) {
            $token = $this->accessToken(true);
            if ($token === null) {
                return $response;
            }
            $response = $this->request($method, $url, $this->buildAuthorizedArgs($token, $args));
        }

        return $response;
    }

    /**
     * @param array{body?:mixed,headers?:array<string,string>} $args
     * @return array{body?:mixed,headers:array<string,string>}
     */
    private function buildAuthorizedArgs(string $token, array $args): array
    {
        $request = [
            'headers' => array_merge(
                [
                    'Authorization' => 'Bearer ' . $token,
                    'X-Request-Id' => self::uuid(),
                    'X-Correlation-Id' => self::uuid(),
                ],
                $this->accountingCode !== '' ? ['X-Accounting-Code' => $this->accountingCode] : [],
                $args['headers'] ?? []
            ),
        ];
        if (array_key_exists('body', $args) && $args['body'] !== null) {
            $request['body'] = $args['body'];
        }
        return $request;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function json(string $method, string $path, array $body = [], array $query = []): Response
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $args = [];
        if ($method === 'POST' && $body !== []) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body);
            $args['body'] = is_string($encoded) ? $encoded : '[]';
            $args['headers'] = ['Content-Type' => 'application/json'];
        }

        return $this->authorized($method, $url, $args);
    }

    /**
     * MPL-001 token retrieval with mode+account transient caching and an expires_in-refresh margin.
     * Returns null on any failure; the raw token is never logged or returned to callers.
     */
    private function accessToken(bool $force = false): ?string
    {
        if (!$force && $this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        $cacheKey = $this->cacheKey();
        if (!$force && function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $this->token = $cached;
                $this->tokenExpiresAt = time() + 3000; // conservative; real expiry re-checked on 401
                return $cached;
            }
        }

        if (!$this->hasCredentials()) {
            return null;
        }

        $response = $this->request('POST', $this->baseUrl . self::OAUTH_PATH, [
            'body' => 'grant_type=client_credentials',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->key . ':' . $this->secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
        ]);

        if ($response->error !== null || !$response->isSuccess()) {
            return null;
        }

        $data = $response->json();
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            return null;
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $ttl = max(60, $expiresIn - 60); // refresh margin
        $this->token = $token;
        $this->tokenExpiresAt = time() + $ttl;

        if (function_exists('set_transient')) {
            set_transient($cacheKey, $token, $ttl);
        }

        return $token;
    }

    private function cacheKey(): string
    {
        return 'fch_mpl_token_' . $this->mode . '_' . substr(md5($this->accountId), 0, 16);
    }

    /** Guard the path segment against path traversal/encoding surprises. */
    private function tokenize(string $value): string
    {
        $value = trim($value);
        return preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';
    }

    /** Required per-request uuid (§2.1); wp_generate_uuid4 with a random_bytes fallback. */
    private static function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            $uuid = (string) wp_generate_uuid4();
            if ($uuid !== '') {
                return $uuid;
            }
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
