<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async DPD label-creation job (hook `fch_create_label_dpd`), enqueued by DpdModule with
 * `JobSpec('fch:{order}:dpd:label_job:{n}', 'fch_create_label_dpd', ['order_id'=>…, 'shipment_no'=>…], …,
 * maxAttempts=3)`.
 *
 * Idempotency lives in the shipment + ledger unique keys; a duplicate/retried run finds the existing row
 * and performs no external call. The provider create itself is NOT retry-safe (04_DPD §7), so
 * DpdShipmentService runs it with `max_attempts=1`; a timeout becomes UNCERTAIN + manual reconciliation,
 * never a re-POST. The job's 3-attempt budget only covers pre-dispatch local errors.
 */
final class CreateLabelJob implements JobInterface
{
    public const HOOK = 'fch_create_label_dpd';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $orderId = (int) ($args['order_id'] ?? 0);
        $shipmentNo = (int) ($args['shipment_no'] ?? 1);
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('order_id is required for ' . self::HOOK . '.');
        }
        if ($shipmentNo <= 0) {
            $shipmentNo = 1;
        }

        do_action('fch/shipping/label_started', $orderId, $shipmentNo);

        $result = (new DpdShipmentService())->createForOrder($orderId, $shipmentNo);
        do_action('fch/dpd/label_result', $orderId, $result);

        $status = (string) ($result['status'] ?? '');
        if ($status === 'validation_error' || $status === 'rejected') {
            $reason = (string) ($result['reason'] ?? $status);
            $errors = (array) ($result['errors'] ?? []);
            throw new \RuntimeException(
                'dpd_label_' . $reason . ($errors !== [] ? ': ' . implode(',', array_map('strval', $errors)) : '')
            );
        }
    }
}
