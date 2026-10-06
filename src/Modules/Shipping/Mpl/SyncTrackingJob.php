<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async MPL PULL-1 tracking job (hook `fch_sync_tracking`), args `['order_id'=>int]`. It reads tracking for
 * the order's confirmed MPL shipments (MPL-013, 04_MPL §4) and applies the C43/C9-mapped FC shipping status
 * via the verified OrderResource API (api/Resource/OrderResource.php:2187). Transient failures throw so the
 * queue retries with backoff (JobStore::backoffDelay); successes no-op via the monotonic guard.
 */
final class SyncTrackingJob implements JobInterface
{
    public const HOOK = 'fch_sync_tracking';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $orderId = (int) ($args['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('order_id is required for ' . self::HOOK . '.');
        }

        $result = (new MplTracking())->syncOrder($orderId);
        do_action('fch/mpl/tracking_result', $orderId, $result);
    }
}
