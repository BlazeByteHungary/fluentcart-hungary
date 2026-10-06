<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async Foxpost PULL tracking job (hook `fch_sync_tracking_foxpost`), args `['order_id'=>int]`. It reads
 * `GET /api/tracking/{barcode}` for the order's confirmed Foxpost shipments and applies the mapped FC
 * shipping status via the verified OrderResource API. Transient failures throw so the queue retries with
 * backoff; successes no-op via the monotonic guard. Push/callback is not implemented (MISSING_DOC, FX-U3).
 */
final class SyncTrackingJob implements JobInterface
{
    public const HOOK = 'fch_sync_tracking_foxpost';

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

        $result = (new FoxpostTracking())->syncOrder($orderId);
        do_action('fch/foxpost/tracking_result', $orderId, $result);
    }
}
