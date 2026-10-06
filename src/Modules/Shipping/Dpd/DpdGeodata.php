<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

/**
 * Read-only DPD geodata / master-data helpers (04_DPD_CONTRACT.md §4.1 DPD-018…DPD-024, §4.2
 * DPD-040…DPD-050).
 *
 * Documented and implemented (Shipment API, same PREPROD/PROD host as the shipment client):
 *  - DPD-018 validate city            `GET /routing/city-validation`
 *  - DPD-019 get city by postcode     `GET /routing/get-city`
 *  - DPD-020 validate postcode        `GET /routing/postcode-validation`
 *  - DPD-021 select product           `GET /routing/product-selection` (routing only, NO price)
 *  - DPD-022/023/024 route calcs      `GET /routing/route-calculation[-with-element]`,
 *                                     `GET /routing/route-collection-calculation`
 *
 * MISSING_DOC / UNKNOWN — deliberately NOT called, returned as `not_supported` with the matrix id:
 *  - DPD-040/041/043/044/045/046/049/050 Back-Office master data: the documented base is a
 *    `localhost:8086/nst` artifact and the real host is UNKNOWN (DPD-UNK-02, DPD-U2). Calling an invented
 *    host is forbidden.
 *  - DPD-902/DPD-UNK-03 PUDO directory: no "get PUDO list" operation exists in either file (DPD-U3);
 *    pickup inventory comes only from the operator-supplied static feed (DpdPoints), never invented.
 *  - DPD-047/048 public master-data/i18n share the same unknown Back-Office host → not_supported.
 *
 * All results are `{ok:bool, supported:bool, data:mixed, error:?string, reason:?string}`. No network is
 * touched when no bearer is provisioned: the helper reports the `jwt_scheme_unknown` blocker instead.
 */
final class DpdGeodata
{
    private ?DpdClient $client;

    public function __construct(?DpdClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Static capability map for probe/reporting (matrix ids). Not a network call.
     *
     * @return array<string,array{operation:string,status:string,reason:?string}>
     */
    public static function capabilities(): array
    {
        return [
            'DPD-018' => ['operation' => 'Validate city', 'status' => 'documented', 'reason' => null],
            'DPD-019' => ['operation' => 'Get city by country and postcode', 'status' => 'documented', 'reason' => null],
            'DPD-020' => ['operation' => 'Validate postcode', 'status' => 'documented', 'reason' => null],
            'DPD-021' => ['operation' => 'Select product (routing)', 'status' => 'documented', 'reason' => 'no_price_returned'],
            'DPD-022' => ['operation' => 'Calculate route', 'status' => 'documented', 'reason' => 'no_price_returned'],
            'DPD-023' => ['operation' => 'Calculate route with elements', 'status' => 'documented', 'reason' => null],
            'DPD-024' => ['operation' => 'Calculate collection route', 'status' => 'documented', 'reason' => null],
            'DPD-040' => ['operation' => 'Get all product configuration', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-041' => ['operation' => 'Get product by id', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-043' => ['operation' => 'Get all parcel ranges', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-044' => ['operation' => 'Get all elements', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-045' => ['operation' => 'Get reason codes', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-046' => ['operation' => 'Route master data (cities/depots/postcode)', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-047' => ['operation' => 'Public master data (BU/countries/COD types/currencies)', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-048' => ['operation' => 'Public i18n', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-049' => ['operation' => 'Business units (COD/insurance max)', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-050' => ['operation' => 'Customers and addresses', 'status' => 'not_supported', 'reason' => 'backoffice_host_unknown'],
            'DPD-902' => ['operation' => 'PUDO (parcel point/locker) directory', 'status' => 'not_supported', 'reason' => 'pudo_feed_missing_doc'],
        ];
    }

    /** DPD-018. @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string} */
    public function cityValidation(string $city, string $country): array
    {
        return $this->routing('city_validation', ['city' => $city, 'countryCode' => strtoupper($country)]);
    }

    /** DPD-019. @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string} */
    public function cityByPostcode(string $country, string $postcode): array
    {
        return $this->routing('get_city', ['countryCode' => strtoupper($country), 'zipCode' => $postcode]);
    }

    /** DPD-020. @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string} */
    public function postcodeValidation(string $country, string $postcode): array
    {
        return $this->routing('postcode_validation', ['countryCode' => strtoupper($country), 'zipCode' => $postcode]);
    }

    /**
     * DPD-021 — routing only; the response never contains a price (04_DPD §3.8). Shipping fees remain
     * shop-configured.
     *
     * @param array<string,mixed> $query
     * @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string}
     */
    public function productSelection(array $query): array
    {
        return $this->routing('product_selection', $query);
    }

    /**
     * @param array<string,mixed> $query
     * @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string}
     */
    public function routeCalculation(array $query): array
    {
        return $this->routing('route_calculation', $query);
    }

    /**
     * @param array<string,mixed> $query
     * @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string}
     */
    public function routeCalculationWithElement(array $query): array
    {
        return $this->routing('route_calculation_element', $query);
    }

    /**
     * @param array<string,mixed> $query
     * @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string}
     */
    public function collectionRouteCalculation(array $query): array
    {
        return $this->routing('collection_route_calculation', $query);
    }

    /** DPD-040/041/043/044/045/046/049/050 — Back-Office host UNKNOWN (DPD-U2). */
    public function masterData(string $operation): array
    {
        return $this->notSupported('backoffice_host_unknown', 'DPD-UNK-02/DPD-U2: real Back-Office host not documented.');
    }

    /** DPD-902 — PUDO directory MISSING_DOC (DPD-U3); inventory is operator-supplied only (DpdPoints). */
    public function pudoDirectory(): array
    {
        return $this->notSupported('pudo_feed_missing_doc', 'DPD-UNK-03/DPD-U3: no PUDO list operation documented.');
    }

    /**
     * @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string}
     */
    private function notSupported(string $reason, string $message): array
    {
        return ['ok' => false, 'supported' => false, 'data' => null, 'error' => $message, 'reason' => $reason];
    }

    /**
     * @param array<string,mixed> $query
     * @return array{ok:bool,supported:bool,data:mixed,error:?string,reason:?string}
     */
    private function routing(string $operation, array $query): array
    {
        $client = $this->client();
        if ($client === null || !$client->hasToken()) {
            return [
                'ok' => false,
                'supported' => true,
                'data' => null,
                'error' => 'jwt_scheme_unknown',
                'reason' => 'jwt_scheme_unknown',
            ];
        }

        $response = match ($operation) {
            'city_validation' => $client->cityValidation((string) ($query['city'] ?? ''), (string) ($query['countryCode'] ?? 'HU')),
            'get_city' => $client->getCity((string) ($query['countryCode'] ?? 'HU'), (string) ($query['zipCode'] ?? '')),
            'postcode_validation' => $client->postcodeValidation((string) ($query['countryCode'] ?? 'HU'), (string) ($query['zipCode'] ?? '')),
            'product_selection' => $client->productSelection($query),
            'route_calculation' => $client->routeCalculation($query),
            'route_calculation_element' => $client->routeCalculationWithElement($query),
            'collection_route_calculation' => $client->collectionRouteCalculation($query),
            default => null,
        };
        if ($response === null) {
            return $this->notSupported('unknown_operation', 'Unknown routing operation: ' . $operation);
        }
        if ($response->error !== null || $response->status === 0) {
            return ['ok' => false, 'supported' => true, 'data' => null, 'error' => 'timeout', 'reason' => 'request_failed'];
        }
        if ($response->status === 401) {
            return ['ok' => false, 'supported' => true, 'data' => null, 'error' => 'unauthorized', 'reason' => 'jwt_scheme_unknown'];
        }
        if (!$response->isSuccess()) {
            return ['ok' => false, 'supported' => true, 'data' => null, 'error' => 'dpd_http_' . $response->status, 'reason' => 'http_error'];
        }
        return ['ok' => true, 'supported' => true, 'data' => $response->json(), 'error' => null, 'reason' => null];
    }

    private function client(): ?DpdClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $this->client = new DpdClient(DpdModule::mode());
        } catch (\Throwable $e) {
            return null;
        }
        return $this->client;
    }
}
