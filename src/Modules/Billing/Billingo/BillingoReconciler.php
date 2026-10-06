<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Ledger\ProviderCallResult;

/**
 * Resolves an UNCERTAIN Billingo create by read-back, never by re-sending (invariant §1.4;
 * 04_BILLINGO_CONTRACT.md §6). Lookup: `GET /documents/vendor/{vendor_id}` — the documented unique
 * external id (vendor_id). Found => SUCCESS (+ PDF best effort); a 404 stays UNCERTAIN after a lost
 * response (no documented visibility guarantee; W4_EXECUTION_PLAN §5) and never re-creates the
 * document. Timeout stays UNCERTAIN (manual reconciliation queue).
 *
 * Registered as a callable `fn(object $ledgerRow): ProviderCallResult` in ReconcilerRegistry.
 */
final class BillingoReconciler
{
    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        // W4 corrections have their own read-back (cancelled flag / related_documents).
        if (in_array((string) ($ledgerRow->kind ?? ''), ['storno_invoice', 'corrected_invoice'], true)) {
            return (new BillingoCorrectionService())->reconcile($ledgerRow);
        }

        $vendorId = trim((string) ($ledgerRow->external_ref2 ?? ''));
        if ($vendorId === '') {
            $vendorId = trim((string) ($ledgerRow->business_reference ?? ''));
        }
        $request = ['operation' => 'get_document_by_vendor_id', 'vendor_id' => $vendorId];

        if ($vendorId === '') {
            return ProviderCallResult::uncertain('NO_QUERY_REFERENCE', $request);
        }
        if (trim((string) BillingoModule::apiKey()) === '') {
            return ProviderCallResult::uncertain('MISSING_API_KEY', $request);
        }

        $docId = (int) ($ledgerRow->owner_id ?? 0);
        if ($docId <= 0) {
            return ProviderCallResult::uncertain('MISSING_DOCUMENT_ROW', $request);
        }

        $result = (new BillingoInvoiceService())->reconcile($docId);
        $responseSummary = [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'reason' => (string) ($result['reason'] ?? ''),
            'document_number' => $result['document_number'] ?? null,
        ];

        return match ((string) ($result['status'] ?? '')) {
            'confirmed', 'already_confirmed' => ProviderCallResult::success([
                'external_id' => (string) ($result['document_number'] ?? ''),
                'document_number' => (string) ($result['document_number'] ?? ''),
                'external_ref2' => $vendorId,
                'business_reference' => $vendorId,
            ], $request, $responseSummary),
            // A single 404 after a dispatched-but-lost create is NOT proof of rejection: the contract
            // only documents "404 if absent" and gives no visibility guarantee for a document whose
            // create response was lost. The row stays UNCERTAIN (original identity preserved) and the
            // manual resolution path remains available (W4_EXECUTION_PLAN §5).
            'rejected' => ProviderCallResult::uncertain('QUERY_NOT_FOUND_NOT_FINAL', $request, $responseSummary),
            default => ProviderCallResult::uncertain('QUERY_' . strtoupper((string) ($result['reason'] ?? 'INCONCLUSIVE')), $request, $responseSummary),
        };
    }
}
