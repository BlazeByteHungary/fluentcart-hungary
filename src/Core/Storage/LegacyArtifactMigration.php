<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Storage;

/**
 * Upgrade-time protection for legacy plaintext artifacts (W1 closeout item 1).
 *
 * Earlier releases wrote PDFs/labels as plaintext into `{uploads}/fch-private` and only re-sealed a file
 * when an authorized download happened to read it. This migration removes that dependency: on the first
 * request after upgrade it schedules a batched, resumable seal pass, and — when a private root outside
 * the uploads tree is configured — first relocates the WHOLE legacy tree with one atomic directory rename,
 * so still-unprocessed plaintext files are already unreachable over HTTP while the batches run.
 *
 * Safety: every file is sealed with an atomic tmp+rename and verified by read-back (original restored on
 * mismatch); a failed/unreadable file is reported per file and never deleted; a missing key reports
 * `degraded` and leaves bytes untouched (relocation, when configured, still applies).
 */
final class LegacyArtifactMigration
{
    public const OPTION = 'fch_artifact_migration';

    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_DEGRADED = 'degraded';

    public const BATCH_FILES = 50;

    public const BATCH_SECONDS = 2.0;

    public const MAX_REPORTED_FAILURES = 50;

    /** @return array<string,mixed> */
    public function status(): array
    {
        if (!function_exists('get_option')) {
            return ['status' => self::STATUS_UNKNOWN, 'batches' => 0];
        }
        $raw = get_option(self::OPTION, null);
        if (!is_string($raw) || $raw === '') {
            return ['status' => self::STATUS_UNKNOWN, 'batches' => 0];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => self::STATUS_UNKNOWN, 'batches' => 0];
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION, (string) wp_json_encode($state), false);
        }
    }

    /**
     * Schedule the migration job when it has never run or is still incomplete. Safe to call on every boot:
     * it only reads one option and re-enqueues through the rerunnable path (crash recovery).
     */
    public function maybeSchedule(): bool
    {
        $state = $this->status();
        $status = (string) ($state['status'] ?? self::STATUS_UNKNOWN);
        if (in_array($status, [self::STATUS_DONE, self::STATUS_DEGRADED], true)) {
            return false;
        }
        if (!class_exists(\FluentCartHungary\Core\Jobs\JobRunner::class)
            || !class_exists(\FluentCartHungary\Core\Jobs\JobSpec::class)) {
            return false;
        }

        if ($status === self::STATUS_UNKNOWN) {
            $state = [
                'status' => self::STATUS_RUNNING,
                'batches' => 0,
                'sealed' => 0,
                'relocated' => 0,
                'failed_total' => 0,
                'failed' => [],
                // Lifetime counters survive rescans so the audit trail is not lost on a re-run.
                'sealed_total' => (int) ($state['sealed_total'] ?? 0),
                'relocation' => null,
                'target_root' => $this->targetRootSafe(),
                'started_at' => gmdate('Y-m-d H:i:s'),
            ];
            $this->save($state);
        }

        $batch = (int) ($state['batches'] ?? 0) + 1;
        return (bool) \FluentCartHungary\Core\Jobs\JobRunner::instance()->enqueueRerunnable(
            new \FluentCartHungary\Core\Jobs\JobSpec(
                'fch:artifact_migration:' . $batch,
                \FluentCartHungary\Core\Jobs\SealLegacyArtifactsJob::HOOK,
                ['max_files' => self::BATCH_FILES, 'budget' => self::BATCH_SECONDS],
                10,
                3,
                'core'
            )
        );
    }

    /**
     * Run one batch and persist the accumulated state. Returns the fresh state.
     *
     * @return array<string,mixed>
     */
    public function runBatch(int $maxFiles = self::BATCH_FILES, float $budget = self::BATCH_SECONDS): array
    {
        $state = $this->status();
        if (!in_array((string) ($state['status'] ?? ''), [self::STATUS_RUNNING, self::STATUS_UNKNOWN], true)) {
            return $state;
        }

        $engine = new ArtifactSealEngine(PrivateStorage::legacyRoot(), PrivateStorage::root());
        $batch = $engine->runBatch($maxFiles, $budget);

        $failures = (array) ($state['failed'] ?? []);
        foreach ($batch['failed'] as $failure) {
            $failures[] = $failure;
        }
        $failures = array_slice($failures, -self::MAX_REPORTED_FAILURES);

        $state['status'] = $batch['done']
            ? ($batch['failed'] === [] ? self::STATUS_DONE : self::STATUS_DEGRADED)
            : self::STATUS_RUNNING;
        $state['batches'] = (int) ($state['batches'] ?? 0) + 1;
        $state['sealed'] = (int) ($state['sealed'] ?? 0) + (int) $batch['sealed'];
        $state['sealed_total'] = (int) ($state['sealed_total'] ?? 0) + (int) $batch['sealed'];
        $state['relocated'] = $batch['mode'] === 'tree_rename' ? 1 : (int) ($state['relocated'] ?? 0);
        $state['relocation'] = $batch['mode'];
        $state['failed_total'] = (int) ($state['failed_total'] ?? 0) + count($batch['failed']);
        $state['failed'] = $failures;
        $state['target_root'] = $this->targetRootSafe();
        $state['last_batch'] = $batch;
        $state['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($batch['done']) {
            $state['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        $this->save($state);

        return $state;
    }

    /** Force a fresh scan (operator/diagnostic re-run); keeps counters for the report. */
    public function rescan(): void
    {
        $state = $this->status();
        $state['status'] = self::STATUS_UNKNOWN;
        $this->save($state);
        $this->maybeSchedule();
    }

    private function targetRootSafe(): string
    {
        try {
            return PrivateStorage::root();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
