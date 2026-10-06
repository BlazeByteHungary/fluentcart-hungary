<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Core;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Billing\RefundCorrectionLinker;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\ReconcileUncertainJob;
use FluentCartHungary\Core\Jobs\SealLegacyArtifactsJob;
use FluentCartHungary\Core\Ledger\ReconcileSweeper;
use FluentCartHungary\Core\ModuleInterface;
use FluentCartHungary\Core\Settings\SettingsPage;
use FluentCartHungary\Core\Shipping\CancelShipmentJob;
use FluentCartHungary\Core\Storage\LegacyArtifactMigration;

/**
 * The always-on core module: admin shell, settings and queue runtime wiring.
 */
final class CoreModule implements ModuleInterface
{
    public function id(): string
    {
        return 'core';
    }

    public function label(): string
    {
        return __('Core', 'fluentcart-hungary');
    }

    public function requires(): array
    {
        return [];
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function registerHooks(): void
    {
        JobRunner::instance()->registerHandler(new ReconcileUncertainJob());
        JobRunner::instance()->registerHandler(new SealLegacyArtifactsJob());
        JobRunner::instance()->registerHandler(new CancelShipmentJob());

        add_action('fch_run_job', static function ($jobId): void {
            JobRunner::instance()->executeById((int) $jobId);
        }, 10, 1);

        // Upgrade-time protection for legacy plaintext artifacts: schedule the batched seal pass on the
        // first admin request and on every cron tick until it completes (never on a document download).
        $scheduleArtifactMigration = static function (): void {
            (new LegacyArtifactMigration())->maybeSchedule();
        };
        add_action('admin_init', $scheduleArtifactMigration);

        add_action('fch_cron_run_due_jobs', static function (): void {
            (new ReconcileSweeper())->sweep(5);
            JobRunner::instance()->releaseExpired();
            JobRunner::instance()->runDue(20);
            (new LegacyArtifactMigration())->maybeSchedule();
        });

        // W4 linked lifecycle: a confirmed refund consults the merchant's correction policy and, when
        // enabled for a FULL refund, stornoes the active invoice through the shared correction service.
        add_action(RefundCorrectionLinker::HOOK, [new RefundCorrectionLinker(), 'onRefundConfirmed'], 10, 1);

        (new AdminShell())->register();
        (new SettingsPage())->register();
    }

    public function probe(): array
    {
        return ['ok' => true, 'message' => 'core ready'];
    }
}
