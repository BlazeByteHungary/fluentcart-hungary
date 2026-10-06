<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Modules\Shipping\Packeta\PacketaModule;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

/**
 * Packeta PUDO feed provider (04_PACKETA_CONTRACT.md §2.2, §5.3; matrix PKT-030/031/032).
 *
 * Feeds (v5, apiKey URL segment — never the apiPassword):
 *   GET {base}/v5/{apiKey}/branch/json         -> Packeta pick-up points  (type PP)
 *   GET {base}/v5/{apiKey}/box/json            -> Packeta Z-BOXes         (type Z-BOX)
 *   GET {base}/v5/{apiKey}/carrier/json        -> home-delivery carrier list
 *   GET {base}/v5/{apiKey}/carrier_point/json  -> external carrier points (ids[] filter)
 * base = `https://pickup-point.api.packeta.com`. Feeds are cached by the caller (PickupDirectory); the
 * checkout path never touches the network. No feed from a non-200 response is ever invented.
 *
 * Static override: the option `fch_pickup_packeta_static` (array or JSON `{points:[…]}`) is used when the
 * operator wants a curated subset or the live feed is unavailable. `fetch()` returns
 * `packeta_credentials_missing` when no apiKey is configured and no static list is present.
 */
final class PacketaPoints implements PickupFeedProviderInterface
{
    public const FEED_BASE = 'https://pickup-point.api.packeta.com';
    public const STATIC_OPTION = 'fch_pickup_packeta_static';

    public const TYPE_PICKUP_POINT = 'PP';
    public const TYPE_ZBOX = 'Z-BOX';
    public const TYPE_EXTERNAL = 'EXTERNAL';

    public function carrier(): string
    {
        return PacketaModule::CARRIER;
    }

    /**
     * @param array{postCode?:string,postCodeOnLabel?:string,city?:string,country?:string,limit?:int} $query
     * @param object|null $client injectable client (must expose `get(string,array): object{isSuccess,json,status}`)
     * @return array{ok:bool,points:list<array<string,mixed>>,error:?string}
     */
    public function fetch(array $query = [], ?object $client = null): array
    {
        $static = $this->staticPoints();
        if ($static !== null) {
            return ['ok' => true, 'points' => $this->applyLimit($static, $query), 'error' => null];
        }

        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'points' => [], 'error' => 'packeta_credentials_missing'];
        }

        $http = $this->resolveClient($client);
        if ($http === null) {
            return ['ok' => false, 'points' => [], 'error' => 'packeta_feed_client_unavailable'];
        }

        $country = strtoupper(trim((string) ($query['country'] ?? 'HU')));
        $filters = ['lang' => 'en', 'country' => strtolower($country)];

        $points = [];
        foreach (['branch' => self::TYPE_PICKUP_POINT, 'box' => self::TYPE_ZBOX] as $feed => $type) {
            $response = $http->get('/v5/' . rawurlencode($apiKey) . '/' . $feed . '/json', $filters);
            if (!is_object($response) || !method_exists($response, 'isSuccess') || !$response->isSuccess()) {
                $status = is_object($response) && isset($response->status) ? (int) $response->status : 0;
                return ['ok' => false, 'points' => [], 'error' => 'packeta_feed_http_' . $status];
            }
            $rows = method_exists($response, 'json') ? (array) $response->json() : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $point = $feed === 'box' ? self::mapBox($row) : self::mapBranch($row);
                if ($point !== null) {
                    $points[] = $point;
                }
            }
        }

        return ['ok' => true, 'points' => $this->applyLimit($points, $query), 'error' => null];
    }

    /**
     * PKT-031 carrier list feed. Returns minimal carrier descriptors ({id,name,available,pickupPoints}).
     *
     * @return array{ok:bool,carriers:list<array<string,mixed>>,error:?string}
     */
    public function fetchCarriers(?object $client = null): array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'carriers' => [], 'error' => 'packeta_credentials_missing'];
        }
        $http = $this->resolveClient($client);
        if ($http === null) {
            return ['ok' => false, 'carriers' => [], 'error' => 'packeta_feed_client_unavailable'];
        }
        $response = $http->get('/v5/' . rawurlencode($apiKey) . '/carrier/json', []);
        if (!is_object($response) || !method_exists($response, 'isSuccess') || !$response->isSuccess()) {
            $status = is_object($response) && isset($response->status) ? (int) $response->status : 0;
            return ['ok' => false, 'carriers' => [], 'error' => 'packeta_feed_http_' . $status];
        }
        $carriers = [];
        foreach (method_exists($response, 'json') ? (array) $response->json() : [] as $row) {
            if (is_array($row)) {
                $carriers[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'available' => (string) ($row['available'] ?? ''),
                    'pickup_points' => (string) ($row['pickupPoints'] ?? ''),
                    'customs_declarations' => (string) ($row['customsDeclarations'] ?? ''),
                ];
            }
        }
        return ['ok' => true, 'carriers' => $carriers, 'error' => null];
    }

    /**
     * PKT-032 external carrier point feed. `ids` limits by carrier id.
     *
     * @param list<string> $ids
     * @return array{ok:bool,points:list<array<string,mixed>>,error:?string}
     */
    public function fetchCarrierPoints(array $ids = [], ?object $client = null): array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'points' => [], 'error' => 'packeta_credentials_missing'];
        }
        $http = $this->resolveClient($client);
        if ($http === null) {
            return ['ok' => false, 'points' => [], 'error' => 'packeta_feed_client_unavailable'];
        }

        $query = [];
        $cleanIds = array_values(array_filter(array_map('strval', $ids), static fn(string $v): bool => $v !== ''));
        if ($cleanIds !== []) {
            $query['ids'] = $cleanIds;
        }

        $response = $http->get('/v5/' . rawurlencode($apiKey) . '/carrier_point/json', $query);
        if (!is_object($response) || !method_exists($response, 'isSuccess') || !$response->isSuccess()) {
            $status = is_object($response) && isset($response->status) ? (int) $response->status : 0;
            return ['ok' => false, 'points' => [], 'error' => 'packeta_feed_http_' . $status];
        }

        $data = method_exists($response, 'json') ? (array) $response->json() : [];
        $points = [];
        foreach (self::asList($data['carriers'] ?? []) as $carrier) {
            if (!is_array($carrier)) {
                continue;
            }
            foreach (self::asList($carrier['points'] ?? []) as $point) {
                if (is_array($point)) {
                    $mapped = self::mapCarrierPoint($carrier, $point);
                    if ($mapped !== null) {
                        $points[] = $mapped;
                    }
                }
            }
        }
        return ['ok' => true, 'points' => $points, 'error' => null];
    }

    // ---- canonical mapping (pure) -------------------------------------------------------------------

    /**
     * Map one Packeta branch (`/branch/json`) to the canonical point shape. Type is always `PP`
     * (04 `PACKETA_DOKS.md` §Packeta PUDOs branch feed).
     *
     * @param array<string,mixed> $row
     */
    public static function mapBranch(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        return [
            'point_id' => $id,
            'carrier' => PacketaModule::CARRIER,
            'name' => (string) ($row['labelName'] ?? $row['name'] ?? ''),
            'address' => self::street($row),
            'zip' => (string) ($row['zip'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'type' => self::TYPE_PICKUP_POINT,
            'lat' => self::coord($row, 'latitude'),
            'long' => self::coord($row, 'longitude'),
            'raw' => $row,
        ];
    }

    /**
     * Map one Packeta Z-BOX (`/box/json`) to the canonical point shape; type is `Z-BOX`
     * (`PACKETA_DOKS.md` §Z-BOX attributes: `type: zbox`).
     *
     * @param array<string,mixed> $row
     */
    public static function mapBox(array $row): ?array
    {
        $point = self::mapBranch($row);
        if ($point === null) {
            return null;
        }
        $point['type'] = self::TYPE_ZBOX;
        return $point;
    }

    /**
     * Map one external carrier point (nested `{carriers:[{id,name,points:[{code,…}]}]}`).
     *
     * @param array<string,mixed> $carrier @param array<string,mixed> $point
     */
    public static function mapCarrierPoint(array $carrier, array $point): ?array
    {
        $carrierId = trim((string) ($carrier['id'] ?? ''));
        $code = trim((string) ($point['code'] ?? ''));
        if ($carrierId === '' || $code === '') {
            return null;
        }
        $coordinates = is_array($point['coordinates'] ?? null) ? $point['coordinates'] : [];
        $street = trim((string) ($point['street'] ?? ''));
        $number = trim((string) ($point['streetNumber'] ?? ''));
        return [
            'point_id' => $carrierId . ':' . $code,
            'carrier' => PacketaModule::CARRIER,
            'name' => (string) ($carrier['name'] ?? ''),
            'address' => trim($street . ' ' . $number),
            'zip' => (string) ($point['zip'] ?? ''),
            'city' => (string) ($point['city'] ?? ''),
            'type' => self::TYPE_EXTERNAL,
            'carrier_id' => $carrierId,
            'carrier_pickup_point' => $code,
            'lat' => isset($coordinates['latitude']) && is_numeric($coordinates['latitude']) ? (float) $coordinates['latitude'] : null,
            'long' => isset($coordinates['longitude']) && is_numeric($coordinates['longitude']) ? (float) $coordinates['longitude'] : null,
            'raw' => ['carrier' => $carrier, 'point' => $point],
        ];
    }

    // ---- helpers ------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>>|null null when no static override is configured */
    private function staticPoints(): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }
        $stored = get_option(self::STATIC_OPTION, null);
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($stored) || $stored === []) {
            return null;
        }
        if (isset($stored['points']) && is_array($stored['points'])) {
            $stored = $stored['points'];
        }
        $points = [];
        foreach ($stored as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $point = PickupDirectory::normalizePoint($raw, PacketaModule::CARRIER);
            if ($point !== null) {
                $points[] = $point;
            }
        }
        return $points === [] ? null : $points;
    }

    /** @param list<array<string,mixed>> $points @return list<array<string,mixed>> */
    private function applyLimit(array $points, array $query): array
    {
        $limit = (int) ($query['limit'] ?? 0);
        return $limit > 0 ? array_slice($points, 0, $limit) : $points;
    }

    private function resolveClient(?object $client): ?object
    {
        if (is_object($client) && method_exists($client, 'get')) {
            return $client;
        }
        if ($client !== null) {
            return null;
        }
        return new BaseClient(self::FEED_BASE);
    }

    private static function apiKey(): string
    {
        try {
            return trim((string) PacketaModule::apiKey());
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @param array<string,mixed> $row */
    private static function street(array $row): string
    {
        $street = trim((string) ($row['street'] ?? ''));
        $special = trim((string) ($row['special'] ?? ''));
        if ($special !== '') {
            $street = trim($street . ' (' . $special . ')');
        }
        return $street;
    }

    /** @param array<string,mixed> $row */
    private static function coord(array $row, string $key): ?float
    {
        return isset($row[$key]) && is_numeric($row[$key]) ? (float) $row[$key] : null;
    }

    /** @return list<mixed> */
    private static function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_is_list($value) ? array_values($value) : [$value];
    }
}
