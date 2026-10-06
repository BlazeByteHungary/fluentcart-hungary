<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\InvoicerSelection;

/**
 * Orchestrates one automatic Billingo document for a FluentCart order (W3).
 *
 * Reads the order ONLY through verified FluentCart APIs (same surfaces as the W1 Számlázz service:
 * Order/order_items/billing_address/getBusinessInfo/updateMeta). Idempotency + exactly-once:
 *  - provider-scoped vendor_id / ledger key `fch-{order}-billingo-{role}-{seq}` (the canonical
 *    `fch:{order}:create_invoice:{n}` key belongs to Számlázz; provider scoping avoids cross-provider
 *    key collisions in the shared ledger/document tables);
 *  - `InvoiceDocumentRepository::getOrCreate(is_selected_invoicer=1)` is the single-invoicer guard;
 *  - `OperationRunner` maps success/rejected/UNCERTAIN; UNCERTAIN is NEVER re-dispatched — it is resolved
 *    by `GET /documents/vendor/{vendor_id}` (Billingo documents the `vendor_id` uniqueness + lookup), so a
 *    lost response can never produce a second document.
 *
 * Document types: auto issuance is `invoice` only (COD policy applies); manual admin issuance may request
 * proforma/advance/draft/receipt (W3 scope: types are supported, auto-proforma is not enabled by policy).
 * The payment status of the order is never modified by issuance.
 */
final class BillingoInvoiceService
{
    public const DOC_ROLE_INVOICE = 'invoice';
    public const PROVIDER = 'billingo';
    public const MODULE = 'billing';
    public const SETTINGS_SCOPE = 'billingo';
    public const MAX_ATTEMPTS = 3;

    private const COD_METHOD = 'offline_payment';

    private InvoiceDocumentRepository $documents;

    private OperationRunner $runner;

    private ModuleSettings $settings;

    public function __construct(
        ?InvoiceDocumentRepository $documents = null,
        ?OperationRunner $runner = null,
        ?ModuleSettings $settings = null
    ) {
        $this->documents = $documents ?? new InvoiceDocumentRepository();
        $this->runner = $runner ?? new OperationRunner();
        $this->settings = $settings ?? new ModuleSettings();
    }

    /**
     * @return array{status:string,state:string,order_id:int,document_id:?int,document_number:?string,pdf_ref:?string,reason?:string}
     */
    public function issue(int $orderId, int $sequence = 1, string $docType = 'invoice'): array
    {
        $docType = in_array($docType, BillingoStatus::DOC_TYPES, true) ? $docType : 'invoice';

        // Provider binding: the router normally guarantees this, but a direct/manual call must not issue
        // when another provider already owns the intent (single-invoicer invariant §1.5).
        $bound = InvoicerSelection::boundProvider($orderId, self::DOC_ROLE_INVOICE);
        if ($bound !== null && $bound !== self::PROVIDER) {
            return $this->summary('skipped', 'NOT_STARTED', $orderId, null, null, null, 'single_invoicer_active:' . $bound);
        }
        if ($docType === 'invoice' && !InvoicerSelection::isSelected(self::PROVIDER) && $bound === null) {
            return $this->summary('skipped', 'NOT_STARTED', $orderId, null, null, null, 'not_selected_invoicer');
        }

        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'order_not_found');
        }
        if ((string) ($order->payment_status ?? '') !== 'paid' && !$this->codBeforeDispatch($order, $docType)) {
            return $this->summary('skipped', 'NOT_STARTED', $orderId, null, null, null, 'order_not_paid');
        }

        $apiKey = (string) BillingoModule::apiKey();
        if (trim($apiKey) === '') {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'missing_api_key');
        }
        $blockId = (int) self::setting('block_id', 0);
        if ($blockId <= 0) {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'missing_block_id');
        }

        // W4: after a storno/correction a late paid/label event must not create a replacement invoice
        // (and it must not even leave a stray document row for a superseded order).
        if ($docType === self::DOC_ROLE_INVOICE && $this->documents->latestSupersededAuto($orderId, self::DOC_ROLE_INVOICE) !== null) {
            return $this->summary('skipped', ExternalOperation::SUPERSEDED, $orderId, null, null, null, 'prior_document_superseded');
        }

        $snapshot = $this->buildSnapshot($order, $sequence, $docType);
        if ($snapshot === null) {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'snapshot_failed');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(self::SETTINGS_SCOPE);
        $vendorId = (string) $snapshot['header']['vendor_id'];
        $snapshotHash = BillingoMapper::hashSnapshot($snapshot);
        $totals = BillingoMapper::totals($snapshot);
        $isGuardedAuto = $docType === self::DOC_ROLE_INVOICE;

        $document = $this->documents->getOrCreate([
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'provider' => self::PROVIDER,
            'mode' => $mode,
            'account_id' => $account,
            'doc_role' => $isGuardedAuto ? self::DOC_ROLE_INVOICE : $docType,
            'sequence_no' => $sequence,
            'idempotency_key' => $vendorId,
            'vendor_id' => $vendorId,
            'is_selected_invoicer' => $isGuardedAuto ? 1 : 0,
            'selected_invoicer_provider' => self::PROVIDER,
            'currency' => (string) $snapshot['currency'],
            'order_fin_snapshot_hash' => $snapshotHash,
            'provider_doc_type' => $docType,
            'fulfillment_date' => (string) ($snapshot['header']['teljesites_datum'] ?? ''),
            'due_date' => (string) ($snapshot['header']['fizetesi_hatarido_datum'] ?? ''),
        ]);
        $docRow = $document['row'];
        $docId = (int) $docRow->id;

        if ((string) $docRow->provider !== self::PROVIDER) {
            return $this->summary('skipped', (string) $docRow->state, $orderId, $docId, null, null, 'single_invoicer_active:' . (string) $docRow->provider);
        }
        if ((string) $docRow->state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary('already_issued', (string) $docRow->state, $orderId, $docId, $this->nullableString($docRow->document_number ?? null), $this->nullableString($docRow->pdf_ref ?? null));
        }
        if (in_array((string) $docRow->state, [ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED], true)) {
            return $this->summary('not_retryable', (string) $docRow->state, $orderId, $docId, null, null, 'document_' . strtolower((string) $docRow->state));
        }
        if (in_array((string) $docRow->state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            // Lost response: NEVER create again; the read-back (admin/reconciler) resolves it.
            return $this->summary('uncertain_pending', (string) $docRow->state, $orderId, $docId, null, null, 'reconcile_required');
        }

        $client = new BillingoClient($apiKey);
        $run = $this->runner->run([
            'idempotency_key' => $vendorId,
            'module' => self::MODULE,
            'provider' => self::PROVIDER,
            'kind' => 'create_invoice',
            'mode' => $mode,
            'account_id' => $account,
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'owner_type' => 'invoice_document',
            'owner_id' => $docId,
            'business_reference' => $vendorId,
            'currency' => (string) $snapshot['currency'],
            'amount_minor' => $totals['gross'],
            'order_fin_snapshot_hash' => $snapshotHash,
            'max_attempts' => self::MAX_ATTEMPTS,
        ], function (object $ledgerRow) use ($snapshot, $client, $docType): ProviderCallResult {
            return $this->dispatchCreate($snapshot, $client, $docType);
        });

        $state = (string) $run['state'];
        $fields = $run['result']?->fields ?? [];

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            $this->finalizeIssued($docId, $fields, $client, $snapshot, $totals);
            $fresh = $this->documents->getById($docId);
            return $this->summary('issued', $state, $orderId, $docId, $this->nullableString($fresh->document_number ?? ($fields['document_number'] ?? null)), $this->nullableString($fresh->pdf_ref ?? null));
        }
        if ($state === ExternalOperation::PROVEN_REJECTED) {
            $this->markDocument($docId, ExternalOperation::PROVEN_REJECTED);
            return $this->summary('rejected', $state, $orderId, $docId, null, null, (string) ($run['result']?->code ?? 'REJECTED'));
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            $this->markDocument($docId, ExternalOperation::UNCERTAIN);
            return $this->summary('uncertain', $state, $orderId, $docId, null, null, (string) ($run['result']?->code ?? 'TIMEOUT_AFTER_DISPATCH'));
        }

        return $this->summary('local_error', $state, $orderId, $docId, null, null, (string) ($run['result']?->code ?? 'LOCAL_ERROR'));
    }

    // ---------------------------------------------------------------- provider call

    /** @param array<string,mixed> $snapshot */
    private function dispatchCreate(array $snapshot, BillingoClient $client, string $docType): ProviderCallResult
    {
        $request = [
            'vendor_id' => (string) ($snapshot['header']['vendor_id'] ?? ''),
            'type' => $docType,
            'currency' => (string) ($snapshot['currency'] ?? ''),
            'item_count' => count((array) ($snapshot['items'] ?? [])),
            'gross_minor' => BillingoMapper::totals($snapshot)['gross'],
        ];

        $partnerId = $this->resolvePartnerId($snapshot, $client);
        if ($partnerId === null) {
            return ProviderCallResult::localError('PARTNER_RESOLUTION_FAILED', 'Billingo partner could not be resolved or created.', $request);
        }

        $config = $this->clientConfig($snapshot, $docType);
        $payload = BillingoMapper::documentPayload($snapshot, $config, $partnerId);
        $response = $client->createDocument($payload);

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }

        $body = $response->json();
        $classified = BillingoStatus::classifyHttp((int) $response->status);
        $responseSummary = [
            'http' => (int) $response->status,
            'document_id' => $body['id'] ?? null,
            'invoice_number' => $body['invoice_number'] ?? null,
            'error_label' => $classified['outcome'] === BillingoStatus::OUTCOME_SUCCESS ? null : BillingoStatus::errorLabel($body, (int) $response->status),
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

        $id = (int) ($body['id'] ?? 0);
        $number = trim((string) ($body['invoice_number'] ?? ''));
        if ($id <= 0 || $number === '') {
            return ProviderCallResult::localError('MISSING_DOCUMENT_IDENTIFIERS', 'Billingo reported success without id/invoice_number.', $request, $responseSummary);
        }

        $grossMinor = $this->providerAmountToMinor($body['gross_total'] ?? null, (string) $snapshot['currency']);
        return ProviderCallResult::success([
            'external_id' => (string) $id,
            'document_number' => $number,
            'external_ref2' => (string) ($snapshot['header']['vendor_id'] ?? ''),
            'provider_doc_type' => (string) ($body['type'] ?? $docType),
            'gross_minor' => $grossMinor ?? BillingoMapper::totals($snapshot)['gross'],
            'net_minor' => $this->providerAmountToMinor($body['net_total'] ?? null, (string) $snapshot['currency']) ?? BillingoMapper::totals($snapshot)['net'],
            'vat_minor' => $this->providerAmountToMinor($body['vat_amount'] ?? null, (string) $snapshot['currency']) ?? BillingoMapper::totals($snapshot)['vat'],
            'currency' => (string) ($body['currency'] ?? $snapshot['currency']),
            'conversion_rate' => (float) ($body['conversion_rate'] ?? 1.0),
            'order_fin_snapshot_hash' => BillingoMapper::hashSnapshot($snapshot),
            'vat_snapshot' => function_exists('wp_json_encode') ? wp_json_encode($this->vatSnapshot($snapshot)) : '[]',
            'amount_mismatch' => ($grossMinor !== null && $grossMinor !== BillingoMapper::totals($snapshot)['gross']) ? 1 : 0,
        ], $request, $responseSummary);
    }

    /**
     * Find-or-create partner (04 §4.1). `partner_id` setting pins a fixed partner; `partner_mode=create`
     * always creates; the default searches the first pages for an exact taxcode or name+email match.
     *
     * @param array<string,mixed> $snapshot
     */
    public function resolvePartnerId(array $snapshot, BillingoClient $client): ?int
    {
        $fixed = (int) self::setting('partner_id', 0);
        if ($fixed > 0) {
            return $fixed;
        }
        $mode = (string) self::setting('partner_mode', 'find_or_create');
        $payload = BillingoMapper::partnerPayload($snapshot);

        if ($mode === 'find_or_create') {
            $found = $this->findPartner($client, $payload);
            if ($found !== null) {
                return $found;
            }
        }

        $response = $client->createPartner($payload);
        $body = $response->json();
        $id = (int) ($body['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $payload */
    private function findPartner(BillingoClient $client, array $payload): ?int
    {
        $taxcode = strtoupper(trim((string) ($payload['taxcode'] ?? '')));
        $name = mb_strtolower(trim((string) ($payload['name'] ?? '')));
        $email = mb_strtolower(trim((string) ($payload['emails'][0] ?? '')));

        for ($page = 1; $page <= 3; $page++) {
            $response = $client->listPartners(['page' => $page, 'per_page' => 100]);
            if (!$response->isSuccess()) {
                return null;
            }
            $body = $response->json();
            $partners = $body['data'] ?? null;
            if (!is_array($partners) || $partners === []) {
                return null;
            }
            foreach ($partners as $partner) {
                if (!is_array($partner)) {
                    continue;
                }
                $partnerTax = strtoupper(trim((string) ($partner['taxcode'] ?? '')));
                if ($taxcode !== '' && $partnerTax !== '' && $partnerTax === $taxcode) {
                    return (int) ($partner['id'] ?? 0) ?: null;
                }
                if ($taxcode === '' && $name !== '') {
                    $partnerName = mb_strtolower(trim((string) ($partner['name'] ?? '')));
                    $partnerEmail = mb_strtolower(trim((string) ($partner['emails'][0] ?? '')));
                    if ($partnerName === $name && ($email === '' || $partnerEmail === $email)) {
                        return (int) ($partner['id'] ?? 0) ?: null;
                    }
                }
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- finalize / recovery

    /**
     * Mark issued and fetch the PDF read-back. A failed PDF fetch never flips the proved outcome; the
     * admin "Retry PDF" action retries only the download (never a create).
     *
     * @param array<string,mixed> $fields
     */
    private function finalizeIssued(int $docId, array $fields, BillingoClient $client, array $snapshot, array $totals): void
    {
        $row = $this->documents->getById($docId);
        if ($row !== null && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED && !empty($row->pdf_ref)) {
            return;
        }

        $extra = [
            'document_number' => (string) ($fields['document_number'] ?? ''),
            'external_id' => (string) ($fields['external_id'] ?? ''),
            'external_ref2' => (string) ($fields['external_ref2'] ?? ''),
            'provider_doc_type' => (string) ($fields['provider_doc_type'] ?? 'invoice'),
            'gross_minor' => (int) ($fields['gross_minor'] ?? $totals['gross']),
            'net_minor' => (int) ($fields['net_minor'] ?? $totals['net']),
            'vat_minor' => (int) ($fields['vat_minor'] ?? $totals['vat']),
            'currency' => (string) ($fields['currency'] ?? $snapshot['currency']),
            'vat_snapshot' => (string) ($fields['vat_snapshot'] ?? '[]'),
            'order_fin_snapshot_hash' => (string) ($fields['order_fin_snapshot_hash'] ?? ''),
            'issued_at' => gmdate('Y-m-d H:i:s'),
        ];
        if (!empty($fields['amount_mismatch'])) {
            $extra['vat_snapshot'] = $this->mergeSnapshotFlag((string) $extra['vat_snapshot'], 'amount_mismatch', [
                'expected_minor' => $totals['gross'],
                'provider_minor' => (int) $fields['gross_minor'],
            ]);
            do_action('fch/billingo/invoice_amount_mismatch', $docId, $totals['gross'], (int) $fields['gross_minor']);
        }

        $docIdInt = (int) $fields['external_id'] ?: 0;
        $pdf = $this->fetchAndStorePdf($docIdInt, (string) $extra['document_number'], $client);
        if ($pdf !== null) {
            $extra['pdf_ref'] = $pdf['ref'];
            $extra['pdf_sha256'] = $pdf['sha256'];
        } else {
            $extra['vat_snapshot'] = $this->mergeSnapshotFlag((string) $extra['vat_snapshot'], 'pdf_pending', true);
        }

        self::ensureDocumentInProgress($this->documents, $docId);
        $this->documents->markIssued($docId, $extra);
    }

    /** @return array{ref:string,sha256:string}|null */
    private function fetchAndStorePdf(int $billingoId, string $documentNumber, BillingoClient $client): ?array
    {
        if ($billingoId <= 0 || !(bool) self::setting('pdf_download', true)) {
            return null;
        }
        try {
            $response = $client->downloadPdf($billingoId);
            if (!$response->isSuccess() || !str_starts_with(ltrim($response->body, "\xEF\xBB\xBF \t\r\n"), '%PDF')) {
                return null;
            }
            $stored = (new BillingoDocumentStore())->store('billingo-invoice-' . $documentNumber, $response->body, 'pdf');
            return ['ref' => $stored['ref'], 'sha256' => $stored['sha256']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Retry ONLY the PDF read-back for an already-issued document (never a create; the PDF failure is not
     * a reason for a new document).
     *
     * @return array{status:string,reason?:string,pdf_ref?:string}
     */
    public function retryPdf(int $documentId): array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->provider !== self::PROVIDER) {
            return ['status' => 'error', 'reason' => 'document_not_found'];
        }
        if ((string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return ['status' => 'error', 'reason' => 'document_not_confirmed'];
        }
        if (!empty($row->pdf_ref) && (new BillingoDocumentStore())->read((string) $row->pdf_ref) !== null) {
            return ['status' => 'already_stored', 'pdf_ref' => (string) $row->pdf_ref];
        }
        $apiKey = (string) BillingoModule::apiKey();
        if (trim($apiKey) === '') {
            return ['status' => 'error', 'reason' => 'missing_api_key'];
        }
        $client = new BillingoClient($apiKey);
        $pdf = $this->fetchAndStorePdf((int) ($row->external_id ?? 0), (string) ($row->document_number ?? ''), $client);
        if ($pdf === null) {
            return ['status' => 'error', 'reason' => 'pdf_unavailable'];
        }
        $this->documents->updateIssuedFields($documentId, [
            'pdf_ref' => $pdf['ref'],
            'pdf_sha256' => $pdf['sha256'],
        ]);
        return ['status' => 'stored', 'pdf_ref' => $pdf['ref']];
    }

    /**
     * Resolve an UNCERTAIN document by the documented lookup `GET /documents/vendor/{vendor_id}`
     * (04 §6): found => confirmed (optionally + PDF); 404 => proven rejected (nothing was created);
     * timeout/unexpected => stays uncertain for a later run. NEVER creates a document.
     *
     * @return array{status:string,state?:string,reason?:string,document_number?:string,pdf_ref?:?string}
     */
    public function reconcile(int $documentId): array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->provider !== self::PROVIDER) {
            return ['status' => 'error', 'reason' => 'document_not_found'];
        }
        if ((string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return ['status' => 'already_confirmed', 'document_number' => $this->nullableString($row->document_number ?? null) ?? ''];
        }

        $apiKey = (string) BillingoModule::apiKey();
        if (trim($apiKey) === '') {
            return ['status' => 'error', 'reason' => 'missing_api_key'];
        }
        $vendorId = (string) ($row->vendor_id ?? '');
        if ($vendorId === '') {
            return ['status' => 'error', 'reason' => 'missing_vendor_id'];
        }

        $client = new BillingoClient($apiKey);
        $response = $client->getDocumentByVendorId($vendorId);
        if ($response->error !== null || $response->status === 0) {
            return ['status' => 'uncertain', 'reason' => 'lookup_timeout'];
        }
        if ((int) $response->status === 404) {
            // A single 404 after a dispatched-but-lost create is NOT a rejection: the API documents
            // "404 if absent" but gives no visibility guarantee for a document whose create response
            // was lost. Keep the original identity and stay UNCERTAIN/AWAITING for manual resolution
            // (W4_EXECUTION_PLAN §5). PROVEN_REJECTED comes only from an explicit dispatch-time refusal.
            self::ensureDocumentInProgress($this->documents, $documentId);
            $state = (string) ($this->documents->getById($documentId)->state ?? '');
            if ($state === ExternalOperation::UNCERTAIN) {
                $this->documents->markAwaitingReconciliation($documentId);
                $state = ExternalOperation::AWAITING_RECONCILIATION;
            }
            return ['status' => 'uncertain', 'state' => $state, 'reason' => 'not_found'];
        }
        if (!$response->isSuccess()) {
            return ['status' => 'uncertain', 'reason' => 'lookup_http_' . (int) $response->status];
        }

        $body = $response->json();
        $id = (int) ($body['id'] ?? 0);
        $number = trim((string) ($body['invoice_number'] ?? ''));
        if ($id <= 0 || $number === '') {
            return ['status' => 'uncertain', 'reason' => 'lookup_incomplete'];
        }

        $pdf = $this->fetchAndStorePdf($id, $number, $client);
        $extra = [
            'document_number' => $number,
            'external_id' => (string) $id,
            'external_ref2' => $vendorId,
            'issued_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($pdf !== null) {
            $extra['pdf_ref'] = $pdf['ref'];
            $extra['pdf_sha256'] = $pdf['sha256'];
        }
        self::ensureDocumentInProgress($this->documents, $documentId);
        $rowState = (string) ($this->documents->getById($documentId)->state ?? '');
        if ($rowState === ExternalOperation::UNCERTAIN) {
            $this->documents->markAwaitingReconciliation($documentId);
        }
        $this->documents->markIssued($documentId, $extra);
        return ['status' => 'confirmed', 'state' => ExternalOperation::EXTERNAL_CONFIRMED, 'document_number' => $number, 'pdf_ref' => $extra['pdf_ref'] ?? null];
    }

    // ---------------------------------------------------------------- admin/reporting helpers

    /** @return array{status:string,reason?:string,online_szamla_status?:mixed,notification_status?:mixed} */
    public function refreshStatus(int $documentId): array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->provider !== self::PROVIDER) {
            return ['status' => 'error', 'reason' => 'document_not_found'];
        }
        $apiKey = (string) BillingoModule::apiKey();
        if (trim($apiKey) === '') {
            return ['status' => 'error', 'reason' => 'missing_api_key'];
        }
        $client = new BillingoClient($apiKey);
        $response = $client->getDocument((int) ($row->external_id ?? 0));
        if (!$response->isSuccess()) {
            return ['status' => 'error', 'reason' => 'http_' . (int) $response->status];
        }
        $body = $response->json();
        $snapshot = $this->mergeSnapshotFlag((string) ($row->vat_snapshot ?? '[]'), 'status', [
            'online_szamla_status' => $body['online_szamla_status'] ?? null,
            'notification_status' => $body['notification_status'] ?? null,
            'payment_status' => $body['payment_status'] ?? null,
            'cancelled' => (bool) ($body['cancelled'] ?? false),
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->documents->updateIssuedFields($documentId, ['vat_snapshot' => $snapshot]);
        return [
            'status' => 'refreshed',
            'online_szamla_status' => $body['online_szamla_status'] ?? null,
            'notification_status' => $body['notification_status'] ?? null,
        ];
    }

    /** Provider-side email sending (BIL-015). @param list<string> $emails */
    public function sendEmail(int $documentId, array $emails): array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->provider !== self::PROVIDER || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return ['status' => 'error', 'reason' => 'document_not_confirmed'];
        }
        $emails = array_values(array_filter(array_map('trim', $emails), static fn(string $email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL)));
        if ($emails === []) {
            return ['status' => 'error', 'reason' => 'no_valid_recipients'];
        }
        $apiKey = (string) BillingoModule::apiKey();
        if (trim($apiKey) === '') {
            return ['status' => 'error', 'reason' => 'missing_api_key'];
        }
        $response = (new BillingoClient($apiKey))->sendDocumentEmail((int) ($row->external_id ?? 0), $emails);
        return $response->isSuccess()
            ? ['status' => 'sent']
            : ['status' => 'error', 'reason' => 'http_' . (int) $response->status];
    }

    /** NAV taxpayer check (BI-010), fail-open at the caller. @return array{ok:bool,validity:bool|string,message:string} */
    public function checkTaxNumber(string $taxNumber): array
    {
        $apiKey = (string) BillingoModule::apiKey();
        if (trim($apiKey) === '' || trim($taxNumber) === '') {
            return ['ok' => false, 'validity' => 'unknown', 'message' => 'missing_configuration'];
        }
        $response = (new BillingoClient($apiKey))->checkTaxNumber($taxNumber);
        if (!$response->isSuccess()) {
            return ['ok' => false, 'validity' => 'unknown', 'message' => 'http_' . (int) $response->status];
        }
        $body = $response->json();
        return [
            'ok' => true,
            'validity' => $body['validity'] ?? (string) ($body['vatStatus'] ?? 'unknown'),
            'message' => (string) ($body['name'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------- snapshot

    /**
     * @return array<string,mixed>|null
     */
    public function buildSnapshot(object $order, int $sequence, string $docType = 'invoice'): ?array
    {
        try {
            $currency = strtoupper((string) ($order->currency ?? 'HUF'));
            $taxBehavior = (int) ($order->tax_behavior ?? 0);
            $basis = $this->amountBasis($taxBehavior);
            $businessInfo = method_exists($order, 'getBusinessInfo') ? (array) $order->getBusinessInfo() : [];

            $components = $this->collectComponents($order, $taxBehavior);
            if ($components['items'] === []) {
                return null;
            }

            $today = function_exists('current_time') ? (string) current_time('Y-m-d') : gmdate('Y-m-d');
            $fulfillment = $this->dateOnly($order->completed_at ?? null) ?? $today;
            $deadlineDays = (int) self::setting('payment_deadline_days', 8);
            $due = gmdate('Y-m-d', strtotime($today . ' +' . max(0, $deadlineDays) . ' days'));

            $conversionRate = $this->conversionRate($currency);
            if ($conversionRate === null) {
                return null; // foreign currency without a trustworthy rate: refuse silently-wrong 1.0
            }

            return [
                'order_id' => (string) ($order->id ?? ''),
                'currency' => $currency,
                'amount_basis' => $basis,
                'financials' => [
                    'subtotal' => FluentCartMoney::fromCartCents((int) ($order->subtotal ?? 0), $currency),
                    'shipping_total' => FluentCartMoney::fromCartCents((int) ($order->shipping_total ?? 0), $currency),
                    'fee_total' => FluentCartMoney::fromCartCents((int) ($order->fee_total ?? 0), $currency),
                    'tax_total' => FluentCartMoney::fromCartCents((int) ($order->tax_total ?? 0), $currency),
                    'total_amount' => FluentCartMoney::fromCartCents((int) ($order->total_amount ?? 0), $currency),
                    'tax_behavior' => $taxBehavior,
                ],
                'seller' => [
                    'payment_method' => BillingoStatus::paymentMethodFor(
                        (string) ($order->payment_method ?? ''),
                        $this->paymentMethodOverrides(),
                        (string) self::setting('payment_method_default', 'elore_utalas')
                    ),
                    'language' => (string) self::setting('invoice_language', 'hu'),
                    'block_id' => (int) self::setting('block_id', 0),
                    'bank_account_id' => (int) self::setting('bank_account_id', 0),
                    'comment' => (string) self::setting('note', ''),
                    'electronic' => (bool) self::setting('eszamla', false),
                    'round' => (string) self::setting('round', ''),
                    'conversion_rate' => $conversionRate,
                ],
                'header' => [
                    'kelt_datum' => $today,
                    'teljesites_datum' => $fulfillment,
                    'fizetesi_hatarido_datum' => $due,
                    'rendeles_szam' => (string) ($order->id ?? ''),
                    'vendor_id' => BillingoStatus::vendorId((string) ($order->id ?? ''), self::PROVIDER, $docType === self::DOC_ROLE_INVOICE ? self::DOC_ROLE_INVOICE : $docType, $sequence),
                ],
                'buyer' => $this->buyer($order, $businessInfo),
                'items' => $components['items'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Product/shipping/fee lines with order-exact integer net/VAT/gross (same money rules as W1):
     * exclusive line_total is net; inclusive is gross; no_tax => vat 0. Discounts are already in
     * line_total. Anything not covered by the collected lines is added as ONE balancing line up to the
     * order's real gross/VAT totals so the document always sums to the order total.
     *
     * @return array{items:list<array<string,mixed>>}
     */
    private function collectComponents(object $order, int $taxBehavior): array
    {
        $currency = strtoupper((string) ($order->currency ?? 'HUF'));
        $items = [];
        $orderItems = method_exists($order, 'order_items') ? $order->order_items()->get() : [];
        foreach ($this->toList($orderItems) as $line) {
            $line = is_array($line) ? (object) $line : $line;
            if (!is_object($line)) {
                continue;
            }
            $quantity = (int) round((float) ($line->quantity ?? 1));
            if ($quantity <= 0) {
                continue;
            }
            $lineTotal = FluentCartMoney::fromCartCents((int) ($line->line_total ?? $line->subtotal ?? 0), $currency);
            $taxAmount = FluentCartMoney::fromCartCents((int) ($line->tax_amount ?? 0), $currency);
            [$net, $vat, $gross] = $this->splitAmounts($lineTotal, $taxAmount, $taxBehavior);
            $items[] = [
                'name' => (string) ($line->title ?? ''),
                'quantity' => $quantity,
                'unit' => 'db',
                'net_minor' => $net,
                'vat_minor' => $vat,
                'gross_minor' => $gross,
                'vat_code' => $this->vatCode($line->line_meta ?? null) ?? $this->primaryVatCode($order),
                'entitlement' => (string) self::setting('entitlement', ''),
            ];
        }

        $collectedGross = $collectedVat = 0;
        foreach ($items as $item) {
            $collectedGross += (int) $item['gross_minor'];
            $collectedVat += (int) $item['vat_minor'];
        }
        $targetGross = FluentCartMoney::fromCartCents((int) ($order->total_amount ?? 0), $currency);
        $targetVat = FluentCartMoney::fromCartCents((int) ($order->tax_total ?? 0), $currency);
        $remainingGross = $targetGross - $collectedGross;

        if ($remainingGross > 0) {
            $remainingVat = $targetVat - $collectedVat;
            if ($remainingVat < 0 || $remainingVat > $remainingGross) {
                $remainingVat = 0;
            }
            $shippingTotal = FluentCartMoney::fromCartCents((int) ($order->shipping_total ?? 0), $currency);
            $items[] = [
                'name' => (string) self::setting($shippingTotal !== 0 ? 'shipping_label' : 'fee_label', $shippingTotal !== 0 ? 'Szállítási költség' : 'Kezelési költség'),
                'quantity' => 1,
                'unit' => 'db',
                'net_minor' => $remainingGross - $remainingVat,
                'vat_minor' => $remainingVat,
                'gross_minor' => $remainingGross,
                'vat_code' => $this->feeVatOverride() ?? $this->primaryVatCode($order),
                'entitlement' => (string) self::setting('fee_entitlement', (string) self::setting('entitlement', '')),
            ];
        } elseif ($remainingGross < 0) {
            do_action('fch/billingo/invoice_amount_mismatch', (int) ($order->id ?? 0), $collectedGross, $targetGross);
        }

        return ['items' => $items];
    }

    /** @param array<string,mixed> $businessInfo @return array<string,mixed> */
    private function buyer(object $order, array $businessInfo): array
    {
        $address = method_exists($order, 'billing_address') ? $order->billing_address : null;
        $company = trim((string) ($businessInfo['company_name'] ?? ''));
        $name = $company !== '' ? $company : trim((string) ($address->name ?? ''));
        if ($name === '') {
            $name = trim(((string) ($address->first_name ?? '')) . ' ' . ((string) ($address->last_name ?? '')));
        }
        if ($name === '') {
            $name = 'Vevő';
        }
        $meta = is_array($address->meta ?? null) ? $address->meta : [];
        $phone = trim((string) ($meta['other_data']['phone'] ?? ''));
        $taxNumber = trim((string) ($businessInfo['tax_number'] ?? ''));
        $taxCountry = strtoupper(trim((string) ($businessInfo['tax_number_country'] ?? '')));

        return [
            'nev' => $name,
            'orszag' => strtoupper((string) ($address->country ?? 'HU')),
            'irsz' => (string) ($address->postcode ?? ''),
            'telepules' => (string) ($address->city ?? ''),
            'cim' => (string) ($address->address_1 ?? ''),
            'cim2' => (string) ($address->address_2 ?? ''),
            'email' => (string) ($address->email ?? ''),
            'phone' => $phone,
            'adoszam' => ($taxNumber !== '' && ($taxCountry === 'HU' || $taxCountry === '')) ? $taxNumber : '',
            'adoszamEU' => ($taxNumber !== '' && $taxCountry !== 'HU' && $taxCountry !== '') ? $taxNumber : '',
        ];
    }

    private function conversionRate(string $currency): ?float
    {
        if ($currency === 'HUF') {
            return 1.0;
        }
        $rate = (float) self::setting('conversion_rate_' . strtolower($currency), 0.0);
        if ($rate <= 0) {
            $rate = (float) self::setting('conversion_rate', 0.0);
        }
        return $rate > 0 ? $rate : null;
    }

    private function codBeforeDispatch(object $order, string $docType): bool
    {
        return $docType === self::DOC_ROLE_INVOICE
            && InvoicerSelection::codInvoiceEvent() === InvoicerSelection::INVOICE_ON_BEFORE_DISPATCH
            && (string) ($order->payment_method ?? '') === self::COD_METHOD;
    }

    public function loadOrder(int $orderId): ?object
    {
        $class = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($class)) {
            return null;
        }
        $order = $class::find($orderId);
        return is_object($order) ? $order : null;
    }

    // ---------------------------------------------------------------- small helpers

    /** @param array<string,mixed> $snapshot */
    private function clientConfig(array $snapshot, string $docType): array
    {
        $seller = (array) ($snapshot['seller'] ?? []);
        return [
            'block_id' => (int) ($seller['block_id'] ?? 0),
            'bank_account_id' => (int) ($seller['bank_account_id'] ?? 0),
            'language' => (string) ($seller['language'] ?? 'hu'),
            'payment_method' => (string) ($seller['payment_method'] ?? 'elore_utalas'),
            'comment' => (string) ($seller['comment'] ?? ''),
            'electronic' => (bool) ($seller['electronic'] ?? false),
            'round' => (string) ($seller['round'] ?? ''),
            'conversion_rate' => (float) ($seller['conversion_rate'] ?? 1.0),
            'vat_override' => (string) self::setting('vat_override', ''),
            'entitlement' => (string) self::setting('entitlement', ''),
            'type' => $docType,
        ];
    }

    /** @return array<string,string> */
    private function paymentMethodOverrides(): array
    {
        $map = [];
        foreach (['offline_payment', 'barion', 'simplepay'] as $slug) {
            $value = trim((string) self::setting('payment_method_' . $slug, ''));
            if ($value !== '') {
                $map[$slug] = $value;
            }
        }
        return $map;
    }

    private function feeVatOverride(): ?string
    {
        $value = trim((string) self::setting('fee_vat_override', ''));
        return $value === '' ? null : $value;
    }

    /** @return array{0:int,1:int,2:int} */
    private function splitAmounts(int $lineTotal, int $taxAmount, int $taxBehavior): array
    {
        if ($taxBehavior === 2) {
            $gross = $lineTotal;
            $vat = max(0, $taxAmount);
            return [$gross - $vat, $vat, $gross];
        }
        if ($taxBehavior === 1) {
            $net = $lineTotal;
            $vat = max(0, $taxAmount);
            return [$net, $vat, $net + $vat];
        }
        return [$lineTotal, 0, $lineTotal];
    }

    private function vatCode($lineMeta): ?string
    {
        $meta = is_array($lineMeta) ? $lineMeta : (array) $lineMeta;
        foreach ((array) ($meta['tax_config']['rates'] ?? []) as $rate) {
            $percent = $rate['rate_percent'] ?? $rate['rate'] ?? null;
            if ($percent !== null && is_numeric($percent)) {
                $formatted = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
                return $formatted === '' ? '0' : $formatted;
            }
        }
        return null;
    }

    private function primaryVatCode(object $order): string
    {
        if (method_exists($order, 'getDisplayTaxLines')) {
            foreach ($this->toList($order->getDisplayTaxLines()) as $line) {
                $rate = $line['rate_percent'] ?? null;
                if ($rate !== null && is_numeric($rate) && (float) $rate > 0) {
                    $formatted = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
                    return $formatted === '' ? '0' : $formatted;
                }
            }
        }
        return '0';
    }

    private function amountBasis(int $taxBehavior): string
    {
        $configured = strtolower((string) self::setting('amount_basis', 'auto'));
        if (in_array($configured, ['net', 'gross'], true)) {
            return $configured;
        }
        return $taxBehavior === 2 ? 'gross' : 'net';
    }

    /** @return list<mixed> */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return array_values((array) $value->toArray());
        }
        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value));
        }
        return [];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function vatSnapshot(array $snapshot): array
    {
        $groups = [];
        $lines = [];
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            $code = (string) ($item['vat_code'] ?? '0');
            if (!isset($groups[$code])) {
                $groups[$code] = ['vat_code' => $code, 'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0, 'lines' => 0];
            }
            $groups[$code]['net_minor'] += (int) ($item['net_minor'] ?? 0);
            $groups[$code]['vat_minor'] += (int) ($item['vat_minor'] ?? 0);
            $groups[$code]['gross_minor'] += (int) ($item['gross_minor'] ?? 0);
            $groups[$code]['lines']++;
            // W4: keep the original item lines + debtor snapshot with the document for later corrections.
            $lines[] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => $item['quantity'] ?? 1,
                'unit' => (string) ($item['unit'] ?? 'db'),
                'vat_code' => $code,
                'net_minor' => (int) ($item['net_minor'] ?? 0),
                'vat_minor' => (int) ($item['vat_minor'] ?? 0),
                'gross_minor' => (int) ($item['gross_minor'] ?? 0),
            ];
        }
        return [
            'groups' => array_values($groups),
            'lines' => $lines,
            'buyer' => is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : [],
            'amount_basis' => (string) ($snapshot['amount_basis'] ?? 'gross'),
        ];
    }

    private function providerAmountToMinor(mixed $total, string $currency): ?int
    {
        try {
            if (is_int($total)) {
                return Money::toMinor($total, $currency);
            }
            if (is_float($total)) {
                $exp = Money::exponent($currency);
                $scaled = $total * (10 ** $exp);
                if (abs($scaled - round($scaled)) > 0.000001) {
                    return null;
                }
                return Money::toMinor(rtrim(rtrim(number_format($total, $exp, '.', ''), '0'), '.'), $currency);
            }
            if (is_string($total) && trim($total) !== '') {
                return Money::toMinor(trim($total), $currency);
            }
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return null;
    }

    /** @param array<string,mixed> $flag */
    private function mergeSnapshotFlag(string $json, string $key, mixed $flag): string
    {
        $data = json_decode($json, true);
        $data = is_array($data) ? $data : [];
        $data[$key] = $flag;
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        return is_string($encoded) ? $encoded : $json;
    }

    public static function ensureDocumentInProgress(InvoiceDocumentRepository $repo, int $docId): void
    {
        $row = $repo->getById($docId);
        if ($row === null) {
            return;
        }
        $state = (string) $row->state;
        if (in_array($state, [ExternalOperation::IN_PROGRESS, ExternalOperation::EXTERNAL_CONFIRMED, ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED, ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            return;
        }
        if (method_exists($repo, 'markStarted')) {
            $repo->markStarted($docId);
        }
    }

    private function markDocument(int $docId, string $terminalState): void
    {
        self::ensureDocumentInProgress($this->documents, $docId);
        if ($terminalState === ExternalOperation::UNCERTAIN) {
            $this->documents->markUncertain($docId);
            return;
        }
        if ($terminalState === ExternalOperation::PROVEN_REJECTED) {
            $this->documents->markRejected($docId);
        }
    }

    private static function setting(string $key, mixed $default = null): mixed
    {
        return (new ModuleSettings())->get(self::SETTINGS_SCOPE, $key, $default);
    }

    private function dateOnly(mixed $value): ?string
    {
        $value = (string) $value;
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d', $timestamp);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $value === null ? '' : (string) $value;
        return $value === '' ? null : $value;
    }

    private function summary(string $status, string $state, int $orderId, ?int $docId, ?string $number, ?string $pdfRef, ?string $reason = null): array
    {
        $out = [
            'status' => $status,
            'state' => $state,
            'order_id' => $orderId,
            'document_id' => $docId,
            'document_number' => $number,
            'pdf_ref' => $pdfRef,
        ];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        return $out;
    }
}
