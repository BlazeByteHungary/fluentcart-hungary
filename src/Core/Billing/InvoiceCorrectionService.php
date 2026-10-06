<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Billing;

use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;

/**
 * Shared invoice-correction entry point (W4 invoicing scope).
 *
 * One deduped business entry for storno (`storno_invoice`) and corrected documents (`corrected_invoice`):
 *  - the operation is bound to the ORIGINAL document row: provider, mode, account and currency are taken
 *    from that row, so selecting another invoicer later never redirects an issued document;
 *  - the original row is never mutated until the correction is PROVEN: a new linked row
 *    (`storno_of_document_id`) carries the correction, the original keeps its number, amounts and PDF;
 *  - one idempotent operation per intent (`fch:{order}:{kind}:{n}`), reserved under a per-order advisory
 *    lock; repeated/parallel requests replay the same operation and never dispatch twice;
 *  - UNCERTAIN is fenced: the row is never re-dispatched, only read back by the provider reconciler;
 *  - a rejected correction leaves the original document fully intact and usable.
 *
 * Provider calls live behind CorrectionDispatcherRegistry so Core never references provider modules
 * (ADR-001). The dispatch closure performs exactly one provider call; retries go through `resume()`
 * (NOT_STARTED only) and read-backs through the reconciler machinery.
 */
final class InvoiceCorrectionService
{
    public const STORNO = 'storno_invoice';
    public const CORRECTED = 'corrected_invoice';
    public const KINDS = [self::STORNO, self::CORRECTED];

    private InvoiceDocumentRepository $documents;

    private OperationRunner $runner;

    private LedgerRepository $ledger;

    public function __construct(
        ?InvoiceDocumentRepository $documents = null,
        ?OperationRunner $runner = null,
        ?LedgerRepository $ledger = null
    ) {
        $this->documents = $documents ?? new InvoiceDocumentRepository();
        $this->runner = $runner ?? new OperationRunner();
        $this->ledger = $ledger ?? new LedgerRepository();
    }

    /**
     * Request a correction of an issued document. Idempotent by (original, kind, link reference):
     * the same intent returns the existing operation without a second provider call.
     *
     * @param array{reason?:string,link_ref?:string,intent?:string,requested_by?:string,auto?:bool} $opts
     * @return array<string,mixed>
     */
    public function request(int $originalDocId, string $kind, array $opts = []): array
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            return $this->summary('blocked', '', $originalDocId, 0, 'unknown_correction_kind');
        }

        $original = $this->documents->getById($originalDocId);
        if ($original === null) {
            return $this->summary('error', '', $originalDocId, 0, 'document_not_found');
        }
        $provider = (string) $original->provider;
        if ($provider === '' || !CorrectionDispatcherRegistry::has($provider)) {
            return $this->summary('unsupported', (string) $original->state, $originalDocId, 0, 'correction_unsupported_provider');
        }
        $docType = strtolower(trim((string) ($original->provider_doc_type ?? '')));
        if (!self::supportsDocumentType($provider, $kind, $docType)) {
            // Documented-capability fence: e.g. Billingo proforma, or amount-level correction. The
            // merchant gets a precise block and a usable manual path instead of a simulated document.
            return $this->summary('blocked', (string) $original->state, $originalDocId, 0, 'document_type_not_supported:' . ($docType === '' ? 'unknown' : $docType));
        }

        // A repeated intent is a REPLAY: it returns the same correction even after the original was
        // superseded (the storno/corrected document already exists — never a second one).
        $orderId = (int) $original->order_id;
        $linkRef = $this->linkRef($kind, $originalDocId, $opts);
        $existing = $this->findExisting($originalDocId, $kind, $linkRef);
        if ($existing !== null) {
            return $this->replay($existing, $original, $kind);
        }

        if ((string) $original->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary(
                (string) $original->state === ExternalOperation::SUPERSEDED ? 'already_corrected' : 'not_correctable',
                (string) $original->state,
                $originalDocId,
                0,
                'original_not_confirmed'
            );
        }
        $number = trim((string) ($original->document_number ?? ''));
        $externalId = trim((string) ($original->external_id ?? ''));
        if ($number === '' || $externalId === '') {
            return $this->summary('blocked', (string) $original->state, $originalDocId, 0, 'original_missing_provider_id');
        }

        $lock = $this->acquireLock('fch:correction:' . $orderId);
        try {
            $existing = $this->findExisting($originalDocId, $kind, $linkRef);
            if ($existing !== null) {
                return $this->replay($existing, $original, $kind);
            }

            $mode = (string) $original->mode;
            $account = (string) $original->account_id;
            $sequence = $this->documents->nextSequence($orderId, $kind, $provider, $mode, $account);
            $key = ExternalOperation::idempotencyKey($orderId, $kind, $sequence);
            $fingerprint = $this->fingerprint($originalDocId, $kind, $linkRef);

            $document = $this->documents->getOrCreate([
                'order_id' => $orderId,
                'order_uuid' => (string) ($original->order_uuid ?? ''),
                'provider' => $provider,
                'mode' => $mode,
                'account_id' => $account,
                'doc_role' => $kind,
                'sequence_no' => $sequence,
                'idempotency_key' => $key,
                'vendor_id' => $key,
                'external_ref2' => $linkRef,
                'storno_of_document_id' => $originalDocId,
                'is_selected_invoicer' => 0,
                'provider_doc_type' => $docType === '' ? 'invoice' : $docType,
                'currency' => (string) ($original->currency ?? ''),
                'gross_minor' => $original->gross_minor === null ? null : (int) $original->gross_minor,
                'net_minor' => $original->net_minor === null ? null : (int) $original->net_minor,
                'vat_minor' => $original->vat_minor === null ? null : (int) $original->vat_minor,
                'fulfillment_date' => $original->fulfillment_date ?? null,
                'due_date' => $original->due_date ?? null,
                'vat_snapshot' => (string) $this->encode([
                    'correction' => [
                        'kind' => $kind,
                        'reason' => (string) ($opts['reason'] ?? ''),
                        'link_ref' => $linkRef,
                        'auto' => (bool) ($opts['auto'] ?? false),
                        'requested_by' => (string) ($opts['requested_by'] ?? ''),
                        'requested_at' => gmdate('c'),
                    ],
                ]),
            ]);
            $docRow = $document['row'];
            $docId = (int) $docRow->id;

            if ((string) $docRow->provider !== $provider) {
                return $this->summary('blocked', (string) $docRow->state, $originalDocId, $docId, 'provider_binding_conflict');
            }

            $attrs = $this->ledgerAttrs($docRow, $original, $key, $fingerprint);
            $claim = $this->runner->claim($attrs);
            $opId = (int) $claim['row']->id;
            if ((int) ($docRow->operation_id ?? 0) !== $opId) {
                $this->documents->attachOperation($docId, $opId);
            }

            $state = (string) $claim['row']->state;
            if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
                CorrectionFinalizer::applyFromLedgerRow($opId);
                return $this->summary('already_confirmed', $state, $originalDocId, $docId, null, ['document_number' => $this->docNumber($docId)]);
            }
            if ($state === ExternalOperation::PROVEN_REJECTED) {
                return $this->summary('rejected', $state, $originalDocId, $docId, 'already_rejected');
            }
            if (in_array($state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                $this->runner->enqueueReconcile($opId);
                return $this->summary('uncertain', $state, $originalDocId, $docId, 'reconcile_required');
            }
            if (in_array($state, [ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED], true)) {
                return $this->summary('not_retryable', $state, $originalDocId, $docId, 'operation_' . strtolower($state));
            }
            if ($state === ExternalOperation::IN_PROGRESS) {
                return $this->summary('in_flight', $state, $originalDocId, $docId, 'already_in_flight');
            }

            return $this->dispatch($docId, $attrs);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Operator recovery: continue a correction that never left (local error), queue its read-back when
     * uncertain, or replay the proved outcome. A confirmed side effect is never repeated — only the
     * correction component is touched, the refund (or any other component) is left as it stands.
     *
     * @return array<string,mixed>
     */
    public function resume(int $correctionDocId): array
    {
        $doc = $this->documents->getById($correctionDocId);
        if ($doc === null) {
            return $this->summary('error', '', 0, $correctionDocId, 'document_not_found');
        }
        $kind = (string) $doc->doc_role;
        if (!in_array($kind, self::KINDS, true)) {
            return $this->summary('error', (string) $doc->state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'not_a_correction_document');
        }
        $opId = (int) ($doc->operation_id ?? 0);
        if ($opId <= 0) {
            return $this->summary('error', (string) $doc->state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'missing_operation');
        }
        $ledgerRow = $this->ledger->getById($opId);
        if ($ledgerRow === null) {
            return $this->summary('error', (string) $doc->state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'missing_operation');
        }

        $state = (string) $ledgerRow->state;
        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            CorrectionFinalizer::applyFromLedgerRow($opId);
            return $this->summary('already_confirmed', $state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, null, ['document_number' => $this->docNumber($correctionDocId)]);
        }
        if ($state === ExternalOperation::PROVEN_REJECTED) {
            CorrectionFinalizer::applyFromLedgerRow($opId);
            return $this->summary('rejected', $state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'already_rejected');
        }
        if (in_array($state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            $this->runner->enqueueReconcile($opId);
            return $this->summary('queued', $state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'reconcile_queued');
        }
        if ($state !== ExternalOperation::NOT_STARTED) {
            return $this->summary('in_flight', $state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'already_in_flight');
        }

        $provider = (string) $doc->provider;
        if (!CorrectionDispatcherRegistry::has($provider)) {
            return $this->summary('unsupported', $state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'correction_unsupported_provider');
        }
        $original = $this->documents->getById((int) ($doc->storno_of_document_id ?? 0));
        if ($original === null) {
            return $this->summary('error', $state, (int) ($doc->storno_of_document_id ?? 0), $correctionDocId, 'original_document_missing');
        }

        $this->ledger->clearRetryAt($opId);
        $attrs = $this->ledgerAttrs($doc, $original, (string) $ledgerRow->idempotency_key, (string) ($ledgerRow->request_fingerprint ?? ''));
        return $this->dispatch($correctionDocId, $attrs);
    }

    /** Operator read-back for an UNCERTAIN correction (never re-dispatches). */
    public function reconcileNow(int $opId): string
    {
        try {
            (new ReconcileUncertainJob())->handle(['operation_id' => $opId]);
        } catch (\Throwable $e) {
            // Still-uncertain outcomes keep the row AWAITING for a human — honest, not an error.
        }
        $row = $this->ledger->getById($opId);
        return $row === null ? '' : (string) $row->state;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private function dispatch(int $docId, array $attrs): array
    {
        $provider = (string) $attrs['provider'];
        $dispatcher = CorrectionDispatcherRegistry::get($provider);
        if ($dispatcher === null) {
            return $this->summary('unsupported', '', (int) ($attrs['business_reference'] ?? 0), $docId, 'correction_unsupported_provider');
        }

        $run = $this->runner->run($attrs, static function (object $ledgerRow) use ($dispatcher) {
            return $dispatcher($ledgerRow);
        });

        $opId = (int) $run['row']->id;
        $fields = is_array($run['result']?->fields) ? $run['result']->fields : [];
        $state = (string) $run['state'];
        $reason = $run['result']?->code;

        CorrectionFinalizer::applyFromLedgerRow($opId, $fields);
        if (in_array($state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            $this->mirrorUncertain($docId, $opId);
        }

        $originalId = (int) ($attrs['business_reference'] ?? 0);
        return match ($state) {
            ExternalOperation::EXTERNAL_CONFIRMED => $this->summary('confirmed', $state, $originalId, $docId, null, ['document_number' => (string) ($fields['document_number'] ?? $this->docNumber($docId))]),
            ExternalOperation::PROVEN_REJECTED => $this->summary('rejected', $state, $originalId, $docId, (string) $reason),
            ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION => $this->summary('uncertain', $state, $originalId, $docId, (string) ($reason ?? 'TIMEOUT_AFTER_DISPATCH')),
            ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED => $this->summary('not_retryable', $state, $originalId, $docId, 'operation_' . strtolower($state)),
            default => $this->summary('local_error', $state, $originalId, $docId, (string) ($reason ?? 'LOCAL_ERROR')),
        };
    }

    /** Mirror an uncertain outcome on the correction row (guarded: a concurrent reconcile may have won). */
    private function mirrorUncertain(int $docId, int $opId): void
    {
        $fresh = $this->documents->getById($docId);
        if ($fresh === null) {
            return;
        }
        $state = (string) $fresh->state;
        if ($state === ExternalOperation::NOT_STARTED || $state === ExternalOperation::IN_PROGRESS) {
            CorrectionFinalizer::ensureInProgress($this->documents, $docId);
            $this->documents->markUncertain($docId);
        }
        $ledgerRow = $this->ledger->getById($opId);
        if ($ledgerRow !== null && (string) $ledgerRow->state === ExternalOperation::UNCERTAIN) {
            $this->runner->enqueueReconcile($opId);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerAttrs(object $docRow, object $original, string $key, string $fingerprint): array
    {
        return [
            'idempotency_key' => $key,
            'module' => 'billing',
            'provider' => (string) $docRow->provider,
            'kind' => (string) $docRow->doc_role,
            'mode' => (string) $docRow->mode,
            'account_id' => (string) $docRow->account_id,
            'order_id' => (int) $docRow->order_id,
            'order_uuid' => (string) ($docRow->order_uuid ?? ''),
            'owner_type' => 'invoice_document',
            'owner_id' => (int) $docRow->id,
            'currency' => (string) ($docRow->currency ?? ''),
            'amount_minor' => (int) ($docRow->gross_minor ?? 0),
            'business_reference' => (string) ($docRow->storno_of_document_id ?? $original->id ?? ''),
            'request_fingerprint' => $fingerprint !== '' ? $fingerprint : (string) ($docRow->external_ref2 ?? ''),
            'max_attempts' => 3,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replay(object $existing, object $original, string $kind): array
    {
        $state = (string) $existing->state;
        $originalId = (int) $original->id;
        $docId = (int) $existing->id;
        $number = trim((string) ($existing->document_number ?? ''));

        return match ($state) {
            ExternalOperation::EXTERNAL_CONFIRMED => $this->summary('already_confirmed', $state, $originalId, $docId, null, ['document_number' => $number]),
            ExternalOperation::PROVEN_REJECTED => $this->summary('rejected', $state, $originalId, $docId, 'already_rejected'),
            ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION => $this->summary('uncertain', $state, $originalId, $docId, 'reconcile_required'),
            ExternalOperation::NOT_STARTED => $this->summary('in_flight', $state, $originalId, $docId, 'not_started_resume_available'),
            ExternalOperation::IN_PROGRESS => $this->summary('in_flight', $state, $originalId, $docId, 'already_in_flight'),
            default => $this->summary('not_retryable', $state, $originalId, $docId, 'operation_' . strtolower($state)),
        };
    }

    private function findExisting(int $originalDocId, string $kind, string $linkRef): ?object
    {
        foreach ($this->documents->listCorrections($originalDocId, $kind) as $row) {
            if ($kind === self::STORNO) {
                // A document can be stornoed once: any existing storno intent (open, proved or
                // rejected) is THE storno for this original; never create a second one.
                return $row;
            }
            if ((string) ($row->external_ref2 ?? '') === $linkRef) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Documented provider capabilities only (04_SZAMLAZZ §3.3/§5, 04_BILLINGO §3.2):
     *  - Számlázz: invoice storno (`action-szamla_agent_st`), proforma delete (`action-szamla_agent_dijbekero_torlese`);
     *  - Billingo: document cancel (`PUT /documents/{id}/cancel`), draft delete (`DELETE /documents/{id}`);
     *  - corrected/content modification: invoice only, both providers.
     * Anything else is a precise block with a manual path (merchant/provider UI), never a simulated document.
     */
    public static function supportsDocumentType(string $provider, string $kind, string $docType): bool
    {
        if ($kind === self::STORNO) {
            if ($provider === 'szamlazz') {
                return in_array($docType, ['invoice', 'proforma'], true);
            }
            if ($provider === 'billingo') {
                return in_array($docType, ['invoice', 'draft'], true);
            }
            return false;
        }
        if ($kind === self::CORRECTED) {
            return $docType === 'invoice' && in_array($provider, ['szamlazz', 'billingo'], true);
        }
        return false;
    }

    private function linkRef(string $kind, int $originalDocId, array $opts): string
    {
        $explicit = trim((string) ($opts['link_ref'] ?? ''));
        if ($explicit !== '') {
            return substr($explicit, 0, 191);
        }
        $intent = trim((string) ($opts['intent'] ?? ''));
        return $intent !== ''
            ? substr(sprintf('intent:%s:%s:%d', $kind, $intent, $originalDocId), 0, 191)
            : substr(sprintf('manual:%s:%d', $kind, $originalDocId), 0, 191);
    }

    private function fingerprint(int $originalDocId, string $kind, string $linkRef): string
    {
        return sprintf('correction|orig=%d|kind=%s|link=%s', $originalDocId, $kind, $linkRef);
    }

    private function docNumber(int $docId): ?string
    {
        $row = $this->documents->getById($docId);
        $number = trim((string) ($row->document_number ?? ''));
        return $number === '' ? null : $number;
    }

    private function encode(array $data): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        return is_string($json) ? $json : '{}';
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function summary(string $status, string $state, int $originalDocId, int $correctionDocId, ?string $reason = null, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'state' => $state,
            'original_document_id' => $originalDocId,
            'correction_document_id' => $correctionDocId,
            'document_number' => null,
            'reason' => $reason,
        ], $extra);
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
}
