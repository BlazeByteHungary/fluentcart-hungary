<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\Database\Migrator;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Storage\LegacyArtifactMigration;

/**
 * Overview/diagnostics tab (05_OPERATIONS §4.4). Admin-only; never returns credential values.
 */
final class Diagnostics
{
    /** @return array<string,mixed> */
    public static function data(): array
    {
        $registry = ModuleRegistry::instance();
        $ledger = new LedgerRepository();
        $runner = JobRunner::instance();

        $moduleStates = [];
        foreach ($registry->all() as $id => $module) {
            $moduleStates[] = [
                'id' => $id,
                'label' => $module->label(),
                'state' => $registry->state($id),
                'message' => $registry->message($id),
                'enabled' => $registry->isEnabled($id),
            ];
        }

        $selfTest = get_transient('fch_core_selftest');
        $selfTestFresh = is_array($selfTest);
        if (!$selfTestFresh) {
            $selfTest = (new CoreConnectionTester())->run();
        }

        return [
            'plugin_version' => FCH_VERSION,
            'schema' => [
                'installed' => Migrator::installedVersion(),
                'target' => FCH_SCHEMA_VERSION,
                'installed_ok' => Migrator::installedVersion() >= FCH_SCHEMA_VERSION,
            ],
            'compat' => FluentCartCompat::probe(),
            'queue_driver' => $runner->driverName(),
            'queue_forced' => JobRunner::forcedDriver(),
            'ledger_counts' => $ledger->countByState(),
            'unresolved_uncertain' => $ledger->countUnresolved(),
            'jobs_counts' => $runner->store()->countsByState(),
            'artifact_migration' => (new LegacyArtifactMigration())->status(),
            'modules' => $moduleStates,
            'credentials' => (new CredentialStore())->getMasked(),
            'credentials_configured' => CredentialStore::isConfigured(),
            'selftest' => $selfTest,
            'selftest_fresh' => $selfTestFresh,
        ];
    }

    /** Wrapper kept for REST-compatible/legacy callers. */
    public static function render(): void
    {
        echo '<div class="wrap fch-hu">';
        self::renderContent();
        echo '</div>';
    }

    public static function renderContent(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $data = self::data();
        if ($data['selftest_fresh']) {
            delete_transient('fch_core_selftest');
        }

        $unresolved = (int) $data['unresolved_uncertain'];
        echo '<div class="fch-hu-grid fch-hu-grid-2">';

        // Left: status summary.
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Állapot', 'fluentcart-hungary') . '</h2>';
        echo '<table class="fch-hu-kv"><tbody>';
        self::row(__('Plugin verzió', 'fluentcart-hungary'), (string) $data['plugin_version']);
        self::row(
            __('Séma', 'fluentcart-hungary'),
            sprintf('%d / %d', (int) $data['schema']['installed'], (int) $data['schema']['target'])
                . ' ' . self::badge($data['schema']['installed_ok'] ? 'ok' : 'error', $data['schema']['installed_ok'] ? __('naprakész', 'fluentcart-hungary') : __('elmaradt', 'fluentcart-hungary'))
        );
        $compat = $data['compat'];
        self::row(
            __('FluentCart', 'fluentcart-hungary'),
            ($compat['active'] ? __('aktív', 'fluentcart-hungary') : __('nem aktív', 'fluentcart-hungary'))
                . ' ' . esc_html((string) ($compat['version'] ?? ''))
                . ($compat['pro'] ? ' · Pro' : '')
        );
        self::row(__('Háttérsor driver', 'fluentcart-hungary'), esc_html((string) $data['queue_driver'] . ' (override: ' . $data['queue_forced'] . ')'));
        self::row(
            __('Egyeztetésre váró művelet', 'fluentcart-hungary'),
            $unresolved > 0
                ? self::badge('warn', (string) $unresolved) . ' <a href="' . esc_url(Menu::url(['tab' => 'reconciliation'])) . '">' . esc_html__('Ugrás az egyeztetéshez', 'fluentcart-hungary') . '</a>'
                : self::badge('ok', __('nincs', 'fluentcart-hungary'))
        );
        self::row(
            __('Hitelesítő kulcs', 'fluentcart-hungary'),
            $data['credentials_configured']
                ? self::badge('ok', __('beállítva', 'fluentcart-hungary'))
                : self::badge('error', __('hiányzik (fail-closed)', 'fluentcart-hungary'))
        );
        $migration = is_array($data['artifact_migration'] ?? null) ? $data['artifact_migration'] : [];
        $migrationStatus = (string) ($migration['status'] ?? LegacyArtifactMigration::STATUS_UNKNOWN);
        $migrationBadge = match ($migrationStatus) {
            LegacyArtifactMigration::STATUS_DONE => self::badge('ok', __('kész', 'fluentcart-hungary')),
            LegacyArtifactMigration::STATUS_RUNNING => self::badge('warn', __('fut', 'fluentcart-hungary')),
            LegacyArtifactMigration::STATUS_DEGRADED => self::badge('warn', __('részleges', 'fluentcart-hungary')),
            default => self::badge('muted', __('még nem futott', 'fluentcart-hungary')),
        };
        self::row(
            __('Legacy dokumentumvédelem', 'fluentcart-hungary'),
            $migrationBadge . ' ' . esc_html(sprintf(
                /* translators: 1: lifetime sealed file count, 2: failed count, 3: relocation mode */
                __('pecsételve: %1$d · hibás: %2$d · áthelyezés: %3$s', 'fluentcart-hungary'),
                (int) ($migration['sealed_total'] ?? $migration['sealed'] ?? 0),
                (int) ($migration['failed_total'] ?? 0),
                (string) ($migration['relocation'] ?? '—')
            ))
        );
        echo '</tbody></table>';
        echo '</section>';

        // Right: modules.
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Modulok', 'fluentcart-hungary') . '</h2>';
        echo '<table class="widefat striped fch-hu-table"><thead><tr>';
        echo '<th>' . esc_html__('Modul', 'fluentcart-hungary') . '</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Részletek', 'fluentcart-hungary') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($data['modules'] as $module) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($module['label']) . '</strong><br /><code>' . esc_html($module['id']) . '</code></td>';
            echo '<td>' . self::stateBadge((string) $module['state']) . '</td>';
            echo '<td>' . esc_html((string) $module['message']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('A modulok állapota a jogosultság- és hitelesítés-próbák eredménye. A "degraded" állapot nem hiba, hanem hiányzó beállítás.', 'fluentcart-hungary') . '</p>';
        echo '</section>';
        echo '</div>';

        // Self-test.
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Core önteszt', 'fluentcart-hungary') . '</h2>';
        if (!empty($data['selftest']['checks'])) {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>' . esc_html__('Ellenőrzés', 'fluentcart-hungary') . '</th><th>' . esc_html__('Eredmény', 'fluentcart-hungary') . '</th><th>' . esc_html__('Üzenet', 'fluentcart-hungary') . '</th></tr></thead><tbody>';
            foreach ($data['selftest']['checks'] as $check) {
                printf(
                    '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                    esc_html((string) $check['id']),
                    $check['ok'] ? self::badge('ok', 'PASS') : self::badge('error', 'FAIL'),
                    esc_html((string) $check['message'])
                );
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('Nincs önteszt eredmény; futtassa az alábbi gombbal.', 'fluentcart-hungary') . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-actions">';
        wp_nonce_field('fch_run_selftest');
        echo '<input type="hidden" name="action" value="fch_run_selftest" />';
        submit_button(__('Önteszt futtatása', 'fluentcart-hungary'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</section>';

        // Masked credential overview (single authoritative store: CredentialStore).
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Hitelesítő adatok (maszkolva)', 'fluentcart-hungary') . '</h2>';
        if ($data['credentials'] === []) {
            echo '<p>' . esc_html__('Nincs tárolt hitelesítő adat. A titkok kezelése a Beállítások fülön történik.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>' . esc_html__('Szolgáltató', 'fluentcart-hungary') . '</th><th>' . esc_html__('Mód', 'fluentcart-hungary') . '</th><th>' . esc_html__('Fiók', 'fluentcart-hungary') . '</th><th>' . esc_html__('Mező', 'fluentcart-hungary') . '</th><th>' . esc_html__('Érték', 'fluentcart-hungary') . '</th></tr></thead><tbody>';
            foreach ($data['credentials'] as $cred) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td><code>%s</code></td></tr>',
                    esc_html((string) $cred['provider']),
                    esc_html((string) $cred['mode']),
                    esc_html((string) $cred['account']),
                    esc_html((string) $cred['field']),
                    esc_html((string) $cred['preview'])
                );
            }
            echo '</tbody></table>';
        }
        echo '<p><a class="button" href="' . esc_url(Menu::url(['tab' => 'settings'])) . '">' . esc_html__('Titkok kezelése', 'fluentcart-hungary') . '</a></p>';
        echo '</section>';
    }

    public static function rest(\WP_REST_Request $request)
    {
        return new \WP_REST_Response(self::data(), 200);
    }

    public static function restSelfTest(\WP_REST_Request $request)
    {
        return new \WP_REST_Response((new CoreConnectionTester())->run(), 200);
    }

    private static function row(string $label, string $value): void
    {
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($label), $value);
    }

    private static function stateBadge(string $state): string
    {
        $map = [
            ModuleRegistry::STATE_ACTIVE => 'ok',
            ModuleRegistry::STATE_INACTIVE => 'muted',
            ModuleRegistry::STATE_DEGRADED => 'warn',
            ModuleRegistry::STATE_BLOCKED => 'error',
        ];
        return self::badge($map[$state] ?? 'muted', $state);
    }

    private static function badge(string $kind, string $text): string
    {
        return '<span class="fch-hu-badge fch-hu-badge-' . esc_attr($kind) . '">' . esc_html($text) . '</span>';
    }
}
