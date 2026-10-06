<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async MPL label-creation job (hook `fch_create_label`), enqueued by MplModule with
 * `JobSpec('fch:{order}:label_job:{n}', 'fch_create_label', ['order_id'=>…, 'shipment_no'=>…], …, maxAttempts=3)`.
 *
 * Idempotency lives in the shipment + ledger unique keys: a duplicate/retried run finds the existing row and
 * performs no external call (05_DATA_SCHEMA §8). The provider create itself is NOT retry-safe (04_MPL §7), so
 * MplShipmentService runs it with `max_attempts=1`; a timeout becomes UNCERTAIN + reconciliation, never a
 * re-POST. The job's 3-attempt budget only covers pre-dispatch local errors (validation/credentials/DNS).
 */
final class CreateLabelJob implements JobInterface
{
    public const HOOK = 'fch_create_label';

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

        // Announce the label request BEFORE the provider call: the invoicing module may use this as its
        // COD "before dispatch" trigger (merchant policy; the invoice never changes the payment status).
        do_action('fch/shipping/label_started', $orderId, $shipmentNo);

        $result = (new MplShipmentService())->createForOrder($orderId, $shipmentNo);
        do_action('fch/mpl/label_result', $orderId, $result);

        // Operator-fixable refusals (missing phone, missing address, validation) must not finish as a
        // silent SUCCEEDED job: surface them as a failed attempt with the concrete reason so the order
        // screen/diagnostics show what has to be fixed before a retry.
        $status = (string) ($result['status'] ?? '');
        if ($status === 'validation_error' || $status === 'rejected') {
            $reason = (string) ($result['reason'] ?? $status);
            $errors = (array) ($result['errors'] ?? []);
            throw new \RuntimeException(
                'mpl_label_' . $reason . ($errors !== [] ? ': ' . implode(',', array_map('strval', $errors)) : '')
            );
        }
    }
}
