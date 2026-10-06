<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

/**
 * DPD PUDO (parcel point / locker) feed.
 *
 * The DPD PUDO directory endpoint is **NOT documented** in either local DPD file (04_DPD_CONTRACT.md §3.2;
 * DPD-UNK-03 / DPD-U3, matrix DPD-902). This provider therefore never calls an invented endpoint and never
 * invents inventory:
 *  - if the operator has imported a static list into the `fch_pickup_dpd_static` option, that list is
 *    normalized to canonical points via {@see PickupDirectory::normalizePoint()} and served;
 *  - otherwise `fetch()` returns `['ok'=>false,'points'=>[],'error'=>'feed_unavailable']` and the checkout
 *    selector degrades to manual/other-carrier selection (constraints §0 kill-switch).
 */
final class DpdPoints implements PickupFeedProviderInterface
{
    public const OPTION = 'fch_pickup_dpd_static';

    public function carrier(): string
    {
        return 'dpd';
    }

    public function fetch(array $query = [], ?object $client = null): array
    {
        $points = $this->staticPoints();
        if ($points === []) {
            return ['ok' => false, 'points' => [], 'error' => 'feed_unavailable'];
        }

        $needle = trim((string) ($query['city'] ?? $query['postCode'] ?? $query['postCodeOnLabel'] ?? ''));
        $limit = (int) ($query['limit'] ?? 200);
        return [
            'ok' => true,
            'points' => PickupDirectory::filterPoints($points, $needle, $limit > 0 ? $limit : 200),
            'error' => null,
        ];
    }

    /**
     * Normalize an operator-supplied static list (flat list or `{points:[...]}`) to canonical points.
     * Never produces a point without an id (normalizePoint returns null otherwise).
     *
     * @param array<mixed> $raw
     * @return list<array<string,mixed>>
     */
    public static function normalizeStatic(array $raw): array
    {
        if (isset($raw['points']) && is_array($raw['points'])) {
            $raw = $raw['points'];
        }
        $points = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $point = PickupDirectory::normalizePoint($entry, 'dpd');
            if ($point !== null) {
                $points[] = $point;
            }
        }
        return $points;
    }

    /** @return list<array<string,mixed>> */
    private function staticPoints(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? self::normalizeStatic($stored) : [];
    }
}
