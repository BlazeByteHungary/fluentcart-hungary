<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Mirror of gateway payment attempts (05_DATA_SCHEMA §5.2, §7.2). Invariants encoded here:
 * - uniqueness: (order,provider,mode,account,attempt_no) and idempotency_key;
 * - paid only via server-side verification: markVerified() rejects any verification_source outside
 *   {status_api, signed_ipn, query} (invariant §1.1; redirect/callback never proves payment).
 */
final class PaymentAttemptRepository extends MirrorRepository
{
    public const VERIFICATION_SOURCES = ['status_api', 'signed_ipn', 'query'];

    private const MUTABLE = [
        'order_uuid',
        'operation_id',
        'payment_type',
        'external_payment_id',
        'external_request_id',
        'external_ref2',
        'redirect_url',
        'callback_url',
        'raw_state',
        'verification_source',
        'verified_at',
        'started_at',
        'completed_at',
        'expires_at',
        'last_state_check_at',
        'charged_amount_minor',
        'refunded_amount_minor',
    ];

    protected function baseTable(): string
    {
        return 'fch_payment_attempts';
    }

    /**
     * @return array{created:bool,row:object}
     */
    public function getOrCreate(array $attrs): array
    {
        foreach (['order_id', 'provider', 'mode', 'account_id', 'attempt_no', 'idempotency_key', 'amount_minor', 'currency'] as $key) {
            if (!isset($attrs[$key]) || $attrs[$key] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required payment attempt field "%s".', $key));
            }
        }

        $row = [
            'order_id' => $attrs['order_id'],
            'provider' => (string) $attrs['provider'],
            'mode' => (string) $attrs['mode'],
            'account_id' => (string) $attrs['account_id'],
            'attempt_no' => (int) $attrs['attempt_no'],
            'idempotency_key' => (string) $attrs['idempotency_key'],
            'amount_minor' => (int) $attrs['amount_minor'],
            'currency' => (string) $attrs['currency'],
        ];
        foreach (self::MUTABLE as $optional) {
            if (array_key_exists($optional, $attrs) && $attrs[$optional] !== null) {
                $row[$optional] = $attrs[$optional];
            }
        }

        $created = $this->insertIgnore($row);
        $key = (string) $attrs['idempotency_key'];
        if ($created) {
            return ['created' => true, 'row' => $this->getByKeyOrFail($key)];
        }

        $existing = $this->getByKey($key);
        if ($existing !== null) {
            return ['created' => false, 'row' => $existing];
        }

        $existing = $this->getByAttempt(
            (int) $attrs['order_id'],
            (string) $attrs['provider'],
            (string) $attrs['mode'],
            (string) $attrs['account_id'],
            (int) $attrs['attempt_no']
        );
        if ($existing !== null) {
            return ['created' => false, 'row' => $existing];
        }

        throw new \RuntimeException('Payment attempt insert failed.');
    }

    public function getByKey(string $idempotencyKey): ?object
    {
        return $this->rowBy('idempotency_key', $idempotencyKey);
    }

    public function getByKeyOrFail(string $idempotencyKey): object
    {
        $row = $this->getByKey($idempotencyKey);
        if ($row === null) {
            throw new \RuntimeException('Payment attempt not found: ' . $idempotencyKey);
        }
        return $row;
    }

    public function getByAttempt(int $orderId, string $provider, string $mode, string $account, int $attemptNo): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND provider = %s AND mode = %s AND account_id = %s AND attempt_no = %d LIMIT 1",
            $orderId,
            $provider,
            $mode,
            $account,
            $attemptNo
        ));
        return $row ?: null;
    }

    public function nextAttemptNo(int $orderId, string $provider, string $mode, string $account): int
    {
        global $wpdb;
        $table = $this->table();
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(attempt_no) FROM `{$table}` WHERE order_id = %d AND provider = %s AND mode = %s AND account_id = %s",
            $orderId,
            $provider,
            $mode,
            $account
        ));
        return $max === null ? 1 : ((int) $max + 1);
    }

    /** Guard for starting a new attempt: an order never has two proved-successful attempts. */
    public function hasConfirmed(int $orderId): bool
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE order_id = %d AND state = %s",
            $orderId,
            ExternalOperation::EXTERNAL_CONFIRMED
        )) > 0;
    }

    /** @return list<object> */
    public function findOpenByOrder(int $orderId): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND state IN (%s, %s, %s) ORDER BY attempt_no DESC",
            $orderId,
            ExternalOperation::IN_PROGRESS,
            ExternalOperation::UNCERTAIN,
            ExternalOperation::AWAITING_RECONCILIATION
        ));
        return is_array($rows) ? $rows : [];
    }

    /** @return list<object> every attempt for an order (admin read-only listing). */
    public function listForOrder(int $orderId, int $limit = 50): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY attempt_no DESC LIMIT %d",
            $orderId,
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
    }

    public function findByExternalPaymentId(string $provider, string $mode, string $externalPaymentId): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE provider = %s AND mode = %s AND external_payment_id = %s LIMIT 1",
            $provider,
            $mode,
            $externalPaymentId
        ));
        return $row ?: null;
    }

    public function findByExternalRequestId(string $provider, string $mode, string $externalRequestId): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE provider = %s AND mode = %s AND external_request_id = %s LIMIT 1",
            $provider,
            $mode,
            $externalRequestId
        ));
        return $row ?: null;
    }

    public function markStarted(int $id, array $extra = []): bool
    {
        $extra['started_at'] ??= gmdate('Y-m-d H:i:s');
        return $this->transitionRow($id, ExternalOperation::IN_PROGRESS, $extra, ExternalOperation::NOT_STARTED, self::MUTABLE);
    }

    /** Only a server-side verification source may prove the payment (invariant §1.1). */
    public function markVerified(int $id, array $extra): bool
    {
        $source = (string) ($extra['verification_source'] ?? '');
        if (!in_array($source, self::VERIFICATION_SOURCES, true)) {
            throw new \InvalidArgumentException('verification_source must be one of: ' . implode(', ', self::VERIFICATION_SOURCES));
        }
        $extra['verified_at'] ??= gmdate('Y-m-d H:i:s');
        $extra['completed_at'] ??= gmdate('Y-m-d H:i:s');
        return $this->transitionRow($id, ExternalOperation::EXTERNAL_CONFIRMED, $extra, null, self::MUTABLE);
    }

    public function markRejected(int $id, array $extra = []): bool
    {
        $extra['verified_at'] ??= gmdate('Y-m-d H:i:s');
        return $this->transitionRow($id, ExternalOperation::PROVEN_REJECTED, $extra, null, self::MUTABLE);
    }

    public function markUncertain(int $id, string $rawState = ''): bool
    {
        $extra = ['last_state_check_at' => gmdate('Y-m-d H:i:s')];
        if ($rawState !== '') {
            $extra['raw_state'] = $rawState;
        }
        return $this->transitionRow($id, ExternalOperation::UNCERTAIN, $extra, null, self::MUTABLE);
    }

    public function markAwaitingReconciliation(int $id): bool
    {
        return $this->transitionRow($id, ExternalOperation::AWAITING_RECONCILIATION, [], null, self::MUTABLE);
    }

    public function markSuperseded(int $id): bool
    {
        return $this->transitionRow($id, ExternalOperation::SUPERSEDED, [], null, self::MUTABLE);
    }

    /** Record a non-proving redirect/callback observation without changing state. */
    public function touchObservation(int $id, string $rawState, string $source): bool
    {
        global $wpdb;
        if (!in_array($source, array_merge(self::VERIFICATION_SOURCES, ['redirect_none']), true)) {
            throw new \InvalidArgumentException('Unknown verification source: ' . $source);
        }
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET raw_state = %s, verification_source = %s, last_state_check_at = UTC_TIMESTAMP() WHERE id = %d",
            $rawState,
            $source,
            $id
        ));
        return (int) $affected === 1;
    }

    public function addRefundedAmount(int $id, int $amountMinor): bool
    {
        global $wpdb;
        $table = $this->table();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET refunded_amount_minor = refunded_amount_minor + %d WHERE id = %d",
            $amountMinor,
            $id
        ));
        return (int) $affected === 1;
    }

    /** @return list<object> */
    public function findRecent(int $limit = 20): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", max(1, $limit)));
        return is_array($rows) ? $rows : [];
    }
}
