<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Jobs;

use FluentCartHungary\Core\Storage\LegacyArtifactMigration;

/**
 * Batched, chained execution of the legacy plaintext artifact migration (hook `fch_seal_legacy_artifacts`).
 *
 * One run seals up to N files within a small time budget; when candidates remain it enqueues the next
 * batch under its own key (`fch:artifact_migration:{n}`), so a slow filesystem can never stall the queue
 * and an interrupted chain resumes from the persisted state on the next boot (`maybeSchedule()`).
 */
final class SealLegacyArtifactsJob implements JobInterface
{
    public const HOOK = 'fch_seal_legacy_artifacts';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $maxFiles = (int) ($args['max_files'] ?? LegacyArtifactMigration::BATCH_FILES);
        $budget = (float) ($args['budget'] ?? LegacyArtifactMigration::BATCH_SECONDS);

        $migration = new LegacyArtifactMigration();
        $state = $migration->runBatch($maxFiles, $budget);

        if ((string) ($state['status'] ?? '') === LegacyArtifactMigration::STATUS_RUNNING) {
            $next = (int) ($state['batches'] ?? 0) + 1;
            JobRunner::instance()->enqueue(new JobSpec(
                'fch:artifact_migration:' . $next,
                self::HOOK,
                ['max_files' => $maxFiles, 'budget' => $budget],
                10,
                3,
                'core'
            ));
        }
    }
}
