<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Shipping;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Deferred shipment cancellation: enqueued when the withdrawal request arrives while the create is still
 * in flight (IN_PROGRESS) or its outcome is not yet proven (UNCERTAIN). The handler re-runs the
 * state-aware cancellation service, so the lifecycle continues from the ACTUAL create outcome — a proven
 * create is cancelled at the provider, a never-dispatched intent is cancelled locally. While the create is
 * still in flight the job throws so the queue retries it under its own attempt/backoff budget instead of
 * busy-looping.
 */
final class CancelShipmentJob implements JobInterface
{
    public const HOOK = 'fch_cancel_shipment';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $shipmentId = (int) ($args['shipment_id'] ?? 0);
        if ($shipmentId <= 0) {
            throw new \InvalidArgumentException('shipment_id is required for ' . self::HOOK . '.');
        }
        $result = (new ShipmentCancellationService())->request($shipmentId, ['deferred' => true]);
        $status = (string) ($result['status'] ?? '');
        if ($status === 'deferred') {
            throw new \RuntimeException('shipment_cancel_deferred: create outcome not proven yet');
        }
        do_action('fch/shipping/cancel_result', $shipmentId, $result);
    }
}
