<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Admin\Menu;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * "Számlázás" workspace tab. FluentCart Core has no stable settings-tab addon API for third-party
 * billing integrations (see ADMIN_INTEGRATION_REPORT.md), so the non-secret Számlázz configuration
 * lives here: one authoritative storage (ModuleSettings scope `szamlazz`), the secret agent key stays
 * in CredentialStore (managed on the Settings tab). No provider call happens on this page.
 */
final class SzamlazzAdminScreen
{
    public const ACTION = 'fch_save_szamlazz';
    public const SCOPE = SzamlazzModule::SETTINGS_SCOPE;

    /** Curated, allowlisted non-secret settings (04 SZAMLAZZ). */
    private const FIELDS = [
        'selected_invoicer' => ['type' => 'checkbox', 'label' => 'Ez a plugin állítja ki a bizonylatot', 'value' => 'szamlazz', 'default' => 'szamlazz', 'help' => 'Kikapcsolva a számlázási automatizmus leáll (fail-safe), a beállítások megmaradnak.'],
        'invoice_language' => ['type' => 'select', 'label' => 'Bizonylat nyelve', 'options' => ['hu', 'en', 'de'], 'default' => 'hu'],
        'payment_deadline_days' => ['type' => 'number', 'label' => 'Fizetési határidő (nap)', 'default' => 8],
        'fizmod' => ['type' => 'text', 'label' => 'Fizetési mód a bizonylaton', 'default' => 'Átutalás'],
        'bank_name' => ['type' => 'text', 'label' => 'Bank neve', 'default' => ''],
        'bank_account' => ['type' => 'text', 'label' => 'Bankszámlaszám', 'default' => ''],
        'szamlaszam_elotag' => ['type' => 'text', 'label' => 'Számlaszám előtag', 'default' => ''],
        'email_replyto' => ['type' => 'text', 'label' => 'E-mail válaszcím', 'default' => ''],
        'email_targy' => ['type' => 'text', 'label' => 'E-mail tárgy', 'default' => ''],
        'email_szoveg' => ['type' => 'textarea', 'label' => 'E-mail szöveg', 'default' => ''],
        'note' => ['type' => 'textarea', 'label' => 'Megjegyzés a bizonylaton', 'default' => ''],
        'shipping_label' => ['type' => 'text', 'label' => 'Szállítási tétel neve', 'default' => 'Szállítási költség'],
        'fee_label' => ['type' => 'text', 'label' => 'Kezelési tétel neve', 'default' => 'Kezelési költség'],
        'amount_basis' => ['type' => 'select', 'label' => 'Összeg alapja', 'options' => ['auto', 'net', 'gross'], 'default' => 'auto', 'help' => 'auto: inkluzív árazásnál bruttó, egyébként nettó.'],
        'cod_invoice_event' => ['type' => 'select', 'label' => 'Utánvétes rendelés számlázása', 'options' => ['payment', 'before_dispatch'], 'default' => 'payment', 'help' => 'payment (alapértelmezett): számla a fizetettség igazolása után. before_dispatch: utánvétes rendelésnél a szállítási címke kérésekor, feladás előtt állítja ki a számlát; a rendelés ettől nem lesz fizetett. Előkészített (nem utánvétes) rendelés mindig a fizetettség után számlázódik.'],
        'eszamla' => ['type' => 'checkbox', 'label' => 'e-Számla kérés', 'default' => ''],
        'pdf_download' => ['type' => 'checkbox', 'label' => 'PDF letöltése kiállításkor', 'default' => '1'],
        'send_email' => ['type' => 'checkbox', 'label' => 'Bizonylat küldése e-mailben', 'default' => ''],
        'taxpayer_lookup_enabled' => ['type' => 'checkbox', 'label' => 'Adószám-ellenőrzés (B2B)', 'default' => ''],
        'taxpayer_lookup_required' => ['type' => 'checkbox', 'label' => 'Adószám-ellenőrzés kötelező', 'default' => ''],
    ];

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handleSave']);
    }

    public static function renderContent(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $registry = ModuleRegistry::instance();
        $state = $registry->state(SzamlazzModule::ID);
        $settings = new ModuleSettings();
        $store = new CredentialStore();
        $mode = $settings->mode();
        $account = $settings->accountId('szamlazz');
        $hasKey = $store->has('szamlazz', $mode, $account, 'agent_key');

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Számlázz.hu', 'fluentcart-hungary') . ' <span class="fch-hu-badge fch-hu-badge-' . esc_attr(self::stateKind($state, $registry->isEnabled(SzamlazzModule::ID))) . '">' . esc_html($registry->isEnabled(SzamlazzModule::ID) ? $state : 'modul letiltva') . '</span></h2>';
        echo '<table class="fch-hu-kv"><tbody>';
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Bizonylatkiállító', 'fluentcart-hungary'), SzamlazzModule::isSelectedInvoicer() ? esc_html__('ez a plugin', 'fluentcart-hungary') : esc_html__('külső / kikapcsolva', 'fluentcart-hungary'));
        printf(
            '<tr><th>%s</th><td>%s <a href="%s">%s</a></td></tr>',
            esc_html__('Agent kulcs', 'fluentcart-hungary'),
            $hasKey ? '<span class="fch-hu-badge fch-hu-badge-ok">' . esc_html__('beállítva', 'fluentcart-hungary') . '</span>' : '<span class="fch-hu-badge fch-hu-badge-error">' . esc_html__('hiányzik', 'fluentcart-hungary') . '</span>',
            esc_url(Menu::url(['tab' => 'settings'])),
            esc_html__('Kezelés a Beállítások fülön', 'fluentcart-hungary')
        );
        printf('<tr><th>%s</th><td><code>%s / %s</code></td></tr>', esc_html__('Mód / fiók', 'fluentcart-hungary'), esc_html($mode), esc_html($account));
        echo '</tbody></table>';
        echo '</section>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-card">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        echo '<h2>' . esc_html__('Számlázási beállítások', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('Nem titkos beállítások; a titkok kizárólag a Beállítások fül hitelesítő tárában élnek.', 'fluentcart-hungary') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach (self::FIELDS as $key => $field) {
            $current = $settings->get(self::SCOPE, $key, $field['default'] ?? '');
            printf('<tr><th scope="row"><label for="fch_sz_%1$s">%2$s</label></th><td>', esc_attr($key), esc_html((string) $field['label']));

            switch ($field['type']) {
                case 'checkbox':
                    $checked = $key === 'selected_invoicer'
                        ? (string) $current === (string) $field['value']
                        : ($current === true || $current === '1' || $current === 1);
                    printf(
                        '<input type="checkbox" id="fch_sz_%1$s" name="fch_sz[%1$s]" value="1"%2$s />',
                        esc_attr($key),
                        checked($checked, true, false)
                    );
                    break;

                case 'select':
                    printf('<select id="fch_sz_%s" name="fch_sz[%s]">', esc_attr($key), esc_attr($key));
                    foreach ((array) $field['options'] as $option) {
                        printf('<option value="%s"%s>%s</option>', esc_attr((string) $option), selected((string) $current, (string) $option, false), esc_html((string) $option));
                    }
                    echo '</select>';
                    break;

                case 'number':
                    printf('<input type="number" min="0" id="fch_sz_%1$s" name="fch_sz[%1$s]" value="%2$s" class="small-text" />', esc_attr($key), esc_attr((string) $current));
                    break;

                case 'textarea':
                    printf('<textarea id="fch_sz_%1$s" name="fch_sz[%1$s]" rows="3" class="large-text">%2$s</textarea>', esc_attr($key), esc_textarea((string) $current));
                    break;

                default:
                    printf('<input type="text" id="fch_sz_%1$s" name="fch_sz[%1$s]" value="%2$s" class="regular-text" autocomplete="off" />', esc_attr($key), esc_attr((string) $current));
            }

            if (!empty($field['help'])) {
                printf('<p class="description">%s</p>', esc_html((string) $field['help']));
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button(__('Számlázási beállítások mentése', 'fluentcart-hungary'));
        echo '</form>';
    }

    public function handleSave(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);

        $posted = isset($_POST['fch_sz']) && is_array($_POST['fch_sz']) ? wp_unslash($_POST['fch_sz']) : [];
        $settings = new ModuleSettings();

        foreach (self::FIELDS as $key => $field) {
            switch ($field['type']) {
                case 'checkbox':
                    if ($key === 'selected_invoicer') {
                        $settings->set(self::SCOPE, $key, !empty($posted[$key]) ? (string) $field['value'] : '');
                    } else {
                        $settings->set(self::SCOPE, $key, !empty($posted[$key]) ? '1' : '');
                    }
                    break;

                case 'select':
                    $value = isset($posted[$key]) ? sanitize_key((string) $posted[$key]) : '';
                    $settings->set(self::SCOPE, $key, in_array($value, (array) $field['options'], true) ? $value : (string) ($field['default'] ?? ''));
                    break;

                case 'number':
                    $settings->set(self::SCOPE, $key, max(0, (int) ($posted[$key] ?? 0)));
                    break;

                case 'textarea':
                    $settings->set(self::SCOPE, $key, sanitize_textarea_field((string) ($posted[$key] ?? '')));
                    break;

                default:
                    $settings->set(self::SCOPE, $key, sanitize_text_field((string) ($posted[$key] ?? '')));
            }
        }

        wp_safe_redirect(Menu::url(['tab' => 'billing', 'fch_notice' => 'saved']));
        exit;
    }

    private static function stateKind(string $state, bool $enabled): string
    {
        if (!$enabled) {
            return 'muted';
        }
        return match ($state) {
            ModuleRegistry::STATE_ACTIVE => 'ok',
            ModuleRegistry::STATE_DEGRADED => 'warn',
            ModuleRegistry::STATE_BLOCKED => 'error',
            default => 'muted',
        };
    }
}
