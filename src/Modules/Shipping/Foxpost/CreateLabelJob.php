<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async Foxpost label-creation job (hook `fch_create_label_foxpost`), enqueued by FoxpostModule with a
 * stable per-order job key. Idempotency lives in the shipment + ledger unique keys, so a duplicate/retried
 * run finds the existing row and performs no external call.
 *
 * The provider create itself is only conditionally retry-safe (04_FOXPOST §7), so FoxpostParcelService runs
 * it with `max_attempts` 2 (APM + uniqueBarcode) or 1 (HD); a timeout becomes UNCERTAIN + reconciliation,
 * never a blind re-POST. This job's own attempt budget only covers pre-dispatch local errors.
 */
final class CreateLabelJob implements JobInterface
{
    public const HOOK = 'fch_create_label_foxpost';

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

        $result = (new FoxpostParcelService())->createForOrder($orderId, $shipmentNo);
        do_action('fch/foxpost/label_result', $orderId, $result);

        // Operator-fixable refusals must not finish as a silent SUCCEEDED job.
        $status = (string) ($result['status'] ?? '');
        if ($status === 'validation_error' || $status === 'rejected') {
            $reason = (string) ($result['reason'] ?? $status);
            $errors = (array) ($result['errors'] ?? []);
            throw new \RuntimeException(
                'foxpost_label_' . $reason . ($errors !== [] ? ': ' . implode(',', array_map('strval', $errors)) : '')
            );
        }
    }
}
