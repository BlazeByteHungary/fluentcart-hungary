<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Mirror of provider invoice/document rows (05_DATA_SCHEMA §5.3, §7.3). Invariants encoded here:
 * - single-invoicer: at most one active automatic document per (order, doc_role) — the generated
 *   `auto_doc_guard` column is the mechanical guard, this repository surfaces it as a soft result;
 * - reprint: never inserts a row, never changes state (read-only helpers only);
 * - per-order sequence uniqueness (order,doc_role,provider,mode,account,sequence_no).
 */
final class InvoiceDocumentRepository extends MirrorRepository
{
    public const ACTIVE_GUARD_STATES = 'PROVEN_REJECTED,SUPERSEDED,MANUALLY_RESOLVED';

    private const MUTABLE = [
        'order_uuid',
        'vendor_id',
        'external_id',
        'external_ref2',
        'operation_id',
        'provider_doc_type',
        'document_number',
        'gross_minor',
        'net_minor',
        'vat_minor',
        'currency',
        'vat_snapshot',
        'order_fin_snapshot_hash',
        'pdf_ref',
        'pdf_sha256',
        'issued_at',
        'fulfillment_date',
        'due_date',
        'storno_of_document_id',
    ];

    protected function baseTable(): string
    {
        return 'fch_invoice_documents';
    }

    /**
     * Claim-or-return the document row for an operation. When $attrs['is_selected_invoicer'] = 1 the
     * generated-column guard returns the already-active automatic document instead of inserting a second.
     *
     * @return array{created:bool,row:object,guard:?string}
     */
    public function getOrCreate(array $attrs): array
    {
        foreach (['order_id', 'provider', 'mode', 'account_id', 'doc_role', 'idempotency_key'] as $key) {
            if (!isset($attrs[$key]) || $attrs[$key] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required invoice document field "%s".', $key));
            }
        }

        $isSelected = (int) ($attrs['is_selected_invoicer'] ?? 0);
        $orderId = (int) $attrs['order_id'];
        $docRole = (string) $attrs['doc_role'];

        if ($isSelected === 1) {
            $active = $this->findActiveAuto($orderId, $docRole);
            if ($active !== null) {
                return ['created' => false, 'row' => $active, 'guard' => 'single_invoicer'];
            }
        }

        $row = [
            'order_id' => $orderId,
            'provider' => (string) $attrs['provider'],
            'mode' => (string) $attrs['mode'],
            'account_id' => (string) $attrs['account_id'],
            'doc_role' => $docRole,
            'idempotency_key' => (string) $attrs['idempotency_key'],
            'is_selected_invoicer' => $isSelected,
            'sequence_no' => (int) ($attrs['sequence_no'] ?? $this->nextSequence(
                $orderId,
                $docRole,
                (string) $attrs['provider'],
                (string) $attrs['mode'],
                (string) $attrs['account_id']
            )),
        ];
        foreach (self::MUTABLE as $optional) {
            if (array_key_exists($optional, $attrs) && $attrs[$optional] !== null) {
                $row[$optional] = $attrs[$optional];
            }
        }

        if ($this->insertIgnore($row)) {
            return ['created' => true, 'row' => $this->getByKeyOrFail((string) $attrs['idempotency_key']), 'guard' => null];
        }

        $existing = $this->getByKey((string) $attrs['idempotency_key']);
        if ($existing !== null) {
            return ['created' => false, 'row' => $existing, 'guard' => null];
        }

        if ($isSelected === 1) {
            $active = $this->findActiveAuto($orderId, $docRole);
            if ($active !== null) {
                return ['created' => false, 'row' => $active, 'guard' => 'single_invoicer'];
            }
        }

        $bySequence = $this->findBySequence($orderId, $docRole, (string) $attrs['provider'], (string) $attrs['mode'], (string) $attrs['account_id'], (int) $row['sequence_no']);
        if ($bySequence !== null) {
            return ['created' => false, 'row' => $bySequence, 'guard' => 'sequence_conflict'];
        }

        throw new \RuntimeException('Invoice document insert failed.');
    }

    public function getByKey(string $idempotencyKey): ?object
    {
        return $this->rowBy('idempotency_key', $idempotencyKey);
    }

    /** Attach the ledger operation to a row created before the ledger claim (correction flow). */
    public function attachOperation(int $id, int $operationId): bool
    {
        global $wpdb;
        return $wpdb->update($this->table(), ['operation_id' => $operationId], ['id' => $id]) !== false;
    }

    public function getByKeyOrFail(string $idempotencyKey): object
    {
        $row = $this->getByKey($idempotencyKey);
        if ($row === null) {
            throw new \RuntimeException('Invoice document not found: ' . $idempotencyKey);
        }
        return $row;
    }

    /** The active automatic document for (order, doc_role), if one exists (single-invoicer guard). */
    public function findActiveAuto(int $orderId, string $docRole): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND doc_role = %s AND is_selected_invoicer = 1 AND state NOT IN (%s, %s, %s) LIMIT 1",
            $orderId,
            $docRole,
            ExternalOperation::PROVEN_REJECTED,
            ExternalOperation::SUPERSEDED,
            ExternalOperation::MANUALLY_RESOLVED
        ));
        return $row ?: null;
    }

    /**
     * Latest automatic document of (order, doc_role) that a correction superseded. A late paid/label
     * event must not silently replace a stornoed/corrected invoice; re-issuing is a manual decision.
     */
    public function latestSupersededAuto(int $orderId, string $docRole): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND doc_role = %s AND is_selected_invoicer = 1 AND state = %s ORDER BY id DESC LIMIT 1",
            $orderId,
            $docRole,
            ExternalOperation::SUPERSEDED
        ));
        return $row ?: null;
    }

    public function findBySequence(int $orderId, string $docRole, string $provider, string $mode, string $account, int $sequenceNo): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE order_id = %d AND doc_role = %s AND provider = %s AND mode = %s AND account_id = %s AND sequence_no = %d LIMIT 1",
            $orderId,
            $docRole,
            $provider,
            $mode,
            $account,
            $sequenceNo
        ));
        return $row ?: null;
    }

    public function nextSequence(int $orderId, string $docRole, string $provider, string $mode, string $account): int
    {
        global $wpdb;
        $table = $this->table();
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sequence_no) FROM `{$table}` WHERE order_id = %d AND doc_role = %s AND provider = %s AND mode = %s AND account_id = %s",
            $orderId,
            $docRole,
            $provider,
            $mode,
            $account
        ));
        return $max === null ? 1 : ((int) $max + 1);
    }

    public function findByDocumentNumber(string $provider, string $mode, string $documentNumber): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE provider = %s AND mode = %s AND document_number = %s LIMIT 1",
            $provider,
            $mode,
            $documentNumber
        ));
        return $row ?: null;
    }

    public function findByVendorId(string $provider, string $mode, string $vendorId): ?object
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE provider = %s AND mode = %s AND vendor_id = %s LIMIT 1",
            $provider,
            $mode,
            $vendorId
        ));
        return $row ?: null;
    }

    /** NOT_STARTED -> IN_PROGRESS (worker claim before dispatch; mirror machine §7.3). */
    public function markStarted(int $id, array $extra = []): bool
    {
        return $this->transitionRow($id, ExternalOperation::IN_PROGRESS, $extra, ExternalOperation::NOT_STARTED, self::MUTABLE);
    }

    public function markIssued(int $id, array $extra): bool
    {
        if (trim((string) ($extra['document_number'] ?? '')) === '') {
            throw new \InvalidArgumentException('markIssued requires a document_number.');
        }
        $extra['issued_at'] ??= gmdate('Y-m-d H:i:s');
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
     * Update mutable fields on an ALREADY-ISSUED document without a state change (e.g. attach a
     * delayed PDF read-back, refresh provider status). Only MUTABLE columns; confirmed rows only.
     */
    public function updateIssuedFields(int $id, array $fields): bool
    {
        global $wpdb;
        $row = $this->getById($id);
        if ($row === null || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return false;
        }
        $data = [];
        foreach (self::MUTABLE as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }
        if ($data === []) {
            return false;
        }
        return $wpdb->update($this->table(), $data, ['id' => $id]) !== false;
    }

    /** Storno/corrected reached EXTERNAL_CONFIRMED: the source document becomes SUPERSEDED. */
    public function markSuperseded(int $id): bool
    {
        return $this->transitionRow($id, ExternalOperation::SUPERSEDED, [], null, self::MUTABLE);
    }

    /** @return list<object> */
    public function listForOrder(int $orderId): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY id ASC", $orderId));
        return is_array($rows) ? $rows : [];
    }

    /**
     * Correction/derived rows pointing at an original document (storno_invoice / corrected_invoice),
     * oldest first. The parent/child chain stays auditable via `storno_of_document_id`.
     *
     * @return list<object>
     */
    public function listCorrections(int $originalDocId, string $docRole): array
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE storno_of_document_id = %d AND doc_role = %s ORDER BY id ASC",
            $originalDocId,
            $docRole
        ));
        return is_array($rows) ? $rows : [];
    }
}
