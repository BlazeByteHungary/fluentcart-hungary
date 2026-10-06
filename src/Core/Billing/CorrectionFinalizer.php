<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Billing;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;

/**
 * Mirrors the correction ledger truth onto the invoice-document rows (W4 invoicing scope).
 *
 * A correction is a NEW document row (doc_role = storno_invoice / corrected_invoice) linked to the
 * original through `storno_of_document_id`; the original keeps its identifiers, amounts, VAT snapshot and
 * PDF and is marked SUPERSEDED only once the correction is PROVEN at the provider. A proven rejection
 * marks the correction row PROVEN_REJECTED and leaves the original untouched. Every step is idempotent:
 * running it again on an already-applied row is a no-op, so a lost job/retry can never duplicate state.
 *
 * PDF artifacts live on the document row (never in the ledger), so callers pass the provider result
 * fields they observed; the async reconcile path supplies them from the reconciler's ProviderCallResult.
 */
final class CorrectionFinalizer
{
    public const KINDS = ['storno_invoice', 'corrected_invoice'];

    public static function applyFromLedgerRow(int $ledgerId, array $resultFields = []): void
    {
        $ledger = new LedgerRepository();
        $row = $ledger->getById($ledgerId);
        if ($row === null || !in_array((string) $row->kind, self::KINDS, true)) {
            return;
        }
        if ((string) ($row->owner_type ?? '') !== 'invoice_document') {
            return;
        }
        $docId = (int) ($row->owner_id ?? 0);
        if ($docId <= 0) {
            return;
        }

        $documents = new InvoiceDocumentRepository();
        $doc = $documents->getById($docId);
        if ($doc === null) {
            return;
        }

        $state = (string) $row->state;
        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            self::confirm($documents, $docId, $row, $resultFields);
            return;
        }
        if ($state === ExternalOperation::PROVEN_REJECTED) {
            self::normalizeForTerminal($documents, $docId);
            $documents->markRejected($docId, self::rejectionFields($row));
        }
    }

    private static function confirm(InvoiceDocumentRepository $documents, int $docId, object $row, array $resultFields): void
    {
        $doc = $documents->getById($docId);
        if ($doc === null) {
            return;
        }

        if ((string) $doc->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            $number = self::pick($resultFields, $row, 'document_number');
            if ($number === '') {
                // The provider proved the outcome but reported no document number: keep the row in
                // progress so a later read-back can complete it; never fabricate an identifier.
                return;
            }
            $extra = [
                'document_number' => $number,
                'external_id' => self::pick($resultFields, $row, 'external_id') ?: $number,
                'issued_at' => gmdate('Y-m-d H:i:s'),
            ];
            foreach (['provider_doc_type'] as $key) {
                $value = (string) ($resultFields[$key] ?? '');
                if ($value !== '') {
                    $extra[$key] = $value;
                }
            }
            foreach (['gross_minor', 'net_minor', 'vat_minor'] as $key) {
                if (array_key_exists($key, $resultFields)) {
                    $extra[$key] = (int) $resultFields[$key];
                }
            }
            if (!empty($resultFields['pdf_ref'])) {
                $extra['pdf_ref'] = (string) $resultFields['pdf_ref'];
            }
            if (!empty($resultFields['pdf_sha256'])) {
                $extra['pdf_sha256'] = (string) $resultFields['pdf_sha256'];
            }
            self::normalizeForTerminal($documents, $docId);
            $documents->markIssued($docId, $extra);
            $doc = $documents->getById($docId) ?? $doc;
        }

        // The original document is superseded only after the correction is proven to exist.
        $originalId = (int) ($doc->storno_of_document_id ?? 0);
        if ($originalId > 0) {
            $original = $documents->getById($originalId);
            if ($original !== null && (string) $original->state === ExternalOperation::EXTERNAL_CONFIRMED) {
                $documents->markSuperseded($originalId);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function rejectionFields(object $row): array
    {
        $code = trim((string) ($row->local_error_code ?? ''));
        $message = trim((string) ($row->local_error_message ?? ''));
        return $code === '' && $message === ''
            ? []
            : ['local_error_code' => $code, 'local_error_message' => $message];
    }

    private static function pick(array $resultFields, object $row, string $key): string
    {
        $value = trim((string) ($resultFields[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
        return trim((string) ($row->{$key} ?? ''));
    }

    public static function ensureInProgress(InvoiceDocumentRepository $repo, int $docId): void
    {
        $row = $repo->getById($docId);
        if ($row === null) {
            return;
        }
        $state = (string) $row->state;
        if (in_array($state, [
            ExternalOperation::IN_PROGRESS,
            ExternalOperation::EXTERNAL_CONFIRMED,
            ExternalOperation::PROVEN_REJECTED,
            ExternalOperation::SUPERSEDED,
            ExternalOperation::MANUALLY_RESOLVED,
            ExternalOperation::UNCERTAIN,
            ExternalOperation::AWAITING_RECONCILIATION,
        ], true)) {
            return;
        }
        $repo->markStarted($docId);
    }

    /**
     * Move the row into a state from which a terminal mirror transition is legal:
     * UNCERTAIN -> AWAITING_RECONCILIATION (mirror profile), NOT_STARTED -> IN_PROGRESS.
     */
    private static function normalizeForTerminal(InvoiceDocumentRepository $repo, int $docId): void
    {
        $row = $repo->getById($docId);
        if ($row === null) {
            return;
        }
        $state = (string) $row->state;
        if ($state === ExternalOperation::UNCERTAIN) {
            $repo->markAwaitingReconciliation($docId);
            $row = $repo->getById($docId);
            $state = (string) ($row->state ?? '');
        }
        if ($state === ExternalOperation::NOT_STARTED) {
            $repo->markStarted($docId);
        }
    }
}
