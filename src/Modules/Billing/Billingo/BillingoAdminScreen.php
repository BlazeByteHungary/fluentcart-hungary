<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Admin\Menu;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * "Számlázás" workspace — Billingo non-secret configuration (one authoritative storage: ModuleSettings
 * scope `billingo`). The API key stays in the encrypted CredentialStore (Settings tab). The shared
 * provider selector, the document browser and the recovery actions live in BillingAdminScreen.
 * No provider call happens while rendering this page.
 */
final class BillingoAdminScreen
{
    public const ACTION = 'fch_save_billingo';
    public const SCOPE = BillingoModule::SETTINGS_SCOPE;

    /** Curated, allowlisted non-secret settings (04_BILLINGO_CONTRACT.md §4). */
    private const FIELDS = [
        'block_id' => ['type' => 'number', 'label' => 'Bizonylattömb (block_id)', 'default' => 0, 'help' => 'A Billingo fiók bizonylattömb azonosítója; dokumentum kiállításához kötelező.'],
        'bank_account_id' => ['type' => 'number', 'label' => 'Bankszámla (bank_account_id)', 'default' => 0, 'help' => '0 = a fiók alapértelmezett bankszámlája.'],
        'partner_id' => ['type' => 'number', 'label' => 'Rögzített partner (partner_id)', 'default' => 0, 'help' => '0 = automatikus find-or-create.'],
        'partner_mode' => ['type' => 'select', 'label' => 'Partner kezelés', 'options' => ['find_or_create', 'create'], 'default' => 'find_or_create'],
        'payment_method_default' => ['type' => 'select', 'label' => 'Fizetési mód (alap)', 'options' => ['elore_utalas', 'bankcard', 'cash', 'cash_on_delivery', 'online_bankcard', 'barion', 'paypal', 'other'], 'default' => 'elore_utalas'],
        'payment_method_offline_payment' => ['type' => 'text', 'label' => 'Fizetési mód – utánvét', 'default' => 'cash_on_delivery'],
        'payment_method_barion' => ['type' => 'text', 'label' => 'Fizetési mód – Barion', 'default' => 'barion'],
        'payment_method_simplepay' => ['type' => 'text', 'label' => 'Fizetési mód – SimplePay', 'default' => 'online_bankcard'],
        'invoice_language' => ['type' => 'select', 'label' => 'Bizonylat nyelve', 'options' => ['hu', 'en', 'de'], 'default' => 'hu'],
        'payment_deadline_days' => ['type' => 'number', 'label' => 'Fizetési határidő (nap)', 'default' => 8],
        'amount_basis' => ['type' => 'select', 'label' => 'Összeg alapja', 'options' => ['auto', 'net', 'gross'], 'default' => 'auto', 'help' => 'auto: inkluzív árazásnál bruttó, egyébként nettó.'],
        'vat_override' => ['type' => 'text', 'label' => 'ÁFA kulcs felülírás', 'default' => '', 'help' => 'Üresen hagyva a rendelési tétel ÁFA kulcsa érvényes.'],
        'entitlement' => ['type' => 'text', 'label' => 'ÁFA jogcím (entitlement)', 'default' => '', 'help' => 'Pl. AAM, EUFADE; csak akkor kerül a payloadba, ha ki van töltve.'],
        'fee_vat_override' => ['type' => 'text', 'label' => 'Díjtétel ÁFA felülírás', 'default' => ''],
        'fee_entitlement' => ['type' => 'text', 'label' => 'Díjtétel ÁFA jogcím', 'default' => ''],
        'round' => ['type' => 'select', 'label' => 'Kerekítés', 'options' => ['', 'five', 'none', 'one', 'ten'], 'default' => ''],
        'conversion_rate' => ['type' => 'text', 'label' => 'Devizaárfolyam (EUR/USD)', 'default' => '', 'help' => 'Külföldi pénznemhez kötelező; üres értéknél a kiállítás megtagadva (nincs csendes 1.0 fallback).'],
        'conversion_rate_eur' => ['type' => 'text', 'label' => 'EUR árfolyam', 'default' => ''],
        'note' => ['type' => 'textarea', 'label' => 'Megjegyzés a bizonylaton', 'default' => ''],
        'shipping_label' => ['type' => 'text', 'label' => 'Szállítási tétel neve', 'default' => 'Szállítási költség'],
        'fee_label' => ['type' => 'text', 'label' => 'Kezelési tétel neve', 'default' => 'Kezelési költség'],
        'eszamla' => ['type' => 'checkbox', 'label' => 'e-Számla kérés', 'default' => ''],
        'pdf_download' => ['type' => 'checkbox', 'label' => 'PDF letöltése kiállításkor', 'default' => '1'],
        'taxpayer_lookup_enabled' => ['type' => 'checkbox', 'label' => 'Adószám-ellenőrzés (B2B, fail-open)', 'default' => ''],
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
        $state = $registry->state(BillingoModule::ID);
        $settings = new ModuleSettings();
        $store = new CredentialStore();
        $mode = $settings->mode();
        $account = $settings->accountId(self::SCOPE);
        $hasKey = $store->has(self::SCOPE, $mode, $account, 'api_key');

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Billingo', 'fluentcart-hungary') . ' <span class="fch-hu-badge fch-hu-badge-' . esc_attr(self::stateKind($state, $registry->isEnabled(BillingoModule::ID))) . '">' . esc_html($registry->isEnabled(BillingoModule::ID) ? $state : 'modul letiltva') . '</span></h2>';
        echo '<table class="fch-hu-kv"><tbody>';
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('API kulcs', 'fluentcart-hungary'), $hasKey ? '<span class="fch-hu-badge fch-hu-badge-ok">' . esc_html__('beállítva', 'fluentcart-hungary') . '</span>' : '<span class="fch-hu-badge fch-hu-badge-error">' . esc_html__('hiányzik', 'fluentcart-hungary') . '</span>');
        printf('<tr><th>%s</th><td><code>%s / %s</code> · %s</td></tr>', esc_html__('Mód / fiók', 'fluentcart-hungary'), esc_html($mode), esc_html($account), esc_html__('egyetlen API host; a kulcs fiókja dönt teszt/éles között', 'fluentcart-hungary'));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Bizonylattömb', 'fluentcart-hungary'), ((int) $settings->get(self::SCOPE, 'block_id', 0)) > 0 ? esc_html__('beállítva', 'fluentcart-hungary') : '<span class="fch-hu-badge fch-hu-badge-error">' . esc_html__('hiányzik', 'fluentcart-hungary') . '</span>');
        echo '</tbody></table>';
        echo '</section>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-card">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        echo '<h2>' . esc_html__('Billingo beállítások', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('Nem titkos beállítások; az API kulcs kizárólag a Beállítások fül hitelesítő tárában él.', 'fluentcart-hungary') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach (self::FIELDS as $key => $field) {
            $current = $settings->get(self::SCOPE, $key, $field['default'] ?? '');
            printf('<tr><th scope="row"><label for="fch_bi_%1$s">%2$s</label></th><td>', esc_attr($key), esc_html((string) $field['label']));
            switch ($field['type']) {
                case 'checkbox':
                    printf('<input type="checkbox" id="fch_bi_%1$s" name="fch_bi[%1$s]" value="1"%2$s />', esc_attr($key), checked($current === true || $current === '1' || $current === 1, true, false));
                    break;
                case 'select':
                    printf('<select id="fch_bi_%s" name="fch_bi[%s]">', esc_attr($key), esc_attr($key));
                    foreach ((array) $field['options'] as $option) {
                        printf('<option value="%s"%s>%s</option>', esc_attr((string) $option), selected((string) $current, (string) $option, false), esc_html($option === '' ? '—' : (string) $option));
                    }
                    echo '</select>';
                    break;
                case 'number':
                    printf('<input type="number" min="0" id="fch_bi_%1$s" name="fch_bi[%1$s]" value="%2$s" class="small-text" />', esc_attr($key), esc_attr((string) $current));
                    break;
                case 'textarea':
                    printf('<textarea id="fch_bi_%1$s" name="fch_bi[%1$s]" rows="3" class="large-text">%2$s</textarea>', esc_attr($key), esc_textarea((string) $current));
                    break;
                default:
                    printf('<input type="text" id="fch_bi_%1$s" name="fch_bi[%1$s]" value="%2$s" class="regular-text" autocomplete="off" />', esc_attr($key), esc_attr((string) $current));
            }
            if (!empty($field['help'])) {
                printf('<p class="description">%s</p>', esc_html((string) $field['help']));
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        submit_button(__('Billingo beállítások mentése', 'fluentcart-hungary'));
        echo '</form>';
    }

    public function handleSave(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);

        $posted = isset($_POST['fch_bi']) && is_array($_POST['fch_bi']) ? wp_unslash($_POST['fch_bi']) : [];
        $settings = new ModuleSettings();
        foreach (self::FIELDS as $key => $field) {
            switch ($field['type']) {
                case 'checkbox':
                    $settings->set(self::SCOPE, $key, !empty($posted[$key]) ? '1' : '');
                    break;
                case 'select':
                    $value = isset($posted[$key]) ? sanitize_key((string) $posted[$key]) : '';
                    $settings->set(self::SCOPE, $key, in_array($value, array_map('strval', (array) $field['options']), true) ? $value : (string) ($field['default'] ?? ''));
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

        wp_safe_redirect(Menu::url(['tab' => 'billing', 'fch_notice' => 'billingo_saved']));
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
