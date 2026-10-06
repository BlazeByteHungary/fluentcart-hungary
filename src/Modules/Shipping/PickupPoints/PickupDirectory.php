<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Modules\Shipping\Mpl\MplClient;
use FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry;

/**
 * Cached pickup-point directory (UX §4.1 "caching"; constraints §2 pickup point).
 *
 * Storage: one NON-autoload option per carrier `fch_pickup_dir_{carrier}` =
 * {fetched_at, last_success_at, last_attempt_at, last_error, attempts, source, ttl, version, points[]}.
 * This deliberately avoids the legacy 5.7 MB autoload-off option bloat (docs/discovery/01_LEGACY_DB_MAP.md §7.8)
 * and keeps the checkout path network-free.
 *
 * Lifecycle guarantees (checkout 2/3 goal):
 *  - a failed or empty refresh NEVER overwrites the last-good list; it records the attempt + error and
 *    applies a backoff window (see {@see PickupPointsModule::maybeEnqueueRefresh()});
 *  - `version` changes only when the point set changes, so the UI can detect a real directory update;
 *  - the common search (`searchMulti`) filters by carrier/kind/query and paginates deterministically.
 *
 * Sources:
 *  - static import (`importStatic`) from a checked-in / operator-supplied JSON file;
 *  - live fetch through a registered feed provider ({@see PickupFeedRegistry}), including the public
 *    Foxpost CDN feed ({@see Providers\FoxpostPoints}) and the MPL delivery-place API.
 *
 * The filtering/normalisation helpers are static and pure so they are unit-testable without WordPress.
 */
final class PickupDirectory
{
    public const OPTION_PREFIX = 'fch_pickup_dir_';
    public const DEFAULT_TTL = 86400;
    public const MPL_CARRIER = 'mpl';

    /** MPL-004 `servicePointType` enum (04_MPL §3.2, §4 MPL-004). */
    public const MPL_SERVICE_POINT_TYPES = ['PM', 'CS', 'PP'];

    private int $ttl;

    public function __construct(int $ttl = self::DEFAULT_TTL)
    {
        $this->ttl = max(60, $ttl);
    }

    public function optionName(string $carrier): string
    {
        return self::OPTION_PREFIX . preg_replace('/[^a-z0-9_]/', '', strtolower($carrier)) ?: 'unknown';
    }

    /**
     * @return array{fetched_at:int,last_success_at:int,last_attempt_at:int,last_error:string,attempts:int,source:string,ttl:int,version:string,points:list<array<string,mixed>>}
     */
    public function load(string $carrier): array
    {
        $empty = [
            'fetched_at' => 0,
            'last_success_at' => 0,
            'last_attempt_at' => 0,
            'last_error' => '',
            'attempts' => 0,
            'source' => 'none',
            'ttl' => $this->ttl,
            'version' => '',
            'points' => [],
        ];
        $stored = function_exists('get_option') ? get_option($this->optionName($carrier), []) : [];
        if (!is_array($stored) || !isset($stored['points']) || !is_array($stored['points'])) {
            return $empty;
        }
        $points = array_values(array_filter($stored['points'], 'is_array'));
        return [
            'fetched_at' => (int) ($stored['fetched_at'] ?? 0),
            'last_success_at' => (int) ($stored['last_success_at'] ?? $stored['fetched_at'] ?? 0),
            'last_attempt_at' => (int) ($stored['last_attempt_at'] ?? 0),
            'last_error' => (string) ($stored['last_error'] ?? ''),
            'attempts' => max(0, (int) ($stored['attempts'] ?? 0)),
            'source' => (string) ($stored['source'] ?? 'unknown'),
            'ttl' => (int) ($stored['ttl'] ?? $this->ttl),
            'version' => (string) ($stored['version'] ?? self::versionOf($points)),
            'points' => $points,
        ];
    }

    /**
     * Store a successful refresh. `version` only changes when the point set really changed; a failure path
     * must NOT call this (see {@see recordFailure()}).
     *
     * @param list<array<string,mixed>> $points canonical points
     */
    public function save(string $carrier, array $points, string $source, ?int $ttl = null, ?int $fetchedAt = null): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        $points = array_values($points);
        $now = $fetchedAt ?? time();
        $previous = $this->load($carrier);
        $version = self::versionOf($points);
        if ($previous['version'] !== '' && $previous['version'] === $version) {
            // Identical content: keep the old version so the UI does not reset for a no-op refresh.
            $version = $previous['version'];
        }
        update_option($this->optionName($carrier), [
            'fetched_at' => $now,
            'last_success_at' => $now,
            'last_attempt_at' => $now,
            'last_error' => '',
            'attempts' => 0,
            'source' => $source,
            'ttl' => max(60, $ttl ?? $this->ttl),
            'version' => $version,
            'points' => $points,
        ], false);
    }

    /**
     * Record a failed refresh attempt WITHOUT touching the last-good points / fetched_at / version.
     */
    public function recordFailure(string $carrier, string $error, ?int $attemptedAt = null): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        $entry = $this->load($carrier);
        $entry['last_attempt_at'] = $attemptedAt ?? time();
        $entry['last_error'] = substr(trim($error), 0, 190);
        $entry['attempts'] = $entry['attempts'] + 1;
        update_option($this->optionName($carrier), $entry, false);
    }

    /** Content-derived version (same points → same version, independent of fetch time). */
    public static function versionOf(array $points): string
    {
        return substr(sha1((string) json_encode($points)), 0, 12);
    }

    /**
     * Lifecycle state of one directory entry (3/3 gate #3):
     *  - fresh   : never fetched OR age ≤ ttl — selectable;
     *  - stale   : ttl < age ≤ ttl × expiry multiplier — still selectable, visibly flagged;
     *  - expired : older than that (or ttl ≤ 0) — NOT selectable; last-good is kept for ops, but a
     *              selection can no longer be persisted from it.
     */
    public static function stateAt(array $entry, int $now, ?int $expiryMultiplier = null): string
    {
        $fetchedAt = (int) ($entry['fetched_at'] ?? 0);
        if ($fetchedAt <= 0 || count((array) ($entry['points'] ?? [])) === 0) {
            return 'expired';
        }
        $ttl = max(0, (int) ($entry['ttl'] ?? self::DEFAULT_TTL));
        if ($ttl <= 0) {
            return 'expired';
        }
        $age = max(0, $now - $fetchedAt);
        $multiplier = $expiryMultiplier ?? self::expiryMultiplier();
        if ($age <= $ttl) {
            return 'fresh';
        }
        return $age <= ($ttl * max(1, $multiplier)) ? 'stale' : 'expired';
    }

    /** Explicit, documented expiry policy setting (shipping scope); default 2× ttl. */
    public static function expiryMultiplier(): int
    {
        try {
            $value = (int) (new \FluentCartHungary\Core\Settings\ModuleSettings())->get('shipping', 'pickup_expiry_multiplier', 2);
        } catch (\Throwable $e) {
            return 2;
        }
        return max(1, min(30, $value === 0 ? 2 : $value));
    }

    /**
     * Stable version fingerprint of the directories involved in one paginated search; the client sends
     * it back with "load more" so pages from different directory generations can never be mixed.
     *
     * @param list<string> $carriers
     */
    public function versionSet(array $carriers): string
    {
        $parts = [];
        foreach (array_values(array_unique(array_map('strval', $carriers))) as $carrier) {
            $parts[$carrier] = (string) ($this->load($carrier)['version'] ?? '');
        }
        ksort($parts);
        return substr(sha1((string) json_encode($parts)), 0, 16);
    }

    /**
     * Import a static list. Accepts either a flat list or `{points:[...]}`.
     *
     * @param array<mixed> $points
     * @return int number of usable points stored
     */
    public function importStatic(array $points, string $carrier, ?int $ttl = null): int
    {
        if (isset($points['points']) && is_array($points['points'])) {
            $points = $points['points'];
        }

        $normalized = [];
        foreach ($points as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $point = self::normalizePoint($raw, $carrier);
            if ($point !== null) {
                $normalized[] = $point;
            }
        }

        $this->save($carrier, $normalized, 'static', $ttl);
        return count($normalized);
    }

    /** @return list<array<string,mixed>> */
    /**
     * Map an official widget payload id back to a cached directory point (checkout 3/3 gate #4).
     * Exact, documented identity only: the feed's `operator_id` is the integration id (widget payload
     * `operator_id`), `place_id` is the legacy fallback; ids are compared case-insensitively. The widget
     * payload itself is NEVER saved — the resolved directory point goes through the normal
     * offers/validation/snapshot path.
     */
    public function findByWidgetId(string $carrier, string $widgetId): ?array
    {
        $carrier = preg_replace('/[^a-z0-9_]/', '', strtolower($carrier)) ?: '';
        $widgetId = trim($widgetId);
        if ($carrier === '' || $widgetId === '') {
            return null;
        }
        $needle = function_exists('mb_strtolower') ? mb_strtolower($widgetId) : strtolower($widgetId);
        $numericFallback = [];
        foreach ($this->points($carrier) as $point) {
            $pointId = trim((string) ($point['point_id'] ?? ''));
            if ($pointId !== '' && strtolower($pointId) === $needle) {
                return $point;
            }
            $raw = is_array($point['raw'] ?? null) ? $point['raw'] : [];
            foreach (['operator_id', 'place_id'] as $key) {
                $value = trim((string) ($raw[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (strtolower($value) === $needle) {
                    return $point;
                }
                if ($key === 'place_id' && $numericFallback === [] && is_numeric($value) && is_numeric($needle) && (float) $value === (float) $needle) {
                    $numericFallback[] = $point; // numeric place_id: tolerate "1250198" vs 1250198
                }
            }
        }
        return $numericFallback[0] ?? null;
    }

    public function points(string $carrier): array
    {
        return $this->load($carrier)['points'];
    }

    public function get(string $carrier, string $pointId): ?array
    {
        $needle = (string) $pointId;
        if ($needle === '') {
            return null;
        }
        foreach ($this->points($carrier) as $point) {
            if ((string) ($point['point_id'] ?? '') === $needle) {
                return $point;
            }
        }
        return null;
    }

    /**
     * Pure local search over zip/city/name — never network (checkout path requirement, UX §4.1).
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $carrier, string $query, int $limit = 30): array
    {
        return self::filterPoints($this->points($carrier), $query, $limit);
    }

    public function isFresh(string $carrier, ?int $now = null): bool
    {
        return self::isFreshAt($this->load($carrier), $now ?? time());
    }

    public function ageSeconds(string $carrier, ?int $now = null): ?int
    {
        return self::ageAt($this->load($carrier), $now ?? time());
    }

    /**
     * Generic refresh: use the carrier's registered feed provider, falling back to the built-in MPL path.
     * Caches the canonical points on success only. A provider error or an empty feed keeps the last-good
     * list and records the failed attempt. Never called from the checkout path (only WP-CLI/admin/cron).
     *
     * @param array{postCode?:string,postCodeOnLabel?:string,city?:string} $query
     * @return array{ok:bool,count:int,error:?string,kept:int}
     */
    public function refresh(string $carrier, array $query = [], ?object $client = null): array
    {
        $carrier = preg_replace('/[^a-z0-9_]/', '', strtolower($carrier)) ?: '';
        if ($carrier === '') {
            return ['ok' => false, 'count' => 0, 'error' => 'invalid_carrier', 'kept' => 0];
        }

        $provider = PickupFeedRegistry::get($carrier);
        if ($provider !== null) {
            $result = $provider->fetch($query, $client);
            if (!$result['ok']) {
                $error = (string) ($result['error'] ?? 'feed_failed');
                $kept = count($this->points($carrier)); // failure path only: the last-good list stays
                $this->recordFailure($carrier, $error);
                return ['ok' => false, 'count' => 0, 'error' => $error, 'kept' => $kept];
            }
            $points = array_values(array_filter($result['points'], static fn ($point): bool => is_array($point)));
            if ($points === []) {
                // An empty success is treated as a failure: never wipe a working directory silently.
                $kept = count($this->points($carrier));
                $this->recordFailure($carrier, 'empty_feed');
                return ['ok' => false, 'count' => 0, 'error' => 'empty_feed', 'kept' => $kept];
            }
            $this->save($carrier, $points, $carrier);
            return ['ok' => true, 'count' => count($points), 'error' => null, 'kept' => 0];
        }

        if ($carrier === self::MPL_CARRIER) {
            return $this->refreshMpl($query, $client instanceof MplClient ? $client : null);
        }
        $this->recordFailure($carrier, 'no_feed_provider');
        return ['ok' => false, 'count' => 0, 'error' => 'no_feed_provider', 'kept' => count($this->points($carrier))];
    }

    /**
     * Common multi-carrier search: filter by query + abstract kinds across the eligible carriers, sort
     * deterministically (zip, name, point_id) and paginate. Pure local work — never network.
     *
     * @param list<string> $carriers eligible carriers
     * @param list<string> $kinds abstract kinds (point|locker); empty = any
     * @return array{points:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int,per_carrier:array<string,int>}
     */
    public function searchMulti(array $carriers, string $query = '', array $kinds = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $kinds = CarrierBinding::parseKinds(implode(',', array_map('strval', $kinds)));

        $merged = [];
        $perCarrier = [];
        foreach (array_values(array_unique(array_map('strval', $carriers))) as $carrier) {
            $carrier = preg_replace('/[^a-z0-9_]/', '', strtolower($carrier)) ?: '';
            if ($carrier === '') {
                continue;
            }
            $carrierPoints = self::matches($this->points($carrier), $query, $kinds);
            $perCarrier[$carrier] = count($carrierPoints);
            foreach ($carrierPoints as $point) {
                $merged[] = $point;
            }
        }

        usort($merged, static function (array $a, array $b): int {
            $zip = strnatcasecmp((string) ($a['zip'] ?? ''), (string) ($b['zip'] ?? ''));
            if ($zip !== 0) {
                return $zip;
            }
            $name = strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            if ($name !== 0) {
                return $name;
            }
            return strnatcasecmp((string) ($a['point_id'] ?? ''), (string) ($b['point_id'] ?? ''));
        });

        $total = count($merged);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $slice = array_slice($merged, ($page - 1) * $perPage, $perPage);

        return [
            'points' => array_values($slice),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'per_carrier' => $perCarrier,
        ];
    }

    /**
     * Filter points by query + kinds in the deterministic directory order (per carrier).
     *
     * @param list<array<string,mixed>> $points
     * @param list<string> $kinds abstract kinds; empty = any
     * @return list<array<string,mixed>>
     */
    public static function matches(array $points, string $query, array $kinds = []): array
    {
        $kinds = CarrierBinding::parseKinds(implode(',', array_map('strval', $kinds)));
        $query = self::normalize($query);
        $matches = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            if ($kinds !== []) {
                $kind = CarrierBinding::kindOfPoint($point);
                if ($kind === '' || !in_array($kind, $kinds, true)) {
                    continue;
                }
            }
            if ($query !== '') {
                $haystack = [];
                foreach (['zip', 'city', 'name', 'address'] as $field) {
                    $haystack[] = self::normalize((string) ($point[$field] ?? ''));
                }
                if (!str_contains(implode(' ', $haystack), $query)) {
                    continue;
                }
            }
            $matches[] = $point;
        }
        return $matches;
    }

    /**
     * Fetch all MPL fixed points (04_MPL §4 MPL-004) and cache them. Gated on credentials; never called from
     * the checkout path (only WP-CLI/admin/cron).
     *
     * @param array{postCode?:string,postCodeOnLabel?:string,city?:string} $query
     * @return array{ok:bool,count:int,error:?string,kept:int}
     */
    public function refreshMpl(array $query = [], ?MplClient $client = null): array
    {
        $kept = count($this->points(self::MPL_CARRIER));
        if (!class_exists(MplClient::class)) {
            return ['ok' => false, 'count' => 0, 'error' => 'mpl_client_missing', 'kept' => $kept];
        }

        try {
            $client ??= new MplClient('test');
        } catch (\Throwable $e) {
            return ['ok' => false, 'count' => 0, 'error' => 'mpl_client_unavailable', 'kept' => $kept];
        }

        if (!$client->hasCredentials()) {
            $this->recordFailure(self::MPL_CARRIER, 'mpl_credentials_missing');
            return ['ok' => false, 'count' => 0, 'error' => 'mpl_credentials_missing', 'kept' => $kept];
        }

        $response = $client->queryDeliveryPlaces($query, self::MPL_SERVICE_POINT_TYPES);
        if (!$response->isSuccess()) {
            $error = 'mpl_http_' . $response->status;
            $this->recordFailure(self::MPL_CARRIER, $error);
            return ['ok' => false, 'count' => 0, 'error' => $error, 'kept' => $kept];
        }

        $points = [];
        foreach ($response->json() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $point = self::mapMplResult($row, self::MPL_CARRIER);
            if ($point !== null) {
                $points[] = $point;
            }
        }

        if ($points === []) {
            $this->recordFailure(self::MPL_CARRIER, 'empty_feed');
            return ['ok' => false, 'count' => 0, 'error' => 'empty_feed', 'kept' => $kept];
        }

        $this->save(self::MPL_CARRIER, $points, 'mpl');
        return ['ok' => true, 'count' => count($points), 'error' => null, 'kept' => $kept];
    }

    /**
     * Map one MPL `DeliveryPlaceResult` (04_MPL §3.2 / §4 MPL-004: id, deliveryplace, postCode, postCodeOnLabel,
     * city, address, geocodeLat, geocodeLong) to the canonical shape.
     */
    public static function mapMplResult(array $result, string $carrier): ?array
    {
        $id = (string) ($result['id'] ?? '');
        if ($id === '') {
            return null;
        }
        return [
            'point_id' => $id,
            'carrier' => $carrier,
            'name' => (string) ($result['deliveryplace'] ?? $result['name'] ?? ''),
            'address' => (string) ($result['address'] ?? ''),
            'zip' => (string) ($result['postCode'] ?? $result['postCodeOnLabel'] ?? ''),
            'city' => (string) ($result['city'] ?? ''),
            'type' => strtoupper((string) ($result['servicePointType'] ?? $result['type'] ?? '')),
            'lat' => isset($result['geocodeLat']) && is_numeric($result['geocodeLat']) ? (float) $result['geocodeLat'] : null,
            'long' => isset($result['geocodeLong']) && is_numeric($result['geocodeLong']) ? (float) $result['geocodeLong'] : null,
            'raw' => $result,
        ];
    }

    /**
     * Canonicalise one point from either our own canonical keys or provider-ish keys.
     */
    public static function normalizePoint(array $raw, string $carrier): ?array
    {
        $id = (string) ($raw['point_id'] ?? $raw['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $carrier = (string) ($raw['carrier'] ?? $carrier);

        return [
            'point_id' => $id,
            'carrier' => $carrier !== '' ? $carrier : 'unknown',
            'name' => (string) ($raw['name'] ?? $raw['deliveryplace'] ?? $raw['title'] ?? ''),
            'address' => (string) ($raw['address'] ?? ''),
            'zip' => (string) ($raw['zip'] ?? $raw['postCode'] ?? $raw['postCodeOnLabel'] ?? ''),
            'city' => (string) ($raw['city'] ?? ''),
            'type' => strtoupper((string) ($raw['type'] ?? $raw['servicePointType'] ?? '')),
            'lat' => isset($raw['lat']) && is_numeric($raw['lat']) ? (float) $raw['lat'] : null,
            'long' => isset($raw['long']) && is_numeric($raw['long']) ? (float) $raw['long'] : null,
            'raw' => is_array($raw['raw'] ?? null) ? $raw['raw'] : $raw,
        ];
    }

    /**
     * Accent-insensitive, case-insensitive normalisation for HU queries (ó/ő/ű → o, etc.).
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        static $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'û' => 'u', 'ô' => 'o', 'à' => 'a',
            'ä' => 'a', 'ç' => 'c', 'î' => 'i', 'ï' => 'i',
        ];
        return strtr($value, $map);
    }

    /**
     * Pure filtering on zip/city/name. Empty query returns the first `$limit` points.
     *
     * @param list<array<string,mixed>> $points
     * @return list<array<string,mixed>>
     */
    public static function filterPoints(array $points, string $query, int $limit = 30): array
    {
        $limit = max(1, $limit);
        $query = self::normalize($query);
        $matches = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            if ($query === '') {
                $matches[] = $point;
            } else {
                $haystack = [];
                foreach (['zip', 'city', 'name'] as $field) {
                    $haystack[] = self::normalize((string) ($point[$field] ?? ''));
                }
                if (str_contains(implode(' ', $haystack), $query)) {
                    $matches[] = $point;
                }
            }
            if (count($matches) >= $limit) {
                break;
            }
        }
        return $matches;
    }

    public static function isFreshAt(array $entry, int $now): bool
    {
        $fetchedAt = (int) ($entry['fetched_at'] ?? 0);
        if ($fetchedAt <= 0) {
            return false;
        }
        $ttl = (int) ($entry['ttl'] ?? self::DEFAULT_TTL);
        return ($fetchedAt + max(60, $ttl)) > $now;
    }

    public static function ageAt(array $entry, int $now): ?int
    {
        $fetchedAt = (int) ($entry['fetched_at'] ?? 0);
        if ($fetchedAt <= 0) {
            return null;
        }
        return max(0, $now - $fetchedAt);
    }
}
