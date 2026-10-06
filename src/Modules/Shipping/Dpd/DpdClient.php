<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * DPD NST Shipment API v1.1 HTTP client (04_DPD_CONTRACT.md §2 auth/environments, §4 operations).
 *
 * - Auth: `Authorization: Bearer <JWT>` (§2.1 L29/L310). The **JWT acquisition endpoint/scheme is NOT
 *   documented** in the local library (DPD-UNK-01, DPD-U1, global blocker G10). Therefore this client
 *   never tries to fetch a token: the bearer comes from the CredentialStore key `token` (or an injected
 *   value for tests). When it is empty, `probe()` reports BLOCKED reason `jwt_scheme_unknown`
 *   (matrix-account-dependent: whether a HU merchant may use PREPROD is UNKNOWN, DPD-UNK-12).
 * - Back-Office endpoints additionally require `DPD-BU-ID: <string>` (§2.1). We attach it when a `bu_code`
 *   is configured; the real Back-Office host is UNKNOWN (DPD-UNK-02, DPD-U2) so those calls are gated by
 *   DpdGeodata, not issued from here blindly.
 * - Bases: test PREPROD `https://nst-preprod.dpsin.dpdgroup.com/api/v1.1/` (§1 L14-16),
 *   live `https://shipping.dpdgroup.com/api/v1.1/` (§1 L18-20). Both are overridable for tests.
 * - Every call goes through BaseClient so the `fch/http/pre_request` mock seam works (no network in tests),
 *   mirroring MplClient.
 * - Explicit bounded timeouts; no secret and no request/response body is ever logged. A 401 is returned to
 *   the caller as-is: no automatic token refresh is attempted because the acquisition scheme is unknown.
 */
final class DpdClient extends BaseClient
{
    public const PROVIDER = 'dpd';

    public const LIVE_BASE = 'https://shipping.dpdgroup.com/api/v1.1/';
    public const TEST_BASE = 'https://nst-preprod.dpsin.dpdgroup.com/api/v1.1/';

    /** Shipment API paths (04_DPD_CONTRACT.md §4.1; paths are verbatim from the operation table). */
    public const SHIPMENTS_PATH = '/shipments';
    public const SHIPMENTS_CANCELLATION_PATH = '/shipments/cancellation';
    public const LABELS_PATH = '/labels';
    public const LABELS_REPRINT_PATH = '/labels/reprint';
    public const PICKUP_PATH = '/pickup';

    /** Routing/geodata paths (DPD-018…DPD-024; §4.1). */
    public const ROUTING_CITY_VALIDATION_PATH = '/routing/city-validation';
    public const ROUTING_GET_CITY_PATH = '/routing/get-city';
    public const ROUTING_POSTCODE_VALIDATION_PATH = '/routing/postcode-validation';
    public const ROUTING_PRODUCT_SELECTION_PATH = '/routing/product-selection';
    public const ROUTING_ROUTE_CALCULATION_PATH = '/routing/route-calculation';
    public const ROUTING_ROUTE_CALCULATION_ELEMENT_PATH = '/routing/route-calculation-with-element';
    public const ROUTING_COLLECTION_CALCULATION_PATH = '/routing/route-collection-calculation';

    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 30;

    public const TOKEN_FIELD = 'token';
    public const BU_CODE_FIELD = 'bu_code';

    private string $mode;
    private string $token;
    private string $buCode;
    private string $accountId = 'default';

    /**
     * @param string      $mode    'live'|'test' (anything else is treated as test; §2.2)
     * @param string|null $token   bearer JWT; read from CredentialStore key `token` when null
     * @param string|null $buCode  DPD-BU-ID; read from ModuleSettings key `bu_code` when null
     * @param string|null $baseUrl explicit override (tests only); defaults to PREPROD in test mode
     */
    public function __construct(
        string $mode = 'test',
        ?string $token = null,
        ?string $buCode = null,
        ?string $baseUrl = null
    ) {
        $this->mode = $mode === 'live' ? 'live' : 'test';

        // Resolve from ModuleSettings only when needed and only when WordPress is available, so the client
        // is constructible (and header-shape testable) without WordPress.
        if (($token === null || $buCode === null) && function_exists('get_option')) {
            $settings = new ModuleSettings();
            $this->accountId = $settings->accountId(self::PROVIDER);
            if ($token === null) {
                $token = $settings->secret(self::PROVIDER, self::TOKEN_FIELD);
            }
            if ($buCode === null) {
                $buCode = $settings->get(self::PROVIDER, self::BU_CODE_FIELD, '');
            }
        }

        $this->token = trim((string) ($token ?? ''));
        $this->buCode = trim((string) ($buCode ?? ''));

        parent::__construct($baseUrl ?? ($this->mode === 'live' ? self::LIVE_BASE : self::TEST_BASE));
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The bearer token is provisioned manually; token acquisition is undocumented (DPD-U1/G10). */
    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public function hasBuCode(): bool
    {
        return $this->buCode !== '';
    }

    /**
     * Headers for one authorized Shipment call (§2.1). A secret-free, pure function so request shaping is
     * unit-testable without WordPress. The bearer value is never logged or returned by probe().
     *
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    public function buildAuthorizedHeaders(array $extra = []): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($this->buCode !== '') {
            $headers['DPD-BU-ID'] = $this->buCode;
        }
        return array_merge($headers, $extra);
    }

    /**
     * Cheap non-mutating capability probe. `jwt_scheme_unknown` records that a missing token cannot be
     * resolved by this client (no documented acquisition endpoint).
     *
     * @return array{ok:bool,message:string,blocked:list<string>,reason:?string,mode:string}
     */
    public function probe(): array
    {
        if ($this->hasToken()) {
            return ['ok' => true, 'message' => 'dpd client ready (bearer provisioned)', 'blocked' => [], 'reason' => null, 'mode' => $this->mode];
        }
        return [
            'ok' => false,
            'message' => 'blocked: jwt_scheme_unknown (token acquisition endpoint is undocumented)',
            'blocked' => ['jwt_scheme_unknown'],
            'reason' => 'jwt_scheme_unknown',
            'mode' => $this->mode,
        ];
    }

    // ---- DPD-001/002/003/005/006/007/008/004 --------------------------------------------------------

    /**
     * DPD-001 `POST /shipments` — one request carries `shipments[]` (MPS native, §4.1; NO idempotency key,
     * re-POST creates a new shipment → auto create-retry NOT safe, §7).
     *
     * @param array<string,mixed> $shipmentRequest `{buCode,customerId,shipments:[...]}`
     */
    public function createShipment(array $shipmentRequest): Response
    {
        return $this->json('POST', self::SHIPMENTS_PATH, $shipmentRequest);
    }

    /** DPD-005 `GET /shipments/{id}` (§4.1). */
    public function getShipment(int|string $shipmentId): Response
    {
        return $this->json('GET', self::SHIPMENTS_PATH . '/' . rawurlencode($this->tokenize((string) $shipmentId)));
    }

    /**
     * DPD-006 `GET /labels` — `shipmentId` or `parcelNumber` plus labelSize/printFormat/dpi/parcelonly (§4.1).
     *
     * @param array<string,mixed> $query
     */
    public function getLabel(array $query): Response
    {
        return $this->json('GET', self::LABELS_PATH, [], $query);
    }

    /**
     * DPD-008 `POST /labels/reprint` — reprint by shipment id list (§4.1).
     *
     * @param array<string,mixed> $payload
     */
    public function reprintLabel(array $payload): Response
    {
        return $this->json('POST', self::LABELS_REPRINT_PATH, $payload);
    }

    /**
     * DPD-004 `PUT /shipments/cancellation` — `{buCode,customerId,shipmentIdList[]}` (§4.1; no idempotency
     * key, cancel is by shipment id list).
     *
     * @param array<string,mixed> $payload
     */
    public function cancelShipment(array $payload): Response
    {
        return $this->json('PUT', self::SHIPMENTS_CANCELLATION_PATH, $payload);
    }

    // ---- routing / geodata (DPD-018…DPD-024) --------------------------------------------------------

    /** DPD-018 `GET /routing/city-validation` (§4.1). */
    public function cityValidation(string $city, string $country): Response
    {
        return $this->json('GET', self::ROUTING_CITY_VALIDATION_PATH, [], ['city' => $city, 'countryCode' => strtoupper($country)]);
    }

    /** DPD-019 `GET /routing/get-city` (§4.1). */
    public function getCity(string $country, string $postcode): Response
    {
        return $this->json('GET', self::ROUTING_GET_CITY_PATH, [], ['countryCode' => strtoupper($country), 'zipCode' => $postcode]);
    }

    /** DPD-020 `GET /routing/postcode-validation` (§4.1). */
    public function postcodeValidation(string $country, string $postcode): Response
    {
        return $this->json('GET', self::ROUTING_POSTCODE_VALIDATION_PATH, [], ['countryCode' => strtoupper($country), 'zipCode' => $postcode]);
    }

    /**
     * DPD-021 `GET /routing/product-selection` — returns routing (`asCode`,`soCode`,`depot`), **no price**
     * (04_DPD §3.8: no live-rate API; shipping fee is shop-configured).
     *
     * @param array<string,mixed> $query
     */
    public function productSelection(array $query): Response
    {
        return $this->json('GET', self::ROUTING_PRODUCT_SELECTION_PATH, [], $query);
    }

    /**
     * @param array<string,mixed> $query
     */
    public function routeCalculation(array $query): Response
    {
        return $this->json('GET', self::ROUTING_ROUTE_CALCULATION_PATH, [], $query);
    }

    /**
     * @param array<string,mixed> $query
     */
    public function routeCalculationWithElement(array $query): Response
    {
        return $this->json('GET', self::ROUTING_ROUTE_CALCULATION_ELEMENT_PATH, [], $query);
    }

    /**
     * @param array<string,mixed> $query
     */
    public function collectionRouteCalculation(array $query): Response
    {
        return $this->json('GET', self::ROUTING_COLLECTION_CALCULATION_PATH, [], $query);
    }

    // ---- transport ----------------------------------------------------------------------------------

    /**
     * @param array{body?:mixed,headers?:array<string,string>} $args
     */
    private function authorized(string $method, string $url, array $args): Response
    {
        $request = ['headers' => $this->buildAuthorizedHeaders($args['headers'] ?? [])];
        if (array_key_exists('body', $args) && $args['body'] !== null) {
            $request['body'] = $args['body'];
        }

        $response = $this->request($method, $url, $request);

        // No token refresh: the JWT acquisition scheme is undocumented (DPD-U1/G10). A 401 is surfaced as-is.
        return $response;
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
        if (in_array($method, ['POST', 'PUT'], true) && $body !== []) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body);
            $args['body'] = is_string($encoded) ? $encoded : '{}';
            $args['headers'] = ['Content-Type' => 'application/json'];
        }

        return $this->authorized($method, $url, $args);
    }

    /** Guard a path segment against path traversal/encoding surprises. */
    private function tokenize(string $value): string
    {
        $value = trim($value);
        return preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';
    }
}
