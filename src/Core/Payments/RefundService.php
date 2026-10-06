<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Payments;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Shared refund entry point (W4_EXECUTION_PLAN §4; goal §3 "egy dedupált üzleti belépési pont").
 *
 * Both the FluentCart native refund path (gateway `processRefund`) and the plugin's own admin path — and
 * the manual/offline recording path — converge here. Invariants enforced here, not by callers:
 *  - a refund is bound to the ORIGINAL payment (provider/mode/account/currency/charge evidence),
 *    never to the current checkout/settings selection;
 *  - the available amount is charge minus confirmed refunds minus open reservations; reservation and
 *    claim happen under a per-order advisory lock, so parallel different intents cannot over-refund;
 *  - the same intent (token, or the same parent+amount while an operation is open) reuses one operation
 *    and dispatches at most once; an UNCERTAIN reservation never becomes spendable again on lease expiry;
 *  - FluentCart's financial state follows the ledger: on an uncertain outcome the freshly created FC
 *    refund transaction is parked in `pending`, rejected outcomes become `failed` and restore the
 *    parent's refunded_total, confirmation promotes it to `refunded` and re-syncs the order.
 */
final class RefundService
{
    public const OPEN_STATES = [
        ExternalOperation::NOT_STARTED,
        ExternalOperation::IN_PROGRESS,
        ExternalOperation::UNCERTAIN,
        ExternalOperation::AWAITING_RECONCILIATION,
    ];

    private LedgerRepository $ledger;

    private OperationRunner $runner;

    private ModuleSettings $settings;

    public function __construct(?LedgerRepository $ledger = null, ?OperationRunner $runner = null, ?ModuleSettings $settings = null)
    {
        $this->ledger = $ledger ?? new LedgerRepository();
        $this->runner = $runner ?? new OperationRunner($this->ledger);
        $this->settings = $settings ?? new ModuleSettings();
    }

    // ------------------------------------------------------------------ FluentCart binding

    /**
     * Gateway adapter entry (FluentCart `Refund::processRefund`). Returns the provider refund id on a
     * proven-confirmed refund, or WP_Error otherwise. Never throws.
     *
     * @return string|\WP_Error
     */
    public function handleGatewayRefund(string $provider, object $transaction, int $refundAmountCents, array $args = [])
    {
        $orderId = (int) ($transaction->order_id ?? 0);
        $parentTxnId = (int) ($transaction->id ?? 0);
        $currency = strtoupper((string) ($transaction->currency ?? ''));
        if ($orderId <= 0 || $parentTxnId <= 0 || $currency === '') {
            return new \WP_Error('fch_refund_bad_transaction', 'Érvénytelen tranzakció a visszatérítéshez.');
        }

        $amountMinor = FluentCartMoney::fromCartCents($refundAmountCents, $currency);
        $binding = $this->resolveBinding($provider, $transaction);
        $fcRefundTxnId = $this->fcFindRefundTxn($orderId, $parentTxnId, $refundAmountCents, (int) ($transaction->meta['last_fch_refund_txn'] ?? 0));

        $result = $this->request([
            'order_id' => $orderId,
            'parent_txn_id' => $parentTxnId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'provider' => $provider,
            'mode' => $binding['mode'],
            'account_id' => $binding['account_id'],
            'reason' => (string) ($args['reason'] ?? ''),
            'intent_token' => isset($args['fch_refund_intent']) ? (string) $args['fch_refund_intent'] : null,
            'fc_refund_txn_id' => $fcRefundTxnId,
        ]);

        // A replayed intent created a SECOND FluentCart refund transaction: it is a duplicate local
        // record, never money-out. Fail it (restoring the parent's refunded_total) and report the
        // already-known outcome instead of writing a second refund.
        if (($result['reused'] ?? false) === true && $fcRefundTxnId > 0) {
            RefundFinalizer::applyToFcTransaction($fcRefundTxnId, 'rejected');
            return new \WP_Error('fch_refund_duplicate_intent', 'Ugyanez a visszatérítési szándék már rögzítve van ezen a fizetésen — a rendszer nem indított új műveletet, az ismételt kérés helyi rekordját elutasítottnak jelölte.');
        }

        // Park / fail / promote the FluentCart refund transaction to mirror the ledger truth.
        if ($fcRefundTxnId > 0) {
            RefundFinalizer::applyToFcTransaction($fcRefundTxnId, $result['outcome'], $result['vendor_refund_id'] ?? null, (int) ($result['op_id'] ?? 0));
        }

        if ($result['outcome'] === 'confirmed') {
            if (($result['vendor_refund_id'] ?? '') !== '') {
                return (string) $result['vendor_refund_id'];
            }
            return new \WP_Error('fch_refund_confirmed_no_id', 'A visszatérítés megerősítést nyert, de a szolgáltató nem adott visszatérítés-azonosítót — az egyeztetés kézi ellenőrzést igényel.');
        }

        if ($result['outcome'] === 'rejected') {
            return new \WP_Error('fch_refund_rejected', $this->humanMessage($result));
        }

        if ($result['outcome'] === 'local_error') {
            return new \WP_Error('fch_refund_local_error', $this->humanMessage($result));
        }

        return new \WP_Error('fch_refund_uncertain', $this->humanMessage($result));
    }

    /**
     * The single deduped business entry point.
     *
     * @param array{
     *   order_id:int,parent_txn_id:int,amount_minor:int,currency:string,provider:string,
     *   mode?:string,account_id?:string,reason?:string,intent_token?:?string,fc_refund_txn_id?:int,manual?:bool
     * } $spec
     * @return array{outcome:string,reused:bool,op_id:int,state:string,vendor_refund_id:?string,reason:?string}
     */
    public function request(array $spec): array
    {
        $orderId = (int) $spec['order_id'];
        $parentTxnId = (int) $spec['parent_txn_id'];
        $amountMinor = (int) $spec['amount_minor'];
        $currency = strtoupper((string) $spec['currency']);
        $provider = strtolower((string) $spec['provider']);
        $mode = (string) ($spec['mode'] ?? $this->settings->mode());
        $mode = $mode === 'live' ? 'live' : 'test';
        $account = (string) ($spec['account_id'] ?? '');
        $manual = (bool) ($spec['manual'] ?? false);
        $intent = trim((string) ($spec['intent_token'] ?? ''));
        $fcTxnId = (int) ($spec['fc_refund_txn_id'] ?? 0);

        if ($amountMinor <= 0) {
            return $this->result('rejected', false, 0, '', null, 'AMOUNT_NOT_POSITIVE');
        }
        if ($currency === '') {
            return $this->result('rejected', false, 0, '', null, 'CURRENCY_MISSING');
        }
        if (!$manual && !RefundDispatcherRegistry::has($provider)) {
            return $this->result('local_error', false, 0, '', null, 'REFUND_DISPATCHER_MISSING');
        }

        $lock = $this->acquireLock('fch:refund:' . $orderId);
        try {
            $fingerprint = $this->fingerprint($parentTxnId, $amountMinor, $currency, $intent);

            // 1. Same intent (explicit token, or the same parent+amount while an operation is open).
            $existing = $this->findExisting($orderId, $parentTxnId, $amountMinor, $intent, $fingerprint);
            if ($existing !== null) {
                $state = (string) $existing->state;
                if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
                    return $this->result('confirmed', true, (int) $existing->id, $state, $this->nullableString($existing->external_id), 'ALREADY_CONFIRMED');
                }
                if ($state === ExternalOperation::PROVEN_REJECTED) {
                    return $this->result('rejected', true, (int) $existing->id, $state, null, 'ALREADY_REJECTED');
                }
                if ($state === ExternalOperation::NOT_STARTED || $state === ExternalOperation::IN_PROGRESS) {
                    return $this->result($state === ExternalOperation::NOT_STARTED ? 'uncertain' : 'uncertain', true, (int) $existing->id, $state, null, 'ALREADY_IN_FLIGHT');
                }
                return $this->result('uncertain', true, (int) $existing->id, $state, null, 'AWAITING_RECONCILIATION');
            }

            // 2. Availability: charge minus confirmed minus open reservations (ledger truth, stricter of
            //    the ledger and FluentCart's own remaining bound).
            $available = $this->availableMinor($orderId, $parentTxnId, $currency, $provider, $mode, $account);
            $check = RefundMath::validate($amountMinor, $available);
            if (!$check['ok']) {
                return $this->result('rejected', false, 0, '', null, (string) $check['reason']);
            }

            // 3. Atomic claim of a NEW refund identity (separate same-amount intents stay separate).
            $sequence = $this->nextSequence($orderId);
            $attrs = [
                'idempotency_key' => sprintf('fch:%d:refund:%d', $orderId, $sequence),
                'module' => 'payments',
                'provider' => $provider,
                'kind' => 'refund',
                'mode' => $mode,
                'account_id' => $account !== '' ? $account : 'default',
                'order_id' => $orderId,
                'owner_type' => $fcTxnId > 0 ? 'fc_refund_txn' : 'none',
                'owner_id' => $fcTxnId > 0 ? $fcTxnId : 0,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'business_reference' => (string) $parentTxnId,
                'request_fingerprint' => $fingerprint,
                'max_attempts' => 1,
            ];

            if ($manual) {
                $claim = $this->ledger->claim(array_merge($attrs, ['state' => ExternalOperation::NOT_STARTED]));
                if ($claim['created']) {
                    $this->ledger->transition((int) $claim['row']->id, ExternalOperation::IN_PROGRESS, [], ExternalOperation::NOT_STARTED);
                    $this->ledger->transition((int) $claim['row']->id, ExternalOperation::EXTERNAL_CONFIRMED, [
                        'response_payload_redacted' => (string) wp_json_encode(['manual' => true, 'reason' => (string) ($spec['reason'] ?? ''), 'recorded_at' => gmdate('c')]),
                    ], ExternalOperation::IN_PROGRESS);
                }
                $row = $this->ledger->getById((int) $claim['row']->id);
                return $this->result('confirmed', !$claim['created'], (int) $row->id, (string) $row->state, null, $claim['created'] ? null : 'ALREADY_CONFIRMED');
            }

            $dispatcher = RefundDispatcherRegistry::get($provider);
            $run = $this->runner->run($attrs, static function (object $ledgerRow) use ($dispatcher) {
                return $dispatcher($ledgerRow);
            });

            $row = $run['row'];
            $state = (string) $row->state;
            $vendorId = $this->nullableString($row->external_id);
            $outcome = match ($state) {
                ExternalOperation::EXTERNAL_CONFIRMED => 'confirmed',
                ExternalOperation::PROVEN_REJECTED => 'rejected',
                ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION => 'uncertain',
                default => 'local_error',
            };

            return $this->result($outcome, !$run['created'], (int) $row->id, $state, $vendorId, $outcome === 'confirmed' ? null : $outcome);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Manual/offline money-back recording (goal §3: separate from the gateway refund path). Validates
     * against the order's recorded charge, records a confirmed manual operation and lets the caller
     * update FluentCart through its own refund service (manual filter).
     *
     * @return array{outcome:string,reason:?string,op_id:int,amount_minor:int}
     */
    public function validateManualRefund(int $orderId, int $amountCents, string $currency): array
    {
        $amountMinor = FluentCartMoney::fromCartCents($amountCents, $currency);
        if ($amountMinor <= 0) {
            return ['outcome' => 'rejected', 'reason' => 'AMOUNT_NOT_POSITIVE', 'op_id' => 0, 'amount_minor' => 0];
        }
        $available = $this->manualAvailableMinor($orderId, $currency);
        $check = RefundMath::validate($amountMinor, $available);
        if (!$check['ok']) {
            return ['outcome' => 'rejected', 'reason' => (string) $check['reason'], 'op_id' => 0, 'amount_minor' => 0];
        }
        return ['outcome' => 'ok', 'reason' => null, 'op_id' => 0, 'amount_minor' => $amountMinor];
    }

    // ------------------------------------------------------------------ FluentCart filter

    /**
     * `fluent_cart/transaction/max_refundable_amount` callback: subtracts our open reservations so
     * FluentCart's own pre-validation rejects an over-refund BEFORE it creates a refund transaction.
     */
    public function filterMaxRefundable($baseAmount, $transaction): int
    {
        $base = (int) $baseAmount;
        if (!is_object($transaction)) {
            return $base;
        }
        $orderId = (int) ($transaction->order_id ?? 0);
        $parentId = (int) ($transaction->id ?? 0);
        if ($orderId <= 0 || $parentId <= 0) {
            return $base;
        }
        return max(0, $base - $this->openReservedMinor($orderId, $parentId));
    }

    // ------------------------------------------------------------------ availability / inspection

    /**
     * @return array{charged_minor:int,confirmed_refunded_minor:int,open_reserved_minor:int,available_minor:int,currency:string}
     */
    public function availabilityForTransaction($transaction): array
    {
        $orderId = (int) ($transaction->order_id ?? 0);
        $parentId = (int) ($transaction->id ?? 0);
        $currency = strtoupper((string) ($transaction->currency ?? ''));
        $charged = $this->chargedMinorForTransaction($transaction);
        $confirmed = $this->confirmedRefundedMinor($orderId, $parentId);
        $open = $this->openReservedMinor($orderId, $parentId);
        $provider = strtolower((string) ($transaction->payment_method ?? ''));
        $mode = (string) ($transaction->payment_mode ?? $this->settings->mode());
        $ledgerAvailable = RefundMath::available($charged, $confirmed, $open);

        // FluentCart's own bound as a second, independent ceiling (never refund more than FC thinks is
        // left; FC meta counts the parked pending refunds exactly like our reservations).
        $fcAvailable = null;
        $total = (int) ($transaction->total ?? 0);
        $meta = is_array($transaction->meta ?? null) ? $transaction->meta : [];
        $fcRefunded = (int) ($meta['refunded_total'] ?? 0);
        if ($total > 0) {
            $fcAvailable = max(0, FluentCartMoney::fromCartCents($total - $fcRefunded, $currency));
        }
        $available = $fcAvailable === null ? $ledgerAvailable : min($ledgerAvailable, $fcAvailable);

        return [
            'charged_minor' => $charged,
            'confirmed_refunded_minor' => $confirmed,
            'open_reserved_minor' => $open,
            'available_minor' => $available,
            'currency' => $currency,
            'provider' => $provider,
            'mode' => $mode,
        ];
    }

    /** @return list<object> refund ledger rows of one order, newest first. */
    public function refundsForOrder(int $orderId, int $limit = 20): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' ORDER BY id DESC LIMIT %d",
            $orderId,
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
    }

    /**
     * Operator read-back: resolve an uncertain refund through the provider reconciler + finalizer.
     * Returns the resulting ledger state; still-uncertain stays AWAITING for a human.
     */
    public function reconcileNow(int $opId): string
    {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => $opId]);
        } catch (\Throwable $e) {
            // RECONCILE_STILL_UNCERTAIN / NO_RECONCILER leave the row AWAITING — honest, not an error.
        }
        $row = $this->ledger->getById($opId);
        return $row === null ? '' : (string) $row->state;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{mode:string,account_id:string} */
    private function resolveBinding(string $provider, object $transaction): array
    {
        $orderId = (int) ($transaction->order_id ?? 0);
        $vendorId = trim((string) ($transaction->vendor_charge_id ?? ''));
        $payment = $this->findPaymentOperation($orderId, $provider, $vendorId);
        $mode = (string) ($payment->mode ?? $transaction->payment_mode ?? $this->settings->mode());
        $account = (string) ($payment->account_id ?? '');
        if ($account === '') {
            $account = (string) $this->settings->accountId($provider);
        }
        return ['mode' => $mode === 'live' ? 'live' : 'test', 'account_id' => $account];
    }

    private function findPaymentOperation(int $orderId, string $provider, string $vendorChargeId): ?object
    {
        global $wpdb;
        if ($vendorChargeId !== '') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND provider = %s AND kind = 'payment_start' AND external_id = %s ORDER BY id DESC LIMIT 1",
                $orderId,
                $provider,
                $vendorChargeId
            ));
            if ($row !== null) {
                return $row;
            }
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND provider = %s AND kind = 'payment_start' AND state = %s ORDER BY id DESC LIMIT 1",
            $orderId,
            $provider,
            ExternalOperation::EXTERNAL_CONFIRMED
        ));
        return $row ?: null;
    }

    private function chargedMinorForTransaction($transaction): int
    {
        $orderId = (int) ($transaction->order_id ?? 0);
        $currency = strtoupper((string) ($transaction->currency ?? ''));
        $provider = strtolower((string) ($transaction->payment_method ?? ''));
        $vendorId = trim((string) ($transaction->vendor_charge_id ?? ''));
        $payment = $this->findPaymentOperation($orderId, $provider, $vendorId);
        if ($payment !== null && (int) $payment->amount_minor > 0 && strtoupper((string) $payment->currency) === $currency) {
            return (int) $payment->amount_minor;
        }
        // Fallback: FluentCart's charge transaction is charge evidence ONLY when it succeeded —
        // an unpaid/failed/refunded charge must never become refundable.
        $status = strtolower((string) ($transaction->status ?? ''));
        if ($status !== 'succeeded') {
            return 0;
        }
        return FluentCartMoney::fromCartCents((int) ($transaction->total ?? 0), $currency);
    }

    private function availableMinor(int $orderId, int $parentTxnId, string $currency, string $provider, string $mode, string $account): int
    {
        $charged = 0;
        $fcTxn = $this->fcLoadTransaction($parentTxnId);
        if ($fcTxn !== null) {
            $charged = $this->chargedMinorForTransaction($fcTxn);
        } else {
            $payment = $this->findPaymentOperation($orderId, $provider, '');
            $charged = $payment !== null && strtoupper((string) $payment->currency) === $currency ? (int) $payment->amount_minor : 0;
        }
        $confirmed = $this->confirmedRefundedMinor($orderId, $parentTxnId);
        $open = $this->openReservedMinor($orderId, $parentTxnId);
        return RefundMath::available($charged, $confirmed, $open);
    }

    private function manualAvailableMinor(int $orderId, string $currency): int
    {
        global $wpdb;
        $charged = 0;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        if ($order !== null) {
            // FluentCart keeps the refunded total on transaction meta, not on the order row; the manual
            // bound is the recorded paid amount minus our open reservations (computed below).
            $charged = FluentCartMoney::fromCartCents((int) $order->total_paid, $currency);
        }
        if ($charged <= 0) {
            $sum = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'payment_start' AND state = %s",
                $orderId,
                ExternalOperation::EXTERNAL_CONFIRMED
            ));
            $charged = $sum;
        }
        $open = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' AND state IN ('" . implode("','", self::OPEN_STATES) . "')",
            $orderId
        ));
        return RefundMath::available($charged, 0, $open);
    }

    private function confirmedRefundedMinor(int $orderId, int $parentTxnId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' AND business_reference = %s AND state = %s",
            $orderId,
            (string) $parentTxnId,
            ExternalOperation::EXTERNAL_CONFIRMED
        ));
    }

    private function openReservedMinor(int $orderId, int $parentTxnId): int
    {
        global $wpdb;
        $states = "'" . implode("','", self::OPEN_STATES) . "'";
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' AND business_reference = %s AND state IN ({$states})",
            $orderId,
            (string) $parentTxnId
        ));
    }

    private function findExisting(int $orderId, int $parentTxnId, int $amountMinor, string $intent, string $fingerprint): ?object
    {
        global $wpdb;
        if ($intent !== '') {
            $byIntent = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' AND request_fingerprint = %s ORDER BY id DESC LIMIT 1",
                $orderId,
                $fingerprint
            ));
            if ($byIntent !== null && !ExternalOperation::isTerminal((string) $byIntent->state)) {
                return $byIntent;
            }
            // A terminal intent match is returned too: the caller maps it to an idempotent outcome.
            if ($byIntent !== null) {
                return $byIntent;
            }
        }
        $states = "'" . implode("','", self::OPEN_STATES) . "'";
        $byParentAmount = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund' AND business_reference = %s AND amount_minor = %d AND state IN ({$states}) ORDER BY id DESC LIMIT 1",
            $orderId,
            (string) $parentTxnId,
            $amountMinor
        ));
        return $byParentAmount ?: null;
    }

    private function nextSequence(int $orderId): int
    {
        global $wpdb;
        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(idempotency_key, ':', -1) AS UNSIGNED)), 0) FROM {$wpdb->prefix}fch_external_operations WHERE order_id = %d AND kind = 'refund'",
            $orderId
        ));
        return $max + 1;
    }

    private function fingerprint(int $parentTxnId, int $amountMinor, string $currency, string $intent): string
    {
        return $intent !== ''
            ? 'refund|parent=' . $parentTxnId . '|amount=' . $amountMinor . '|cur=' . $currency . '|intent=' . $intent
            : 'refund|parent=' . $parentTxnId . '|amount=' . $amountMinor . '|cur=' . $currency . '|auto';
    }

    /**
     * Locates the FluentCart refund transaction FluentCart created immediately before calling the
     * gateway (Refund.php:60-63). Newest matching row wins; it is only used for state mirroring.
     */
    private function fcFindRefundTxn(int $orderId, int $parentTxnId, int $refundAmountCents, int $hintTxnId = 0): int
    {
        if (!class_exists('\FluentCart\App\Models\OrderTransaction')) {
            return 0;
        }
        try {
            if ($hintTxnId > 0) {
                $hint = \FluentCart\App\Models\OrderTransaction::query()->find($hintTxnId);
                if ($hint !== null && (int) $hint->order_id === $orderId) {
                    return (int) $hint->id;
                }
            }
            $candidates = \FluentCart\App\Models\OrderTransaction::query()
                ->where('order_id', $orderId)
                ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_REFUND)
                ->orderBy('id', 'desc')
                ->limit(10)
                ->get();
            foreach ($candidates as $candidate) {
                $meta = is_array($candidate->meta ?? null) ? $candidate->meta : [];
                $parent = (int) ($meta['parent_id'] ?? 0);
                if ($parent !== $parentTxnId) {
                    continue;
                }
                if ((int) $candidate->total !== $refundAmountCents) {
                    continue;
                }
                if ((string) $candidate->status === \FluentCart\App\Helpers\Status::TRANSACTION_REFUNDED
                    && trim((string) ($candidate->vendor_charge_id ?? '')) === '') {
                    return (int) $candidate->id;
                }
            }
        } catch (\Throwable $e) {
            // Best effort: without the FC row we still keep ledger truth.
        }
        return 0;
    }

    private function fcLoadTransaction(int $txnId): ?object
    {
        if (!class_exists('\FluentCart\App\Models\OrderTransaction')) {
            return null;
        }
        try {
            return \FluentCart\App\Models\OrderTransaction::query()->find($txnId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{outcome:string,reused:bool,op_id:int,state:string,vendor_refund_id:?string,reason:?string} */
    private function result(string $outcome, bool $reused, int $opId, string $state, ?string $vendorId, ?string $reason): array
    {
        return [
            'outcome' => $outcome,
            'reused' => $reused,
            'op_id' => $opId,
            'state' => $state,
            'vendor_refund_id' => $vendorId,
            'reason' => $reason,
        ];
    }

    private function humanMessage(array $result): string
    {
        $reason = (string) ($result['reason'] ?? '');
        $map = [
            'AMOUNT_NOT_POSITIVE' => 'A visszatérítendő összeg nulla vagy negatív.',
            'NOTHING_REFUNDABLE' => 'Ezen a tranzakción nincs több visszatéríthető összeg.',
            'EXCEEDS_REFUNDABLE' => 'A kért összeg meghaladja a visszatéríthető összeget (a függő visszatérítések is foglaltnak számítanak).',
            'CURRENCY_MISSING' => 'Hiányzó pénznem.',
            'REFUND_DISPATCHER_MISSING' => 'Ehhez a szolgáltatóhoz nincs refund-útvonal ebben a verzióban.',
            'ALREADY_CONFIRMED' => 'Ez a visszatérítési szándék már megvalósult — nincs új művelet.',
            'ALREADY_REJECTED' => 'Ezt a visszatérítési szándékot a szolgáltató korábban elutasította.',
            'ALREADY_IN_FLIGHT' => 'Ugyanez a visszatérítési szándék már folyamatban van — nincs új hívás.',
            'AWAITING_RECONCILIATION' => 'A visszatérítés egyeztetés alatt áll; az összeg foglalt marad, amíg az eredmény bizonyítást nem nyer.',
            'uncertain' => 'A szolgáltató válasza bizonytalan — az egyeztetés folyamatban, az összeg foglalt marad.',
            'local_error' => 'A visszatérítés helyi hiba miatt nem indult el; a rendszer nem próbálkozik automatikusan újra.',
            'rejected' => 'A szolgáltató elutasította a visszatérítést.',
        ];
        return $map[$reason] ?? 'A visszatérítés nem fejeződött be (állapot: ' . $result['outcome'] . ').';
    }

    private function acquireLock(string $name): ?string
    {
        global $wpdb;
        if (!method_exists($wpdb, 'get_var')) {
            return null;
        }
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        return (string) $got === '1' ? $name : null;
    }

    private function releaseLock(?string $name): void
    {
        if ($name === null) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
