<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Mirror of carrier shipments/labels (05_DATA_SCHEMA §5.4, §7.4). Partial fulfillment: each row records
 * the order items it covers in `items` (JSON); refunds never share a column with fulfillment (§1.8).
 */
final class ShipmentRepository extends MirrorRepository
{
    public const LABEL_STATES = ['NONE', 'PREPARED', 'PRINTED', 'DELETED'];

    private const MUTABLE = [
        'order_uuid',
        'operation_id',
        'external_shipment_id',
        'external_ref2',
        'external_ref3',
        'barcode',
        'tracking_number',
        'carrier_reference',
        'label_ref',
        'label_sha256',
        'parcels',
        'items',
        'package_count',
        'pickup_point',
        'cod_amount_minor',
        'currency',
    ];

    protected function baseTable(): string
    {
        return 'fch_shipments';
    }

    /**
     * @return array{created:bool,row:object}
     */
    public function getOrCreate(array $attrs): array
    {
        foreach (['order_id', 'carrier', 'mode', 'account_id', 'shipment_no', 'idempotency_key'] as $key) {
            if (!isset($attrs[$key]) || $attrs[$key] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required shipment field "%s".', $key));
            }
        }

        $row = [
            'order_id' => (int) $attrs['order_id'],
            'carrier' => (string) $attrs['carrier'],
            'mode' => (string) $attrs['mode'],
            'account_id' => (string) $attrs['account_id'],
            'shipment_no' => (int) $attrs['shipment_no'],
            'idempotency_key' => (string) $attrs['idempotency_key'],
        ];
        foreach (self::MUTABLE as $optional) {
            if (array_key_exists($optional, $attrs) && $attrs[$optional] !== null) {
                $row[$optional] = $attrs[$optional];
            }
        }

        if ($this->insertIgnore($row)) {
            return ['created' => true, 'row' => $this->getByKeyOrFail((string) $attrs['idempotency_key'])];
        }

        $existing = $this->getByKey((string) $attrs['idempotency_key']);
        if ($existing !== null) {
            return ['created' => false, 'row' => $existing];
        }

        $existing = $this->getByShipmentNo(
            (int) $attrs['order_id'],
            (string) $attrs['carrier'],
            (string) $attrs['mode'],
            (string) $attrs['account_id'],
            (int) $attrs['shipment_no']
        );
        if ($existing !== null) {
            return ['created' => false, 'row' => $existing];
        }

        throw new \RuntimeException('Shipment insert failed.');
    }

    public function getByKey(string $idempotencyKey): ?object
    {
        return $this->rowBy('idempotency_key', $idempotencyKey);
    }

    /** Attach the ledger operation to a row created before the ledger claim (cancellation intent). */
    public function attachOperation(int $id, int $operationId): bool
    {
        global $wpdb;
        return $wpdb->update($this->table(), ['operation_id' => $operationId], ['id' => $id]) !== false;
    }

    public function getByKeyOrFail(string $idempotencyKey): object
    {
        $row = $this->getByKey($idempotencyKey);
        if ($row === null) {
            throw new \RuntimeException('Shipment not found: ' . $idempotencyKey);
        }
        return $row;
    }

    public function getByShipmentNo(int $orderId, string $carrier, string $mode, string $account, int $shipmentNo): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND carrier = %s AND mode = %s AND account_id = %s AND shipment_no = %d LIMIT 1",
            $orderId,
            $carrier,
            $mode,
            $account,
            $shipmentNo
        ));
        return $row ?: null;
    }

    public function nextShipmentNo(int $orderId, string $carrier, string $mode, string $account): int
    {
        global $wpdb;
        $table = $this->table();
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(shipment_no) FROM `{$table}` WHERE order_id = %d AND carrier = %s AND mode = %s AND account_id = %s",
            $orderId,
            $carrier,
            $mode,
            $account
        ));
        return $max === null ? 1 : ((int) $max + 1);
    }

    public function findByTracking(string $carrier, string $mode, string $trackingNumber): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE carrier = %s AND mode = %s AND tracking_number = %s LIMIT 1",
            $carrier,
            $mode,
            $trackingNumber
        ));
        return $row ?: null;
    }

    public function findByExternalId(string $carrier, string $mode, string $externalShipmentId): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE carrier = %s AND mode = %s AND external_shipment_id = %s LIMIT 1",
            $carrier,
            $mode,
            $externalShipmentId
        ));
        return $row ?: null;
    }

    public function findByBarcode(string $carrier, string $mode, string $barcode): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE carrier = %s AND mode = %s AND barcode = %s LIMIT 1",
            $carrier,
            $mode,
            $barcode
        ));
        return $row ?: null;
    }

    /** NOT_STARTED -> IN_PROGRESS (worker claim before dispatch; mirror machine §7.4). */
    public function markStarted(int $id, array $extra = []): bool
    {
        return $this->transitionRow($id, ExternalOperation::IN_PROGRESS, $extra, ExternalOperation::NOT_STARTED, self::MUTABLE);
    }

    public function markConfirmed(int $id, array $extra): bool
    {
        return $this->transitionRow($id, ExternalOperation::EXTERNAL_CONFIRMED, $extra, null, self::MUTABLE);
    }

    public function markRejected(int $id, array $extra = []): bool
    {
        return $this->transitionRow($id, ExternalOperation::PROVEN_REJECTED, $extra, null, self::MUTABLE);
    }

    public function markUncertain(int $id): bool
    {
        return $this->transitionRow($id, ExternalOperation::UNCERTAIN, [], null, self::MUTABLE);
    }

    public function markAwaitingReconciliation(int $id): bool
    {
        return $this->transitionRow($id, ExternalOperation::AWAITING_RECONCILIATION, [], null, self::MUTABLE);
    }

    /**
     * A provider-proven external cancellation: SUPERSEDED + cancelled_at. The LABEL FILE IS KEPT — a
     * deleted label file is not the same as a withdrawn consignment (04 contracts), and the merchant may
     * still need the document for the audit trail. Never called from an FC order status alone.
     *
     * @param array<string,mixed> $extra e.g. ['external_ref3' => provider cancel transaction id]
     */
    public function markCancelled(int $id, array $extra = []): bool
    {
        $fields = array_merge(['cancelled_at' => gmdate('Y-m-d H:i:s')], $extra);
        return $this->transitionRow(
            $id,
            ExternalOperation::SUPERSEDED,
            $fields,
            ExternalOperation::EXTERNAL_CONFIRMED,
            array_merge(self::MUTABLE, ['cancelled_at'])
        );
    }

    /**
     * Cancel a queued (never-dispatched) intent locally: the provider was never called, so no external
     * operation exists. Used when the merchant cancels before the dispatch — a later re-dispatch of the
     * same key finds the SUPERSEDED row and cannot create a packet silently.
     */
    public function markIntentCancelled(int $id, array $extra = []): bool
    {
        $fields = array_merge(['cancelled_at' => gmdate('Y-m-d H:i:s'), 'external_ref3' => 'local_cancel'], $extra);
        return $this->transitionRow(
            $id,
            ExternalOperation::SUPERSEDED,
            $fields,
            ExternalOperation::NOT_STARTED,
            array_merge(self::MUTABLE, ['cancelled_at'])
        );
    }

    /** Label lifecycle on a confirmed shipment; never changes the external operation state. */
    public function setLabelState(int $id, string $labelState): bool
    {
        if (!in_array($labelState, self::LABEL_STATES, true)) {
            throw new \InvalidArgumentException('Unknown label state: ' . $labelState);
        }
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET label_state = %s WHERE id = %d AND state = %s",
            $labelState,
            $id,
            ExternalOperation::EXTERNAL_CONFIRMED
        ));
        return (int) $affected === 1;
    }

    /**
     * Persist a generated label artifact on an ALREADY-confirmed shipment without a state transition
     * (carriers whose create response carries no inline label generate it in a second call, after the
     * confirm transition — a plain markConfirmed() would be a no-op on the same state and orphan the file).
     */
    public function setLabel(int $id, string $labelRef, ?string $sha256 = null, string $labelState = 'PREPARED'): bool
    {
        if (!in_array($labelState, self::LABEL_STATES, true)) {
            throw new \InvalidArgumentException('Unknown label state: ' . $labelState);
        }
        $labelRef = trim($labelRef);
        if ($labelRef === '') {
            throw new \InvalidArgumentException('Empty label ref.');
        }
        global $wpdb;
        $table = $this->table();
        $sets = ['label_ref = %s', 'label_state = %s'];
        $params = [$labelRef, $labelState];
        if ($sha256 !== null && $sha256 !== '') {
            $sets[] = 'label_sha256 = %s';
            $params[] = $sha256;
        }
        $params[] = $id;
        $params[] = ExternalOperation::EXTERNAL_CONFIRMED;
        $affected = $wpdb->query($wpdb->prepare(
            sprintf('UPDATE `%s` SET %s WHERE id = %%d AND state = %%s', $table, implode(', ', $sets)),
            ...$params
        ));
        return (int) $affected === 1;
    }

    /** @return list<object> */
    public function listForOrder(int $orderId): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY id ASC", $orderId));
        return is_array($rows) ? $rows : [];
    }
}
