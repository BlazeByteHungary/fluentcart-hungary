<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

use FluentCartHungary\Core\Jobs\JobInterface;

/**
 * Async GLS label-creation job (hook `fch_create_label_gls`), enqueued by GlsModule with
 * `JobSpec('fch:{order}:gls:label_job:{n}', 'fch_create_label_gls', ['order_id'=>…, 'shipment_no'=>…])`.
 *
 * Idempotency lives in the shipment + ledger unique keys: a duplicate/retried run finds the existing row
 * and performs no external call (05_DATA_SCHEMA §8). The provider create itself is NOT retry-safe
 * (04_GLS §7: no server idempotency key), so GlsShipmentService runs it with `max_attempts=1`; a timeout
 * becomes UNCERTAIN + reconciliation, never a re-POST. The job's attempt budget only covers pre-dispatch
 * local errors (validation/credentials/DNS).
 */
final class CreateLabelJob implements JobInterface
{
    public const HOOK = 'fch_create_label_gls';

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

        // Announce the label request BEFORE the provider call (COD/invoicing "before dispatch" seam).
        do_action('fch/shipping/label_started', $orderId, $shipmentNo);

        $result = (new GlsShipmentService())->createForOrder($orderId, $shipmentNo);
        do_action('fch/gls/label_result', $orderId, $result);

        // Operator-fixable refusals (missing phone/address/validation/COD range) must not finish as a
        // silent SUCCEEDED job: surface them as a failed attempt with the concrete reason.
        $status = (string) ($result['status'] ?? '');
        if ($status === 'validation_error' || $status === 'rejected') {
            $reason = (string) ($result['reason'] ?? $status);
            $errors = (array) ($result['errors'] ?? []);
            throw new \RuntimeException(
                'gls_label_' . $reason . ($errors !== [] ? ': ' . implode(',', array_map('strval', $errors)) : '')
            );
        }
    }
}
