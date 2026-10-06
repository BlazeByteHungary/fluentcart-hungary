<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async DPD tracking-sync job (hook `fch_sync_tracking_dpd`), args `['order_id'=>int]`.
 *
 * There is no DPD tracking API in the local contract (DPD-UNK-06/DPD-U6); the documented channel is the
 * STATUSDATA CSV feed. DpdTracking::syncOrder() therefore performs no HTTP call and reports
 * `tracking_endpoint_undocumented`; this job is a no-op placeholder that keeps the carrier registry
 * contract intact. A STATUSDATA importer can call `DpdTracking::applyRecords()` directly.
 */
final class SyncTrackingJob implements JobInterface
{
    public const HOOK = 'fch_sync_tracking_dpd';

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

        $result = (new DpdTracking())->syncOrder($orderId);
        do_action('fch/dpd/tracking_result', $orderId, $result);
    }
}
