<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;

/**
 * The single guarded flow every outbound provider operation goes through (05_DATA_SCHEMA §7.1, §8;
 * invariants §1.3/§1.4):
 *
 *   claim ledger row (idempotency_key) -> atomic NOT_STARTED=>IN_PROGRESS lease -> dispatch ->
 *     success  => EXTERNAL_CONFIRMED   (external ids + redacted payloads)
 *     rejected => PROVEN_REJECTED
 *     timeout  => UNCERTAIN + reconcile job enqueued (never auto create-retry)
 *     local    => stays NOT_STARTED with local_error + next_retry_at (caller decides re-enqueue)
 *
 * Providers supply only the dispatch callable; they cannot bypass the ledger.
 */
final class OperationRunner
{
    private LedgerRepository $ledger;

    private JobRunner $jobs;

    public function __construct(?LedgerRepository $ledger = null, ?JobRunner $jobs = null)
    {
        $this->ledger = $ledger ?? new LedgerRepository();
        $this->jobs = $jobs ?? JobRunner::instance();
    }

    /**
     * Get-or-create the ledger row for an intended operation (no dispatch).
     *
     * @return array{created:bool,row:object}
     */
    public function claim(array $attrs): array
    {
        return $this->ledger->claim($attrs);
    }

    /**
     * @param array<string,mixed> $attrs ledger fields (see LedgerRepository::claim)
     * @param callable(object):ProviderCallResult $dispatch receives the claimed ledger row
     * @return array{state:string,row:object,result:?ProviderCallResult,created:bool,dispatched:bool}
     */
    public function run(array $attrs, callable $dispatch): array
    {
        $claim = $this->ledger->claim($attrs);
        $row = $claim['row'];

        $state = (string) $row->state;
        if (ExternalOperation::isTerminal($state) || in_array($state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            return ['state' => $state, 'row' => $row, 'result' => null, 'created' => $claim['created'], 'dispatched' => false];
        }

        if (!$this->isDue($row)) {
            return ['state' => $state, 'row' => $row, 'result' => null, 'created' => $claim['created'], 'dispatched' => false];
        }

        $won = $this->ledger->claimForWork((int) $row->id, $this->workerId(), $this->leaseTtl());
        $row = $this->ledger->getById((int) $row->id) ?? $row;
        if (!$won) {
            return ['state' => (string) $row->state, 'row' => $row, 'result' => null, 'created' => $claim['created'], 'dispatched' => false];
        }

        try {
            $result = $dispatch($row);
        } catch (\Throwable $e) {
            $result = ProviderCallResult::localError('EXCEPTION', $e->getMessage());
        }
        if (!$result instanceof ProviderCallResult) {
            $result = ProviderCallResult::localError('BAD_DISPATCHER', 'dispatch() must return ProviderCallResult.');
        }

        $payload = [
            'request_payload_redacted' => $this->encodePayload($result->requestPayload),
            'response_payload_redacted' => $this->encodePayload($result->responsePayload),
        ];

        switch ($result->status) {
            case ProviderCallResult::SUCCESS:
                $fields = array_merge($result->fields, $payload);
                $this->ledger->transition((int) $row->id, ExternalOperation::EXTERNAL_CONFIRMED, $fields, ExternalOperation::IN_PROGRESS);
                break;

            case ProviderCallResult::REJECTED:
                $fields = array_merge($payload, [
                    'local_error_code' => (string) $result->code,
                    'local_error_message' => (string) $result->message,
                ]);
                $this->ledger->transition((int) $row->id, ExternalOperation::PROVEN_REJECTED, $fields, ExternalOperation::IN_PROGRESS);
                break;

            case ProviderCallResult::UNCERTAIN:
                $fields = array_merge($payload, [
                    'uncertain_reason' => (string) ($result->code ?: 'TIMEOUT_AFTER_DISPATCH'),
                ]);
                $this->ledger->transition((int) $row->id, ExternalOperation::UNCERTAIN, $fields, ExternalOperation::IN_PROGRESS);
                $this->enqueueReconcile((int) $row->id);
                break;

            case ProviderCallResult::LOCAL_ERROR:
            default:
                $fields = array_merge($payload, [
                    'local_error_code' => (string) $result->code,
                    'local_error_message' => (string) $result->message,
                    'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $this->localRetryDelay((int) $row->attempt_count)),
                ]);
                $this->ledger->transition((int) $row->id, ExternalOperation::NOT_STARTED, $fields, ExternalOperation::IN_PROGRESS);
                break;
        }

        $final = $this->ledger->getById((int) $row->id) ?? $row;
        return [
            'state' => (string) $final->state,
            'row' => $final,
            'result' => $result,
            'created' => $claim['created'],
            'dispatched' => true,
        ];
    }

    /**
     * Enqueue the read-back reconcile job. The stable key deduplicates while the job is queued or running;
     * once a previous run is terminal an operator-triggered re-run is allowed under an attempt-scoped key
     * (otherwise a second "Re-run read-back" click could never work after the first completion).
     */
    public function enqueueReconcile(int $ledgerId): bool
    {
        $key = 'fch:reconcile:' . $ledgerId;
        $existing = $this->jobs->store()->findByKey($key);
        if ($existing !== null) {
            $state = strtoupper((string) $existing->state);
            if (!in_array($state, ['PENDING', 'RESERVED', 'RUNNING'], true)) {
                // Previous run is terminal: allow an operator-triggered re-run under an attempt-scoped key.
                $key .= ':r' . gmdate('YmdHis');
            }
            // Still queued/running: the driver dedupes but keeps a pump scheduled for a PENDING row.
        }
        return $this->jobs->enqueue(new JobSpec(
            $key,
            ReconcileUncertainJob::HOOK,
            ['operation_id' => $ledgerId],
            5,
            3,
            'core'
        ));
    }

    public function workerId(): string
    {
        $host = function_exists('gethostname') ? (string) gethostname() : 'cli';
        return substr($host . ':' . getmypid() . ':op', 0, 64);
    }

    /**
     * Operator resolution for an operation whose outcome cannot be proven by the provider read-back
     * (e.g. a Packeta claim has no lookup). Moves UNCERTAIN -> AWAITING (mirror-legal) -> MANUALLY_RESOLVED
     * and records the operator's note; the external identity and every prior artifact stay untouched.
     */
    public function resolveManually(int $ledgerId, string $note, ?int $userId = null): bool
    {
        $row = $this->ledger->getById($ledgerId);
        if ($row === null) {
            return false;
        }
        $state = (string) $row->state;
        if ($state === ExternalOperation::UNCERTAIN) {
            if (!$this->ledger->transition($ledgerId, ExternalOperation::AWAITING_RECONCILIATION, [], ExternalOperation::UNCERTAIN)) {
                return false;
            }
            $state = ExternalOperation::AWAITING_RECONCILIATION;
        }
        if ($state !== ExternalOperation::AWAITING_RECONCILIATION) {
            return false;
        }
        return $this->ledger->transition($ledgerId, ExternalOperation::MANUALLY_RESOLVED, [
            'resolved_note' => substr($note, 0, 500),
            'resolved_by' => (int) ($userId ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)),
        ], ExternalOperation::AWAITING_RECONCILIATION);
    }

    public function leaseTtl(): int
    {
        return (int) apply_filters('fch/operation_lease_ttl', 300);
    }

    private function isDue(object $row): bool
    {
        $next = $row->next_retry_at ?? null;
        if ($next === null || $next === '') {
            return true;
        }
        $ts = strtotime((string) $next . ' UTC');
        return $ts === false || $ts <= time();
    }

    private function localRetryDelay(int $attempts): int
    {
        return class_exists('\FluentCartHungary\Core\Jobs\JobStore')
            ? \FluentCartHungary\Core\Jobs\JobStore::backoffDelay(max(1, $attempts))
            : 60;
    }

    private function encodePayload(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }
        $redacted = function_exists('fch_redact_payload') ? fch_redact_payload($payload) : $payload;
        $json = function_exists('wp_json_encode') ? wp_json_encode($redacted) : json_encode($redacted);
        return substr((string) $json, 0, 8000);
    }
}
