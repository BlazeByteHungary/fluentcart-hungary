<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

use FluentCartHungary\Modules\Shipping\Gls\GlsClient;
use FluentCartHungary\Modules\Shipping\Gls\GlsModule;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

/**
 * GLS pickup-point feed (GLS-011 `GetDeliveryPoints`, 04_GLS_CONTRACT.md §3.2 / §4).
 *
 * Sources, in order:
 *  1. the operator-supplied option `fch_pickup_gls_static` (a flat list or `{points:[...]}`) — always
 *     preferred, normalized through {@see PickupDirectory::normalizePoint};
 *  2. if that is empty, the documented `GetDeliveryPoints` endpoint. Its `Data` payload is a
 *     GZIP-compressed JSON list, but the field-by-field wire schema is UNKNOWN (GLS-UNK-09 / GLS-U9), so
 *     parsing is defensive: anything that does not decode to a list of usable rows degrades to
 *     `feed_unavailable` rather than inventing inventory.
 *
 * Never fetches from the checkout path; the caller caches the canonical points in the per-carrier option
 * (`PickupDirectory`). Returns the provider contract shape and never throws for expected failures.
 */
final class GlsPoints implements PickupFeedProviderInterface
{
    public const STATIC_OPTION = 'fch_pickup_gls_static';

    private const TYPE_MAP = [1 => 'PARCELSHOP', 2 => 'PARCELBOX', 3 => 'DEPOT'];

    public function carrier(): string
    {
        return GlsModule::CARRIER;
    }

    public function fetch(array $query = [], ?object $client = null): array
    {
        // 1) Operator static import wins (also the only source when no credentials/endpoint are usable).
        $static = function_exists('get_option') ? get_option(self::STATIC_OPTION, []) : [];
        if (is_array($static) && $static !== []) {
            $points = [];
            foreach (self::extractRows($static) as $row) {
                $point = PickupDirectory::normalizePoint(self::mapRow($row), $this->carrier());
                if ($point !== null) {
                    $points[] = $point;
                }
            }
            if ($points !== []) {
                return ['ok' => true, 'points' => $points, 'error' => null];
            }
        }

        // 2) Documented point-finder endpoint (GLS-011); schema is UNKNOWN so parse defensively.
        if (!class_exists(GlsClient::class)) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }
        try {
            $client ??= new GlsClient(GlsModule::mode());
        } catch (\Throwable $e) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }
        if (!method_exists($client, 'hasCredentials') || !$client->hasCredentials()) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        $country = strtoupper(trim((string) ($query['countryIsoCode'] ?? 'HU')));
        $response = $client->getDeliveryPoints($country !== '' ? $country : 'HU', $query['lastUpdateTime'] ?? null);
        if ($response->error !== null || !$response->isSuccess()) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        $data = $response->json();
        if (array_key_exists('IsChanged', $data) && $data['IsChanged'] === false) {
            // Server says the cached client copy is current; nothing new to import here.
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        $rows = self::decodePointData($data['Data'] ?? null);
        if ($rows === []) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        $points = [];
        foreach ($rows as $row) {
            $point = self::normalizeDeliveryPoint($row, $this->carrier());
            if ($point !== null) {
                $points[] = $point;
            }
        }

        return $points === []
            ? ['ok' => false, 'points' => [], 'error' => 'feed_unavailable']
            : ['ok' => true, 'points' => $points, 'error' => null];
    }

    /**
     * Decode `GetDeliveryPoints.Data`: base64(GZIP(JSON)) per §3.2, with tolerant fallbacks (raw JSON,
     * base64 JSON). Returns a list of raw point rows, or [] when anything is unreadable.
     *
     * @return list<array<string,mixed>>
     */
    public static function decodePointData(mixed $data): array
    {
        if (is_array($data)) {
            return self::extractRows($data);
        }
        if (!is_string($data) || trim($data) === '') {
            return [];
        }

        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return self::extractRows($decoded);
        }

        $binary = base64_decode($data, true);
        if (!is_string($binary) || $binary === '') {
            return [];
        }
        $json = null;
        if (str_starts_with($binary, "\x1f\x8b") && function_exists('gzdecode')) {
            $inflated = @gzdecode($binary);
            $json = is_string($inflated) ? $inflated : null;
        }
        if ($json === null) {
            $inflated = @gzinflate($binary);
            $json = is_string($inflated) ? $inflated : null;
        }

        if ($json === null) {
            $decoded = json_decode($binary, true);
            return is_array($decoded) ? self::extractRows($decoded) : [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? self::extractRows($decoded) : [];
    }

    /**
     * Map one `DeliveryPoint` (§3.2: Id, Address, Latitude, Longitude, Matchcode, LegacyId,
     * DeliveryPointType, IsActive, VacationFrom/Till) to the canonical pickup shape, skipping inactive or
     * currently-on-vacation points.
     */
    public static function normalizeDeliveryPoint(array $raw, string $carrier): ?array
    {
        if ($raw === []) {
            return null;
        }
        if (array_key_exists('IsActive', $raw) && $raw['IsActive'] === false) {
            return null;
        }
        if (self::isOnVacation($raw, time())) {
            return null;
        }
        return PickupDirectory::normalizePoint(self::mapRow($raw), $carrier);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> canonical-ish keys for normalizePoint */
    private static function mapRow(array $raw): array
    {
        $type = $raw['DeliveryPointType'] ?? $raw['type'] ?? null;
        $typeLabel = is_numeric($type) ? (self::TYPE_MAP[(int) $type] ?? ('TYPE_' . (int) $type)) : strtoupper((string) $type);

        return [
            'id' => (string) ($raw['Id'] ?? $raw['id'] ?? $raw['Matchcode'] ?? ''),
            'name' => (string) ($raw['Matchcode'] ?? $raw['name'] ?? $raw['Address'] ?? ''),
            'address' => (string) ($raw['Address'] ?? $raw['address'] ?? ''),
            'zip' => (string) ($raw['ZipCode'] ?? $raw['zip'] ?? ''),
            'city' => (string) ($raw['City'] ?? $raw['city'] ?? ''),
            'type' => $typeLabel,
            'lat' => $raw['Latitude'] ?? $raw['lat'] ?? null,
            'long' => $raw['Longitude'] ?? $raw['long'] ?? null,
            'raw' => $raw,
        ];
    }

    /**
     * §3.2: `IsActive`, `VacationFrom`/`VacationTill` holiday handling.
     *
     * @param array<string,mixed> $raw
     */
    public static function isOnVacation(array $raw, int $now): bool
    {
        $from = $raw['VacationFrom'] ?? null;
        $till = $raw['VacationTill'] ?? null;
        if ($from === null || $till === null) {
            return false;
        }
        $fromTs = is_numeric($from) ? (int) $from : strtotime((string) $from);
        $tillTs = is_numeric($till) ? (int) $till : strtotime((string) $till);
        if ($fromTs === false || $tillTs === false) {
            return false;
        }
        return $now >= $fromTs && $now <= $tillTs;
    }

    /**
     * Accept either a flat list or `{points:[...]}` / `{Data:[...]}`.
     *
     * @param array<mixed> $value
     * @return list<array<string,mixed>>
     */
    private static function extractRows(array $value): array
    {
        if (isset($value['points']) && is_array($value['points'])) {
            $value = $value['points'];
        } elseif (isset($value['Data']) && is_array($value['Data'])) {
            $value = $value['Data'];
        } elseif (isset($value['DeliveryPoints']) && is_array($value['DeliveryPoints'])) {
            $value = $value['DeliveryPoints'];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
