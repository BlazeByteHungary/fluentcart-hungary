<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async per-carrier pickup-directory refresh (hook `fch_pickup_refresh`), enqueued by
 * {@see PickupPointsModule::maybeEnqueueRefresh()} from the hourly WP-Cron tick / CLI / admin.
 *
 * The job is intentionally idempotent: on provider failure it records the attempt (last_attempt_at,
 * last_error, attempts backoff) and leaves the last-good point list untouched. A hard failure is reported
 * as a failed attempt so the operator sees the carrier in diagnostics instead of a silently shrinking
 * directory.
 */
final class PickupRefreshJob implements JobInterface
{
    public const HOOK = 'fch_pickup_refresh';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $carrier = strtolower(trim((string) ($args['carrier'] ?? '')));
        if ($carrier === '') {
            throw new \InvalidArgumentException('carrier is required for ' . self::HOOK . '.');
        }

        $result = PickupPointsModule::refreshCarrier($carrier);
        do_action('fch/pickup/refresh_result', $carrier, $result);

        if (!$result['ok']) {
            throw new \RuntimeException(sprintf(
                'pickup_refresh_failed:%s:%s',
                $carrier,
                (string) ($result['error'] ?? 'unknown')
            ));
        }
    }
}
