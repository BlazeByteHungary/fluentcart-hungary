<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Billing;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Connects a CONFIRMED money refund to the merchant's explicit invoice-correction policy
 * (W4 invoicing scope, "kapcsolt életciklus").
 *
 * Invariants:
 *  - only an explicit policy can act; the default is `off`;
 *  - only a FULL refund (proven by the FluentCart refund transactions: refunded >= paid) can trigger the
 *    automatic storno; a partial refund never does;
 *  - the correction is a separate operation component: a previously confirmed refund is never repeated by
 *    the correction path (and vice versa) — the correction service only touches the document chain;
 *  - the active confirmed automatic invoice is the target, and the correction follows the ORIGINAL
 *    document's provider/mode/account even after the merchant switched invoicers;
 *  - everything is best effort and idempotent: a failure here leaves the correction ledger row
 *    NOT_STARTED for an operator resume (`Folyatás`), it can never corrupt the confirmed refund.
 */
final class RefundCorrectionLinker
{
    public const HOOK = 'fch/refund/confirmed';

    private InvoiceDocumentRepository $documents;

    private ?InvoiceCorrectionService $corrections;

    public function __construct(?InvoiceDocumentRepository $documents = null, ?InvoiceCorrectionService $corrections = null)
    {
        $this->documents = $documents ?? new InvoiceDocumentRepository();
        $this->corrections = $corrections;
    }

    /** @param mixed $refundRow refund ledger row (fch_external_operations) */
    public function onRefundConfirmed($refundRow): void
    {
        try {
            if (!is_object($refundRow) || (string) ($refundRow->kind ?? '') !== 'refund') {
                return;
            }
            if ((string) ($refundRow->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                return;
            }
            $orderId = (int) ($refundRow->order_id ?? 0);
            if ($orderId <= 0) {
                return;
            }

            $policy = CorrectionPolicy::normalize((string) (new ModuleSettings())->get('billing', 'refund_correction_policy', CorrectionPolicy::OFF));
            $full = $this->isFullRefund($orderId);
            $kind = CorrectionPolicy::autoKindForRefund($policy, $full);
            if ($kind === null) {
                return;
            }

            $invoiceId = $this->activeConfirmedInvoiceId($orderId);
            if ($invoiceId <= 0) {
                return;
            }

            $corrections = $this->corrections ?? new InvoiceCorrectionService();
            $corrections->request($invoiceId, $kind, [
                'link_ref' => 'refund:' . (string) ($refundRow->idempotency_key ?? ''),
                'reason' => 'Visszatérítés miatti automatikus sztornó',
                'auto' => true,
            ]);
        } catch (\Throwable $e) {
            // The refund stands; the correction can be resumed by the operator (honest NOT_STARTED row).
        }
    }

    /**
     * Full refund proof from FluentCart's own records: the sum of refunded refund transactions reaches
     * the order's paid amount. Parked (`pending`) local refund records deliberately do not count.
     */
    public function isFullRefund(int $orderId): bool
    {
        global $wpdb;
        if (!isset($wpdb->prefix)) {
            return false;
        }
        $orderClass = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($orderClass)) {
            return false;
        }
        try {
            $order = $orderClass::find($orderId);
            if (!is_object($order)) {
                return false;
            }
            $paid = (int) ($order->total_paid ?? 0);
            if ($paid <= 0) {
                return false;
            }
            $refunded = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}fct_order_transactions
                 WHERE order_id = %d AND transaction_type = 'refund' AND status = 'refunded'",
                $orderId
            ));
            return $refunded >= $paid;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The active confirmed automatic invoice of the order (the document a full refund voids). */
    private function activeConfirmedInvoiceId(int $orderId): int
    {
        $best = 0;
        foreach ($this->documents->listForOrder($orderId) as $row) {
            if ((string) ($row->doc_role ?? '') !== 'invoice') {
                continue;
            }
            if ((int) ($row->is_selected_invoicer ?? 0) !== 1) {
                continue;
            }
            if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
                continue;
            }
            $best = max($best, (int) $row->id);
        }
        return $best;
    }
}
