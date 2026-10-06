<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;

/**
 * Resolves an UNCERTAIN Számlázz invoice create by *reading back* the document — never by re-sending it
 * (04_SZAMLAZZ_HU_CONTRACT.md §6; invariant §1.4). Lookup order: `szamlaKulsoAzon` then `rendelesSzam`
 * (04 SZAMLAZZ §5 Query XML). Found => SUCCESS + markIssued; explicit "unknown id" (code 7) => REJECTED;
 * timeout/unparseable => UNCERTAIN (stays in the manual reconciliation queue).
 *
 * Registered as a callable `fn(object $ledgerRow): ProviderCallResult` in ReconcilerRegistry.
 */
final class SzamlazzReconciler
{
    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        // W4 corrections have their own read-back (query by our szamlaKulsoAzon key).
        if (in_array((string) ($ledgerRow->kind ?? ''), ['storno_invoice', 'corrected_invoice'], true)) {
            return (new SzamlazzCorrectionService())->reconcile($ledgerRow);
        }

        $vendorId = trim((string) ($ledgerRow->external_ref2 ?? ''));
        $orderRef = trim((string) ($ledgerRow->business_reference ?? ''));
        $request = [
            'operation' => 'query_xml',
            'szamlaKulsoAzon' => $vendorId,
            'rendelesSzam' => $orderRef,
        ];

        $agentKey = (string) SzamlazzModule::agentKey();
        if (trim($agentKey) === '') {
            return ProviderCallResult::uncertain('MISSING_AGENT_KEY', $request);
        }
        if ($vendorId === '' && $orderRef === '') {
            return ProviderCallResult::uncertain('NO_QUERY_REFERENCE', $request);
        }

        $query = [];
        if ($vendorId !== '') {
            $query['szamla_kulso_azon'] = $vendorId;
        }
        if ($orderRef !== '') {
            $query['rendeles_szam'] = $orderRef;
        }

        $client = new SzamlazzClient($agentKey);
        $response = $client->queryInvoice($query);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request, ['error' => (string) $response->error]);
        }

        $parsed = SzamlazzResponseParser::parse($response->body, $response->headers);
        $responseSummary = [
            'structure' => (string) ($parsed['structure'] ?? 'unknown'),
            'sikeres' => (bool) ($parsed['ok'] ?? false),
            'szamlaszam' => $parsed['invoice_number'] ?? null,
            'hibakod' => $parsed['error_code'] ?? null,
        ];

        if (!empty($parsed['ok'])) {
            $number = (string) ($parsed['invoice_number'] ?? '');
            if ($number === '') {
                return ProviderCallResult::uncertain('QUERY_MISSING_DOCUMENT_NUMBER', $request, $responseSummary);
            }
            $fields = [
                'external_id' => $number,
                'document_number' => $number,
                'business_reference' => $orderRef,
            ];
            if ($vendorId !== '') {
                $fields['external_ref2'] = $vendorId;
            }
            if (isset($parsed['gross']) && $parsed['gross'] !== null && is_numeric($parsed['gross'])) {
                $fields['gross_minor'] = (int) $parsed['gross'];
            }
            if (isset($parsed['net']) && $parsed['net'] !== null && is_numeric($parsed['net'])) {
                $fields['net_minor'] = (int) $parsed['net'];
            }
            $this->confirmDocument((int) ($ledgerRow->owner_id ?? 0), $fields, $vendorId);
            return ProviderCallResult::success($fields, $request, $responseSummary);
        }

        if (!empty($parsed['local'])) {
            return ProviderCallResult::uncertain('QUERY_UNPARSEABLE', $request, $responseSummary);
        }

        // Definitive provider error (e.g. code 7 "unknown invoice/order id") proves the document was never
        // created: the operation is rejected, and the create worker must not be re-run blindly.
        return ProviderCallResult::rejected(
            (string) ($parsed['error_code'] ?? 'QUERY_ABSENT'),
            (string) ($parsed['error_message'] ?? ''),
            $request,
            $responseSummary
        );
    }

    /** @param array<string,mixed> $fields */
    private function confirmDocument(int $docId, array $fields, string $vendorId): void
    {
        if ($docId <= 0) {
            return;
        }
        $repo = new InvoiceDocumentRepository();
        $row = $repo->getById($docId);
        if ($row === null) {
            return;
        }

        $state = (string) $row->state;
        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return;
        }
        if (!in_array($state, [ExternalOperation::IN_PROGRESS, ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            SzamlazzInvoiceService::ensureDocumentInProgress($repo, $docId);
            $row = $repo->getById($docId);
            $state = (string) ($row->state ?? '');
        }
        if ($state === ExternalOperation::UNCERTAIN) {
            $repo->markAwaitingReconciliation($docId);
            $state = ExternalOperation::AWAITING_RECONCILIATION;
        }
        if (!in_array($state, [ExternalOperation::IN_PROGRESS, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            return;
        }

        $extra = [
            'document_number' => (string) ($fields['document_number'] ?? ''),
            'external_id' => (string) ($fields['document_number'] ?? ''),
            'issued_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($vendorId !== '') {
            $extra['external_ref2'] = $vendorId;
        }
        foreach (['gross_minor', 'net_minor'] as $key) {
            if (array_key_exists($key, $fields)) {
                $extra[$key] = (int) $fields[$key];
            }
        }

        $this->attachPdfBestEffort((string) ($fields['document_number'] ?? ''), $vendorId, $extra);
        $repo->markIssued($docId, $extra);
    }

    /** Best-effort PDF read-back during reconciliation; never blocks the proved outcome. @param array<string,mixed> $extra */
    private function attachPdfBestEffort(string $documentNumber, string $vendorId, array &$extra): void
    {
        if ($documentNumber === '') {
            return;
        }
        $agentKey = (string) SzamlazzModule::agentKey();
        if (trim($agentKey) === '') {
            return;
        }
        try {
            $client = new SzamlazzClient($agentKey);
            $opts = $vendorId !== '' ? ['szamla_kulso_azon' => $vendorId] : [];
            $pdf = SzamlazzClient::extractPdf($client->downloadInvoicePdf($documentNumber, $opts));
            if ($pdf !== null && $pdf !== '') {
                $stored = (new SzamlazzDocumentStore())->store('szamlazz-invoice-' . $documentNumber, $pdf, 'pdf');
                $extra['pdf_ref'] = $stored['ref'];
                $extra['pdf_sha256'] = $stored['sha256'];
            }
        } catch (\Throwable $e) {
            // PDF is a copy; the document itself is already proved.
        }
    }
}
