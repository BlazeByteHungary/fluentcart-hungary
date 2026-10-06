<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Billing\CorrectionDispatcherRegistry;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Settings\CredentialStore;

/**
 * Billingo correction dispatcher + read-back (W4 invoicing scope).
 *
 * Documented capabilities only (04_BILLINGO_CONTRACT.md §3.2/§6):
 *  - storno_invoice of an `invoice` → `PUT /documents/{id}/cancel` (cancellation_reason);
 *  - storno_invoice of a `draft` → `DELETE /documents/{id}` (documented drafts-only delete);
 *  - corrected_invoice of an `invoice` → `POST /documents/{id}/create-modification-document` with a
 *    comment (no items: the provider composes the full modification of the original). Item-level amount
 *    deltas are NOT simulated — the admin UI blocks them with guidance.
 *
 * No documented idempotency key exists for cancel/modification, so: dispatch-time 4xx is the only
 * rejection; a lost response is UNCERTAIN and resolves via read-back (`GET /documents/{id}` cancelled
 * flag, `related_documents` for modifications), never by a blind second call. Credentials come from the
 * ORIGINAL document's binding (mode/account), never from the current selection.
 */
final class BillingoCorrectionService
{
    public const PROVIDER = 'billingo';
    public const DOC_ACTION_STORNO = 'storno_invoice';
    public const DOC_ACTION_CORRECTED = 'corrected_invoice';

    private InvoiceDocumentRepository $documents;

    public function __construct(?InvoiceDocumentRepository $documents = null)
    {
        $this->documents = $documents ?? new InvoiceDocumentRepository();
    }

    /** @param object $ledgerRow correction ledger row (kind storno_invoice|corrected_invoice) */
    public function dispatch(object $ledgerRow): ProviderCallResult
    {
        $kind = (string) $ledgerRow->kind;
        $doc = $this->documents->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($doc === null) {
            return ProviderCallResult::localError('MISSING_DOCUMENT_ROW', 'A korrekciós bizonylatsor nem található.');
        }
        $original = $this->documents->getById((int) ($doc->storno_of_document_id ?? 0));
        if ($original === null) {
            return ProviderCallResult::localError('MISSING_ORIGINAL_DOCUMENT', 'Az eredeti bizonylat sora nem található.');
        }
        $originalId = (int) ($original->external_id ?? 0);
        $originalNumber = trim((string) ($original->document_number ?? ''));
        if ($originalId <= 0 || $originalNumber === '') {
            return ProviderCallResult::localError('ORIGINAL_MISSING_PROVIDER_ID', 'Az eredeti bizonylat szolgáltatói azonosítója hiányzik.');
        }

        $apiKey = (string) $this->credential($ledgerRow, 'api_key');
        if (trim($apiKey) === '') {
            return ProviderCallResult::localError('MISSING_API_KEY', 'A korrekcióhoz az eredeti Billingo fiók (mód/fiók) API kulcsa nem érhető el.');
        }
        $client = new BillingoClient($apiKey);
        $reason = $this->correctionReason($doc);
        $isDraftDelete = $kind === self::DOC_ACTION_STORNO && strtolower((string) ($doc->provider_doc_type ?? '')) === 'draft';
        $request = [
            'operation' => $isDraftDelete ? 'delete_draft' : ($kind === self::DOC_ACTION_STORNO ? 'cancel_document' : 'create_modification_document'),
            'document_id' => $originalId,
            'document_number' => $originalNumber,
        ];

        $response = $isDraftDelete
            ? $client->deleteDraft($originalId)
            : ($kind === self::DOC_ACTION_STORNO
                ? $client->cancelDocument($originalId, ['cancellation_reason' => $reason !== '' ? $reason : 'Bizonylat sztornó'])
                : $client->createModificationDocument($originalId, ['comment' => $reason !== '' ? $reason : 'Bizonylat helyesbítés']));

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }

        $classified = BillingoStatus::classifyHttp((int) $response->status);
        $body = $response->json();
        $responseSummary = [
            'http' => (int) $response->status,
            'document_id' => $body['id'] ?? null,
            'invoice_number' => $body['invoice_number'] ?? null,
            'cancelled' => $body['cancelled'] ?? null,
        ];

        if ($classified['outcome'] === BillingoStatus::OUTCOME_UNCERTAIN) {
            return ProviderCallResult::uncertain($classified['code'], $request, $responseSummary);
        }
        if ($classified['outcome'] === BillingoStatus::OUTCOME_LOCAL_ERROR) {
            return ProviderCallResult::localError($classified['code'], BillingoStatus::errorLabel($body, (int) $response->status), $request, $responseSummary);
        }
        if ($classified['outcome'] === BillingoStatus::OUTCOME_REJECTED) {
            return ProviderCallResult::rejected($classified['code'], BillingoStatus::errorLabel($body, (int) $response->status), $request, $responseSummary);
        }

        if ($isDraftDelete) {
            // Documented 204 with no body; the delete is proved by the successful HTTP outcome.
            return ProviderCallResult::success([
                'document_number' => $originalNumber,
                'external_id' => (string) $originalId,
                'provider_doc_type' => 'draft_deleted',
                'gross_minor' => (int) ($doc->gross_minor ?? 0),
                'net_minor' => (int) ($doc->net_minor ?? 0),
                'vat_minor' => (int) ($doc->vat_minor ?? 0),
                'currency' => (string) ($doc->currency ?? ''),
            ], $request, $responseSummary);
        }

        if ($kind === self::DOC_ACTION_STORNO) {
            if (($body['cancelled'] ?? null) !== true) {
                // 2xx without the cancelled flag: outcome not proven, resolve by read-back.
                return ProviderCallResult::uncertain('CANCEL_UNPROVEN', $request, $responseSummary);
            }
            $fields = [
                'document_number' => $originalNumber,
                'external_id' => (string) $originalId,
                'provider_doc_type' => 'cancellation',
                'gross_minor' => (int) ($doc->gross_minor ?? 0),
                'net_minor' => (int) ($doc->net_minor ?? 0),
                'vat_minor' => (int) ($doc->vat_minor ?? 0),
                'currency' => (string) ($doc->currency ?? ''),
            ];
            $pdf = $this->fetchAndStorePdf($client, $originalId, $originalNumber, 'storno');
            if ($pdf !== null) {
                $fields['pdf_ref'] = $pdf['ref'];
                $fields['pdf_sha256'] = $pdf['sha256'];
            }
            return ProviderCallResult::success($fields, $request, $responseSummary);
        }

        $newId = (int) ($body['id'] ?? 0);
        $newNumber = trim((string) ($body['invoice_number'] ?? ''));
        if ($newId <= 0 || $newNumber === '') {
            return ProviderCallResult::uncertain('MODIFICATION_UNIDENTIFIED', $request, $responseSummary);
        }
        $fields = [
            'document_number' => $newNumber,
            'external_id' => (string) $newId,
            'provider_doc_type' => 'modification',
            'gross_minor' => (int) ($doc->gross_minor ?? 0),
            'net_minor' => (int) ($doc->net_minor ?? 0),
            'vat_minor' => (int) ($doc->vat_minor ?? 0),
            'currency' => (string) ($doc->currency ?? ''),
        ];
        $pdf = $this->fetchAndStorePdf($client, $newId, $newNumber, 'corrected');
        if ($pdf !== null) {
            $fields['pdf_ref'] = $pdf['ref'];
            $fields['pdf_sha256'] = $pdf['sha256'];
        }
        return ProviderCallResult::success($fields, $request, $responseSummary);
    }

    /**
     * Read-back of an UNCERTAIN correction via `GET /documents/{original_id}`:
     *  - storno: `cancelled === true` proves it; anything else stays UNCERTAIN (no visibility guarantee);
     *  - correction: a unique `related_documents` entry of type modification proves it; none/ambiguous
     *    stays UNCERTAIN for a human.
     *
     * @param object $ledgerRow correction ledger row
     */
    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        $doc = $this->documents->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($doc === null) {
            return ProviderCallResult::uncertain('MISSING_DOCUMENT_ROW');
        }
        $original = $this->documents->getById((int) ($doc->storno_of_document_id ?? 0));
        if ($original === null) {
            return ProviderCallResult::uncertain('MISSING_ORIGINAL_DOCUMENT');
        }
        $originalId = (int) ($original->external_id ?? 0);
        if ($originalId <= 0) {
            return ProviderCallResult::uncertain('ORIGINAL_MISSING_PROVIDER_ID');
        }
        $apiKey = (string) $this->credential($ledgerRow, 'api_key');
        if (trim($apiKey) === '') {
            return ProviderCallResult::uncertain('MISSING_API_KEY');
        }

        $request = ['operation' => 'get_document', 'document_id' => $originalId];
        $client = new BillingoClient($apiKey);
        $response = $client->getDocument($originalId);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request, ['error' => (string) $response->error]);
        }
        if (!$response->isSuccess()) {
            return ProviderCallResult::uncertain('QUERY_HTTP_' . (int) $response->status, $request);
        }

        $body = $response->json();
        $summary = [
            'http' => (int) $response->status,
            'cancelled' => $body['cancelled'] ?? null,
            'related_documents' => is_array($body['related_documents'] ?? null) ? count($body['related_documents']) : 0,
        ];
        $kind = (string) $ledgerRow->kind;

        if ($kind === self::DOC_ACTION_STORNO) {
            if (($body['cancelled'] ?? null) === true) {
                return ProviderCallResult::success([
                    'document_number' => trim((string) ($original->document_number ?? '')),
                    'external_id' => (string) $originalId,
                    'provider_doc_type' => 'cancellation',
                    'gross_minor' => (int) ($doc->gross_minor ?? 0),
                    'net_minor' => (int) ($doc->net_minor ?? 0),
                    'vat_minor' => (int) ($doc->vat_minor ?? 0),
                    'currency' => (string) ($doc->currency ?? ''),
                ], $request, $summary);
            }
            return ProviderCallResult::uncertain('QUERY_NOT_CANCELLED_NOT_FINAL', $request, $summary);
        }

        $candidate = $this->uniqueModification($body, $originalId);
        if ($candidate !== null) {
            $fields = [
                'document_number' => $candidate['number'],
                'external_id' => (string) $candidate['id'],
                'provider_doc_type' => 'modification',
                'gross_minor' => (int) ($doc->gross_minor ?? 0),
                'net_minor' => (int) ($doc->net_minor ?? 0),
                'vat_minor' => (int) ($doc->vat_minor ?? 0),
                'currency' => (string) ($doc->currency ?? ''),
            ];
            $pdf = $this->fetchAndStorePdf($client, $candidate['id'], $candidate['number'], 'corrected');
            if ($pdf !== null) {
                $fields['pdf_ref'] = $pdf['ref'];
                $fields['pdf_sha256'] = $pdf['sha256'];
            }
            return ProviderCallResult::success($fields, $request, $summary);
        }
        return ProviderCallResult::uncertain('QUERY_NO_MODIFICATION_NOT_FINAL', $request, $summary);
    }

    /**
     * Retry ONLY the PDF read-back for a confirmed correction (never a second provider document call).
     *
     * @return array{status:string,reason?:string,pdf_ref?:string}
     */
    public function retryPdf(int $documentId): array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->provider !== self::PROVIDER || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return ['status' => 'error', 'reason' => 'document_not_confirmed'];
        }
        if (!empty($row->pdf_ref) && (new BillingoDocumentStore())->read((string) $row->pdf_ref) !== null) {
            return ['status' => 'already_stored', 'pdf_ref' => (string) $row->pdf_ref];
        }
        $apiKey = (string) (new CredentialStore())->get(self::PROVIDER, (string) $row->mode, (string) $row->account_id, 'api_key');
        if (trim($apiKey) === '') {
            return ['status' => 'error', 'reason' => 'missing_api_key'];
        }
        $providerId = (int) ($row->external_id ?? 0);
        $number = trim((string) ($row->document_number ?? ''));
        $pdf = $this->fetchAndStorePdf(new BillingoClient($apiKey), $providerId, $number, 'corrected');
        if ($pdf === null) {
            return ['status' => 'error', 'reason' => 'pdf_unavailable'];
        }
        $this->documents->updateIssuedFields($documentId, [
            'pdf_ref' => $pdf['ref'],
            'pdf_sha256' => $pdf['sha256'],
        ]);
        return ['status' => 'stored', 'pdf_ref' => $pdf['ref']];
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string,mixed> $body
     * @return array{id:int,number:string}|null unique modification candidate of the original
     */
    private function uniqueModification(array $body, int $originalId): ?array
    {
        $related = $body['related_documents'] ?? null;
        if (!is_array($related) || $related === []) {
            return null;
        }
        $candidates = [];
        foreach ($related as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['id'] ?? 0);
            if ($id <= 0 || $id === $originalId) {
                continue;
            }
            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            $correction = trim((string) ($entry['correction_type'] ?? ''));
            if ($type !== 'modification' && $correction === '') {
                continue;
            }
            $number = trim((string) ($entry['invoice_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $candidates[$id] = ['id' => $id, 'number' => $number];
        }
        return count($candidates) === 1 ? array_values($candidates)[0] : null;
    }

    /** @return array{ref:string,sha256:string}|null */
    private function fetchAndStorePdf(BillingoClient $client, int $providerId, string $documentNumber, string $kind): ?array
    {
        if ($providerId <= 0) {
            return null;
        }
        try {
            $response = $client->downloadPdf($providerId);
            if (!$response->isSuccess() || !str_starts_with(ltrim($response->body, "\xEF\xBB\xBF \t\r\n"), '%PDF')) {
                return null;
            }
            $stored = (new BillingoDocumentStore())->store('billingo-' . $kind . '-' . ($documentNumber !== '' ? $documentNumber : (string) $providerId), $response->body, 'pdf');
            return ['ref' => $stored['ref'], 'sha256' => $stored['sha256']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function correctionReason(object $doc): string
    {
        $meta = json_decode((string) ($doc->vat_snapshot ?? ''), true);
        $reason = is_array($meta) ? (string) ($meta['correction']['reason'] ?? '') : '';
        return trim($reason);
    }

    /** Credentials of the ORIGINAL document's binding (mode/account from the ledger row). */
    private function credential(object $ledgerRow, string $field): ?string
    {
        return (new CredentialStore())->get(self::PROVIDER, (string) $ledgerRow->mode, (string) $ledgerRow->account_id, $field);
    }

    /** Registered by BillingoModule so corrections never need a direct module reference from Core. */
    public static function register(): void
    {
        $service = new self();
        CorrectionDispatcherRegistry::register(self::PROVIDER, static function (object $ledgerRow) use ($service): ProviderCallResult {
            return $service->dispatch($ledgerRow);
        });
    }
}
