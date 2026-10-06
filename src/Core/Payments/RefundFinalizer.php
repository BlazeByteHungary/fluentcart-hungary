<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Payments;

use FluentCartHungary\Core\Billing\RefundCorrectionLinker;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;

/**
 * Mirrors the ledger truth onto the FluentCart refund transaction (W4_EXECUTION_PLAN §3).
 *
 * FluentCart creates the refund transaction as `refunded` BEFORE the gateway call and bumps the parent's
 * `refunded_total` (Refund.php:60-63). The financial state must follow our confirmation contract, so:
 *  - uncertain / local error -> the fresh transaction is parked in `pending` (its OrderRefund event then
 *    computes zero new refund and does not touch order totals; OrderRefund.php:54-75),
 *  - proven rejection -> `failed` and the parent's refunded_total increment is rolled back,
 *  - confirmation -> `refunded` + vendor refund id + an OrderRefund dispatch so order totals sync.
 */
final class RefundFinalizer
{
    public static function applyFromLedgerRow(int $ledgerId): void
    {
        $row = (new LedgerRepository())->getById($ledgerId);
        if ($row === null || (string) $row->kind !== 'refund') {
            return;
        }
        $fcTxnId = (int) ($row->owner_id ?? 0);
        if ((string) ($row->owner_type ?? '') !== 'fc_refund_txn' || $fcTxnId <= 0) {
            return;
        }
        $state = (string) $row->state;
        $outcome = match ($state) {
            ExternalOperation::EXTERNAL_CONFIRMED => 'confirmed',
            ExternalOperation::PROVEN_REJECTED => 'rejected',
            default => 'pending',
        };
        $vendorId = trim((string) ($row->external_id ?? ''));
        self::applyToFcTransaction($fcTxnId, $outcome, $vendorId !== '' ? $vendorId : null, $ledgerId);
    }

    public static function applyToFcTransaction(int $fcTxnId, string $outcome, ?string $vendorRefundId = null, int $ledgerId = 0): void
    {
        if (!class_exists('\FluentCart\App\Models\OrderTransaction')) {
            return;
        }
        try {
            $txn = \FluentCart\App\Models\OrderTransaction::query()->find($fcTxnId);
            if ($txn === null) {
                return;
            }
            $status = (string) $txn->status;
            $meta = is_array($txn->meta ?? null) ? $txn->meta : [];
            if ($ledgerId > 0) {
                $meta['fch_refund_op'] = $ledgerId;
            }

            if ($outcome === 'confirmed') {
                $wasRefunded = $status === \FluentCart\App\Helpers\Status::TRANSACTION_REFUNDED;
                if ($vendorRefundId !== null && $vendorRefundId !== '') {
                    $txn->vendor_charge_id = $vendorRefundId;
                }
                $txn->status = \FluentCart\App\Helpers\Status::TRANSACTION_REFUNDED;
                $txn->meta = $meta;
                $txn->save();
                if (!$wasRefunded) {
                    self::dispatchOrderRefund($txn);
                }
                // The listener is idempotent (one correction operation per refund); it must run even when
                // FluentCart had already flagged the fresh refund transaction as refunded.
                self::announceConfirmed($ledgerId);
                return;
            }

            if ($outcome === 'rejected') {
                if ($status !== \FluentCart\App\Helpers\Status::TRANSACTION_FAILED) {
                    self::rollbackParentRefundedTotal($txn, $meta);
                    $txn->status = \FluentCart\App\Helpers\Status::TRANSACTION_FAILED;
                }
                $txn->meta = $meta;
                $txn->save();
                return;
            }

            // pending / local error: park the local record so it never reads as money-out.
            if ($status === \FluentCart\App\Helpers\Status::TRANSACTION_REFUNDED) {
                $txn->status = \FluentCart\App\Helpers\Status::TRANSACTION_PENDING;
            }
            $txn->meta = $meta;
            $txn->save();
        } catch (\Throwable $e) {
            // Best effort mirror; the ledger remains the source of truth.
        }
    }

    /**
     * Announce a PROVEN refund confirmation so the invoicing side can apply the merchant's explicit
     * correction policy (W4 linked lifecycle). The listener is idempotent and best effort; a failure
     * there can never affect the confirmed refund.
     */
    private static function announceConfirmed(int $ledgerId): void
    {
        if ($ledgerId <= 0 || !function_exists('do_action')) {
            return;
        }
        try {
            $row = (new LedgerRepository())->getById($ledgerId);
            if ($row !== null) {
                do_action(RefundCorrectionLinker::HOOK, $row);
            }
        } catch (\Throwable $e) {
            // Correction linkage is advisory; the ledger row stays available for a manual resume.
        }
    }

    private static function rollbackParentRefundedTotal(object $refundTxn, array $meta): void
    {
        $parentId = (int) ($meta['parent_id'] ?? 0);
        if ($parentId <= 0) {
            return;
        }
        try {
            $parent = \FluentCart\App\Models\OrderTransaction::query()->find($parentId);
            if ($parent === null) {
                return;
            }
            $parentMeta = is_array($parent->meta ?? null) ? $parent->meta : [];
            $current = (int) ($parentMeta['refunded_total'] ?? 0);
            $parentMeta['refunded_total'] = max(0, $current - (int) $refundTxn->total);
            $parent->meta = $parentMeta;
            $parent->save();
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    private static function dispatchOrderRefund(object $refundTxn): void
    {
        if (!class_exists('\FluentCart\App\Events\Order\OrderRefund')) {
            return;
        }
        try {
            $order = $refundTxn->order;
            if ($order === null) {
                return;
            }
            (new \FluentCart\App\Events\Order\OrderRefund($order, $refundTxn))->dispatch();
        } catch (\Throwable $e) {
            // The refund is confirmed regardless; order-total sync is retried by the next admin refresh.
        }
    }
}
