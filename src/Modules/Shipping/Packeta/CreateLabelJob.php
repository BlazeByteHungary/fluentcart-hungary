<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async Packeta label-creation job (hook `fch_create_label_packeta`), enqueued by PacketaModule with
 * `JobSpec('fch:{order}:packeta:label_job:{n}', 'fch_create_label_packeta', ['order_id'=>…, 'shipment_no'=>…])`.
 *
 * Idempotency lives in the shipment + ledger unique keys: a duplicate/retried run finds the existing row
 * and performs no external call. The provider create is NOT retry-safe (04_PACKETA §7), so
 * {@see PacketaPacketService} runs it with `max_attempts=1`; a timeout becomes UNCERTAIN + reconciliation,
 * never a re-POST. The job's own attempt budget only covers pre-dispatch local errors.
 */
final class CreateLabelJob implements JobInterface
{
    public const HOOK = 'fch_create_label_packeta';

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

        $result = (new PacketaPacketService())->createForOrder($orderId, $shipmentNo);
        do_action('fch/packeta/label_result', $orderId, $result);

        $status = (string) ($result['status'] ?? '');
        if ($status === 'validation_error' || $status === 'rejected') {
            $reason = (string) ($result['reason'] ?? $status);
            $errors = (array) ($result['errors'] ?? []);
            throw new \RuntimeException(
                'packeta_label_' . $reason . ($errors !== [] ? ': ' . implode(',', array_map('strval', $errors)) : '')
            );
        }
    }
}
