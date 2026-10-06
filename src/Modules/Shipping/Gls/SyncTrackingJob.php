<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async GLS tracking job (hook `fch_sync_tracking_gls`), args `['order_id'=>int]`. It reads GLS-021
 * `GetParcelStatuses` for the order's confirmed GLS shipments (04_GLS §3.5/§4) and applies the
 * Appendix-G-mapped FC shipping status through the verified OrderResource API. Transient failures throw
 * so the queue retries with backoff; successes no-op via the monotonic guard.
 */
final class SyncTrackingJob implements JobInterface
{
    public const HOOK = 'fch_sync_tracking_gls';

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

        $result = (new GlsTracking())->syncOrder($orderId);
        do_action('fch/gls/tracking_result', $orderId, $result);
    }
}
