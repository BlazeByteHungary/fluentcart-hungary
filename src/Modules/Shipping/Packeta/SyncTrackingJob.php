<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async Packeta tracking job (hook `fch_sync_tracking_packeta`), args `['order_id'=>int]`. It reads
 * `packetStatus`/`packetTracking` for the order's confirmed packets (PKT-011/012) and applies the
 * status-code-mapped FC shipping status via the verified OrderResource API. Transient failures throw so
 * the queue retries with backoff; successes no-op via the monotonic guard.
 */
final class SyncTrackingJob implements JobInterface
{
    public const HOOK = 'fch_sync_tracking_packeta';

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

        $result = (new PacketaTracking())->syncOrder($orderId);
        do_action('fch/packeta/tracking_result', $orderId, $result);
    }
}
