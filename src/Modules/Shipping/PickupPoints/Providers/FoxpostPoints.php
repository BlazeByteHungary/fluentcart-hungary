<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

/**
 * Foxpost APM point-list feed, expressed through the W2 provider contract.
 *
 * The contract documents a public, hourly-refreshed CDN JSON feed
 * `https://cdn.foxpost.hu/foxplus.json` (04_FOXPOST_CONTRACT.md §3/§4; live-checked 2026-10-06). This
 * provider deliberately does **not** invent or bundle inventory:
 *  - when a {@see FoxpostFeedClient} is supplied (scheduled refresh / CLI / admin), the live CDN feed is
 *    fetched and normalized — checkout never reaches this path;
 *  - when the operator imports a list into the option `fch_pickup_foxpost_static`, those points are
 *    normalized to the canonical `PickupDirectory::normalizePoint()` shape and returned (offline fallback);
 *  - otherwise it returns `feed_unavailable` and the PickupPoints module serves its stale cache / degrades
 *    to manual selection (constraints §0 kill-switch).
 *
 * Key mapping (guide §4): `operator_id` is the integration id and takes precedence; `place_id` is the
 * legacy fallback when `operator_id` is empty; `geolat`/`geolng` are the coordinates.
 */
final class FoxpostPoints implements PickupFeedProviderInterface
{
    public const CARRIER = 'foxpost';
    public const STATIC_OPTION = 'fch_pickup_foxpost_static';
    public const CDN_URL = FoxpostFeedClient::CDN_URL;
    /** Hard cap on one feed import: never let a runaway response bloat the options table. */
    public const MAX_POINTS = 20000;

    public function carrier(): string
    {
        return self::CARRIER;
    }

    public function fetch(array $query = [], ?object $client = null): array
    {
        if ($client instanceof FoxpostFeedClient) {
            $live = $this->fetchLive($client);
            if ($live !== null) {
                return $live;
            }
            // Live feed failed: fall back to the operator's static import (last resort), never invent.
        }

        $static = function_exists('get_option') ? get_option(self::STATIC_OPTION, []) : [];
        if (is_string($static) && $static !== '') {
            $decoded = json_decode($static, true);
            $static = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($static) || $static === []) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        if (isset($static['points']) && is_array($static['points'])) {
            $static = $static['points'];
        }

        $points = [];
        foreach ($static as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $point = PickupDirectory::normalizePoint(self::canonicalize($raw), $this->carrier());
            if ($point !== null) {
                $points[] = $point;
            }
        }

        if ($points === []) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        return ['ok' => true, 'points' => $points, 'error' => null];
    }

    /**
     * @return array{ok:bool,points:list<array<string,mixed>>,error:?string}|null null = caller should try
     *         the static fallback (transport not available in this context)
     */
    private function fetchLive(FoxpostFeedClient $client): ?array
    {
        $response = $client->fetchAll();
        if ($response->isTimeout() && !function_exists('wp_remote_request')) {
            return null;
        }
        if (!$response->isSuccess()) {
            return ['ok' => false, 'points' => [], 'error' => 'foxpost_http_' . ($response->error ?? $response->status)];
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'points' => [], 'error' => 'foxpost_invalid_json'];
        }
        foreach (['points', 'results', 'data'] as $wrapper) {
            if (isset($decoded[$wrapper]) && is_array($decoded[$wrapper])) {
                $decoded = $decoded[$wrapper];
                break;
            }
        }
        if (!array_is_list($decoded)) {
            return ['ok' => false, 'points' => [], 'error' => 'foxpost_invalid_shape'];
        }

        if (count($decoded) > self::MAX_POINTS) {
            return ['ok' => false, 'points' => [], 'error' => 'foxpost_feed_too_large'];
        }

        $points = [];
        foreach ($decoded as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $point = PickupDirectory::normalizePoint(self::canonicalize($raw), $this->carrier());
            if ($point !== null) {
                $points[] = $point;
            }
        }
        if ($points === []) {
            return ['ok' => false, 'points' => [], 'error' => 'foxpost_empty_feed'];
        }
        return ['ok' => true, 'points' => $points, 'error' => null];
    }

    /**
     * Map the documented `foxplus.json` field names to the canonical keys `normalizePoint()` understands
     * without inventing any values.
     *
     * `raw` is deliberately bounded to the fields this integration consumes: the full feed row carries
     * dozens of extra columns (opening hours, per-carrier flags, …) that would multiply the stored option
     * by ~5x for 5000+ points and exhaust a 128M web process on the checkout AJAX path.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function canonicalize(array $raw): array
    {
        $operatorId = trim((string) ($raw['operator_id'] ?? $raw['operatorId'] ?? ''));
        $placeId = trim((string) ($raw['place_id'] ?? $raw['placeId'] ?? ''));
        $id = $operatorId !== '' ? $operatorId : $placeId;

        return [
            'point_id' => $id,
            'carrier' => self::CARRIER,
            'name' => (string) ($raw['name'] ?? ''),
            'address' => (string) ($raw['address'] ?? $raw['street'] ?? ''),
            'zip' => (string) ($raw['zip'] ?? ''),
            'city' => (string) ($raw['city'] ?? ''),
            'type' => strtoupper((string) ($raw['variant'] ?? $raw['apmType'] ?? 'APM')),
            'lat' => is_numeric($raw['geolat'] ?? null) ? (float) $raw['geolat'] : null,
            'long' => is_numeric($raw['geolng'] ?? null) ? (float) $raw['geolng'] : null,
            'raw' => array_filter([
                'operator_id' => $operatorId,
                'place_id' => $placeId,
                'name' => (string) ($raw['name'] ?? ''),
                'zip' => (string) ($raw['zip'] ?? ''),
                'city' => (string) ($raw['city'] ?? ''),
                'variant' => (string) ($raw['variant'] ?? $raw['apmType'] ?? ''),
            ], static fn($value): bool => $value !== ''),
        ];
    }
}
