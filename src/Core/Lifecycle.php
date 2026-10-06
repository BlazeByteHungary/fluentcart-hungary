<?php
declare(strict_types=1);

namespace FluentCartHungary\Core;

use FluentCartHungary\Core\Database\Migrator;

/**
 * Activation / deactivation / cron lifetime (05_DATA_SCHEMA §10.4). Deactivation is never destructive.
 */
final class Lifecycle
{
    public const CRON_HOOK = 'fch_cron_run_due_jobs';
    public const CRON_SCHEDULE = 'fch_minute';

    public static function activate(): void
    {
        Migrator::upgrade();
        self::scheduleCron();
        update_option('fch_plugin_first_activated', time(), false);
    }

    public static function deactivate(): void
    {
        self::clearCron();
    }

    /** @param array<string,array<string,mixed>> $schedules */
    public static function cronSchedules(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 60,
            'display' => __('Every minute (FluentCart Hungary)', 'fluentcart-hungary'),
        ];
        return $schedules;
    }

    public static function scheduleCron(): void
    {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function clearCron(): void
    {
        if (!function_exists('wp_clear_scheduled_hook')) {
            return;
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
