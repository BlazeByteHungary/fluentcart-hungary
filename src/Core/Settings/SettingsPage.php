<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Settings;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Admin\Menu;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\ModuleRegistry;

/**
 * Settings + credentials tab of the "Magyar integrációk" workspace.
 *
 * One authoritative storage per setting: non-secrets live in ModuleSettings/options, secrets only in the
 * encrypted CredentialStore (never in FluentCart's gateway settings, never in a REST response). Credential
 * UX: masked preview, empty field = keep existing, explicit replace, explicit delete.
 */
final class SettingsPage
{
    public const DRIVERS = ['auto', 'action_scheduler', 'db'];

    /** Implemented providers only — unimplemented providers must not appear as working. */
    private const PROVIDERS = [
        'barion' => [
            'label' => 'Barion',
            'module' => 'payments/barion',
            'fields' => [
                'pos_key' => ['label' => 'POS kulcs', 'help' => 'Barion POS key (titkosítva tárolva).'],
            ],
        ],
        'simplepay' => [
            'label' => 'SimplePay (OTP)',
            'module' => 'payments/simplepay',
            'fields' => [
                'merchant_huf' => ['label' => 'Merchant (HUF)', 'help' => 'SimplePay HUF merchant account azonosító (mód+fiók szerint tárolva).'],
                'secret_key_huf' => ['label' => 'Secret key (HUF)', 'help' => 'SimplePay HUF SECRET_KEY (HMAC-SHA384 aláírás).'],
                'merchant_eur' => ['label' => 'Merchant (EUR)', 'help' => 'Opcionális EUR merchant account.'],
                'secret_key_eur' => ['label' => 'Secret key (EUR)', 'help' => 'Opcionális EUR SECRET_KEY.'],
                'merchant_usd' => ['label' => 'Merchant (USD)', 'help' => 'Opcionális USD merchant account.'],
                'secret_key_usd' => ['label' => 'Secret key (USD)', 'help' => 'Opcionális USD SECRET_KEY.'],
            ],
        ],
        'billingo' => [
            'label' => 'Billingo',
            'module' => 'billing/billingo',
            'fields' => [
                'api_key' => ['label' => 'API kulcs (v3)', 'help' => 'Billingo v3 API kulcs írási jogosultsággal; a fiók dönt teszt/éles között.'],
            ],
        ],
        'szamlazz' => [
            'label' => 'Számlázz.hu',
            'module' => 'billing/szamlazz',
            'fields' => [
                'agent_key' => ['label' => 'Agent kulcs', 'help' => 'Számlázz.hu agent kulcs (titkosítva tárolva).'],
            ],
        ],
        'mpl' => [
            'label' => 'MPL (Magyar Posta)',
            'module' => 'shipping/mpl',
            'fields' => [
                'api_key' => ['label' => 'API kulcs', 'help' => 'OAuth2 client_credentials kulcs.'],
                'api_secret' => ['label' => 'API titok', 'help' => 'OAuth2 client_credentials titok.'],
            ],
        ],
        // W2 carriers
        'gls' => [
            'label' => 'GLS (MyGLS)',
            'module' => 'shipping/gls',
            'fields' => [
                'username' => ['label' => 'Felhasználónév', 'help' => 'MyGLS Username (e-mail).'],
                'password' => ['label' => 'Jelszó', 'help' => 'MyGLS jelszó; a kliens SHA-512 lenyomatot küld (titkosítva tárolva).'],
            ],
        ],
        'dpd' => [
            'label' => 'DPD (NST)',
            'module' => 'shipping/dpd',
            'fields' => [
                'token' => ['label' => 'Bearer token', 'help' => 'DPD NST JWT; a kibocsátás módja a szerződés szerint nem dokumentált (G10) — a tokent itt kell megadni.'],
                'bu_code' => ['label' => 'BU kód', 'help' => 'DPD business unit azonosító (opcionális, DPD-BU-ID fejléc).'],
            ],
        ],
        'packeta' => [
            'label' => 'Packeta (Zásilkovna)',
            'module' => 'shipping/packeta',
            'fields' => [
                'api_password' => ['label' => 'API jelszó', 'help' => 'Packeta apiPassword (32 karakter; minden API-hívás első argumentuma).'],
                'api_key' => ['label' => 'API kulcs', 'help' => 'Packeta apiKey (16 karakter; widget és PUDO feed).'],
                'signing_key' => ['label' => 'Signing key', 'help' => 'Push-tracking webhook HMAC kulcs (opcionális).'],
            ],
        ],
        'foxpost' => [
            'label' => 'Foxpost (FoxWeb)',
            'module' => 'shipping/foxpost',
            'fields' => [
                'username' => ['label' => 'Felhasználónév', 'help' => 'FoxWeb HTTP Basic felhasználónév.'],
                'password' => ['label' => 'Jelszó', 'help' => 'FoxWeb HTTP Basic jelszó (titkosítva tárolva).'],
                'api_key' => ['label' => 'API kulcs', 'help' => 'FoxWeb api-key fejléc minden híváshoz.'],
            ],
        ],
    ];

    public function register(): void
    {
        add_action('admin_post_fch_save_settings', [$this, 'handleSave']);
        add_action('admin_post_fch_credential', [$this, 'handleCredential']);
    }

    /** Legacy wrapper. */
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

        $registry = ModuleRegistry::instance();
        $driver = (string) get_option('fch_queue_driver', 'auto');
        $removeData = (bool) get_option('fch_remove_data_on_uninstall', false);
        $mode = (new ModuleSettings())->mode();

        // ---- Operations form -----------------------------------------------------------------------
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-card">';
        wp_nonce_field('fch_save_settings');
        echo '<input type="hidden" name="action" value="fch_save_settings" />';
        echo '<h2>' . esc_html__('Működés', 'fluentcart-hungary') . '</h2>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Működési mód', 'fluentcart-hungary') . '</th><td>';
        foreach (ModuleSettings::MODES as $candidate) {
            printf(
                '<label class="fch-hu-inline"><input type="radio" name="fch_mode" value="%s"%s /> %s</label> ',
                esc_attr($candidate),
                checked($mode, $candidate, false),
                esc_html($candidate === 'live' ? __('Éles', 'fluentcart-hungary') : __('Teszt', 'fluentcart-hungary'))
            );
        }
        echo '<p class="description">' . esc_html__('A teszt és éles műveletek külön sorokban, külön hitelesítő adatokkal futnak; teszt művelet sosem "fejeződik be" éles módban.', 'fluentcart-hungary') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Háttérsor', 'fluentcart-hungary') . '</th><td><select name="fch_queue_driver">';
        foreach (self::DRIVERS as $option) {
            printf('<option value="%s"%s>%s</option>', esc_attr($option), selected($driver, $option, false), esc_html($option));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html(sprintf('Effektív driver: %s', JobRunner::instance()->driverName())) . '</p></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Modulok', 'fluentcart-hungary') . '</h2>';
        echo '<table class="widefat striped fch-hu-table"><tbody>';
        foreach ($registry->all() as $id => $module) {
            $state = $registry->state($id);
            printf(
                '<tr><th scope="row" style="width:320px">%s <code>%s</code></th><td><label class="fch-hu-inline"><input type="checkbox" name="fch_modules[%s]" value="1"%s %s /> %s</label> <span class="fch-hu-badge fch-hu-badge-%s">%s</span></td></tr>',
                esc_html($module->label()),
                esc_html($id),
                esc_attr($id),
                $registry->isEnabled($id) ? ' checked="checked"' : '',
                $id === 'core' ? ' disabled="disabled"' : '',
                esc_html__('engedélyezve', 'fluentcart-hungary'),
                esc_attr(self::stateKind($state)),
                esc_html($state)
            );
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('A core mindig aktív. Egy modul letiltása megőrzi az adatait, csak a hookjait szünteti meg.', 'fluentcart-hungary') . '</p>';

        echo '<h2>' . esc_html__('Eltávolítás', 'fluentcart-hungary') . '</h2>';
        printf(
            '<label class="fch-hu-inline"><input type="checkbox" name="fch_remove_data_on_uninstall" value="1"%s /> %s</label>',
            checked($removeData, true, false),
            esc_html__('A plugin eltávolításakor minden FluentCart Hungary adat törlése (táblák + beállítások). Kikapcsolva az adatok megmaradnak.', 'fluentcart-hungary')
        );

        submit_button(__('Beállítások mentése', 'fluentcart-hungary'));
        echo '</form>';

        // ---- Credentials ---------------------------------------------------------------------------
        self::renderCredentials($mode);
    }

    private static function renderCredentials(string $currentMode): void
    {
        $store = new CredentialStore();
        $masked = $store->getMasked();
        $settings = new ModuleSettings();

        echo '<section class="fch-hu-card fch-hu-credentials">';
        echo '<h2>' . esc_html__('Hitelesítő adatok', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('A titkok kizárólag itt, titkosítva tárolódnak (FCH_CREDENTIALS_KEY). A FluentCart natív fizetési beállításai csak nem titkos megjelenítési adatokat tartalmaznak.', 'fluentcart-hungary') . '</p>';

        if (!CredentialStore::isConfigured()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('FCH_CREDENTIALS_KEY nincs beállítva — a titkok mentése le van tiltva (fail-closed).', 'fluentcart-hungary') . '</p></div>';
        }

        foreach (self::PROVIDERS as $provider => $config) {
            if (!class_exists('\\FluentCartHungary\\Modules\\' . self::moduleClassSuffix($config['module']))) {
                // The module class is the existence proof; providers without it stay hidden entirely.
                continue;
            }
            $account = $settings->accountId($provider);

            echo '<div class="fch-hu-provider">';
            echo '<h3>' . esc_html($config['label']) . ' <code>' . esc_html($config['module']) . '</code> ' . self::moduleBadge($config['module']) . '</h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('fch_credential');
            echo '<input type="hidden" name="action" value="fch_credential" />';
            echo '<input type="hidden" name="fch_provider" value="' . esc_attr($provider) . '" />';

            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th scope="row">' . esc_html__('Mód', 'fluentcart-hungary') . '</th><td><select name="fch_cred_mode">';
            foreach (ModuleSettings::MODES as $m) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($m),
                    selected($currentMode, $m, false),
                    esc_html($m === 'live' ? __('Éles', 'fluentcart-hungary') : __('Teszt', 'fluentcart-hungary'))
                );
            }
            echo '</select> <span class="description">' . esc_html__('a titkok mód+fiók szerint elkülönítve tárolódnak', 'fluentcart-hungary') . '</span></td></tr>';
            echo '<tr><th scope="row"><label for="fch_account_' . esc_attr($provider) . '">' . esc_html__('Fiók-azonosító (nem titkos)', 'fluentcart-hungary') . '</label></th><td>';
            printf('<input type="text" id="fch_account_%s" name="fch_account" value="%s" class="regular-text" autocomplete="off" />', esc_attr($provider), esc_attr($account));
            echo '</td></tr>';

            foreach ($config['fields'] as $field => $meta) {
                $preview = '—';
                foreach ($masked as $cred) {
                    if ($cred['provider'] === $provider && $cred['mode'] === $currentMode && $cred['account'] === $account && $cred['field'] === $field) {
                        $preview = (string) $cred['preview'];
                        break;
                    }
                }
                printf('<tr><th scope="row"><label for="fch_value_%1$s_%2$s">%3$s</label></th><td>', esc_attr($provider), esc_attr($field), esc_html($meta['label']));
                printf('<code class="fch-hu-secret">%s</code><br />', esc_html($preview));
                printf(
                    '<input type="password" id="fch_value_%1$s_%2$s" name="fch_value[%2$s]" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s" /> ',
                    esc_attr($provider),
                    esc_attr($field),
                    esc_attr__('új érték (üresen hagyva a meglévő megmarad)', 'fluentcart-hungary')
                );
                printf(
                    '<button type="submit" class="button" name="fch_do" value="delete:%1$s" data-fch-confirm="delete">%2$s</button>',
                    esc_attr($field),
                    esc_html__('Törlés', 'fluentcart-hungary')
                );
                printf('<p class="description">%s</p>', esc_html((string) $meta['help']));
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            printf(
                '<p class="fch-hu-actions"><button type="submit" class="button button-primary" name="fch_do" value="save">%s</button></p>',
                esc_html__('Mentés', 'fluentcart-hungary')
            );
            echo '</form>';
            echo '</div>';
        }
        echo '</section>';
    }

    // ---- Handlers ----------------------------------------------------------------------------------

    public function handleSave(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer('fch_save_settings');

        $registry = ModuleRegistry::instance();
        $posted = isset($_POST['fch_modules']) && is_array($_POST['fch_modules']) ? wp_unslash($_POST['fch_modules']) : [];
        foreach ($registry->ids() as $id) {
            if ($id === 'core') {
                continue;
            }
            $registry->setEnabled($id, isset($posted[$id]) && (string) $posted[$id] === '1');
        }

        $mode = isset($_POST['fch_mode']) ? sanitize_key(wp_unslash((string) $_POST['fch_mode'])) : '';
        if (in_array($mode, ModuleSettings::MODES, true)) {
            (new ModuleSettings())->setMode($mode);
        }

        $driver = isset($_POST['fch_queue_driver']) ? sanitize_key(wp_unslash((string) $_POST['fch_queue_driver'])) : 'auto';
        if (!in_array($driver, self::DRIVERS, true)) {
            $driver = 'auto';
        }
        update_option('fch_queue_driver', $driver, false);
        update_option('fch_remove_data_on_uninstall', !empty($_POST['fch_remove_data_on_uninstall']), false);

        wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => 'saved']));
        exit;
    }

    public function handleCredential(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer('fch_credential');

        $provider = isset($_POST['fch_provider']) ? sanitize_key(wp_unslash((string) $_POST['fch_provider'])) : '';
        $mode = isset($_POST['fch_cred_mode']) ? sanitize_key(wp_unslash((string) $_POST['fch_cred_mode'])) : '';
        $account = isset($_POST['fch_account']) ? sanitize_text_field(wp_unslash((string) $_POST['fch_account'])) : '';
        $do = isset($_POST['fch_do']) ? sanitize_text_field(wp_unslash((string) $_POST['fch_do'])) : '';

        if (!isset(self::PROVIDERS[$provider]) || !in_array($mode, ModuleSettings::MODES, true)) {
            wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => 'invalid']));
            exit;
        }
        if ($account === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $account)) {
            wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => 'invalid']));
            exit;
        }

        $settings = new ModuleSettings();
        $settings->set($provider, 'account_id', $account);

        $store = new CredentialStore();

        if (str_starts_with($do, 'delete:')) {
            $field = sanitize_key(substr($do, 7));
            if (!isset(self::PROVIDERS[$provider]['fields'][$field])) {
                wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => 'invalid']));
                exit;
            }
            $deleted = $store->delete($provider, $mode, $account, $field);
            wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => $deleted ? 'secret_deleted' : 'not_found']));
            exit;
        }

        $values = isset($_POST['fch_value']) && is_array($_POST['fch_value']) ? wp_unslash($_POST['fch_value']) : [];
        $savedAny = false;
        $preserved = false;
        foreach (self::PROVIDERS[$provider]['fields'] as $field => $meta) {
            $value = isset($values[$field]) ? (string) $values[$field] : '';
            if ($value === '') {
                if ($store->has($provider, $mode, $account, $field)) {
                    $preserved = true;
                }
                continue;
            }
            if (strlen($value) > 512) {
                wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => 'invalid']));
                exit;
            }
            if (!$store->set($provider, $mode, $account, $field, $value, true)) {
                wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => 'invalid']));
                exit;
            }
            $savedAny = true;
        }

        $notice = $savedAny ? 'secret_saved' : ($preserved ? 'secret_preserved' : 'credit_missing');
        wp_safe_redirect(Menu::url(['tab' => 'settings', 'fch_notice' => $notice]));
        exit;
    }

    private static function moduleBadge(string $moduleId): string
    {
        $registry = ModuleRegistry::instance();
        $state = $registry->state($moduleId);
        $enabled = $registry->isEnabled($moduleId);
        $kind = !$enabled ? 'muted' : self::stateKind($state);
        $label = !$enabled ? 'modul letiltva' : $state;
        return '<span class="fch-hu-badge fch-hu-badge-' . esc_attr($kind) . '">' . esc_html($label) . '</span>';
    }

    private static function stateKind(string $state): string
    {
        return match ($state) {
            ModuleRegistry::STATE_ACTIVE => 'ok',
            ModuleRegistry::STATE_DEGRADED => 'warn',
            ModuleRegistry::STATE_BLOCKED => 'error',
            default => 'muted',
        };
    }

    private static function moduleClassSuffix(string $moduleId): string
    {
        $map = [
            'payments/barion' => 'Payments\\Barion\\BarionModule',
            'payments/simplepay' => 'Payments\\Simplepay\\SimplepayModule',
            'billing/szamlazz' => 'Billing\\Szamlazz\\SzamlazzModule',
            'billing/billingo' => 'Billing\\Billingo\\BillingoModule',
            'shipping/mpl' => 'Shipping\\Mpl\\MplModule',
            'shipping/gls' => 'Shipping\\Gls\\GlsModule',
            'shipping/dpd' => 'Shipping\\Dpd\\DpdModule',
            'shipping/packeta' => 'Shipping\\Packeta\\PacketaModule',
            'shipping/foxpost' => 'Shipping\\Foxpost\\FoxpostModule',
            'shipping/pickuppoints' => 'Shipping\\PickupPoints\\PickupPointsModule',
            'shipping' => 'Shipping\\CoreShipping\\ShippingCoreModule',
        ];
        return $map[$moduleId] ?? '';
    }
}
