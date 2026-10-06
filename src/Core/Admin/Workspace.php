<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Settings\SettingsPage;

/**
 * "Magyar integrációk" workspace: one page under FluentCart with tabs. Tab renderers are plain PHP
 * screens of this plugin; screens share the scoped `.fch-hu` style layer and nonce-protected
 * `admin_post` handlers. No SPA internals are touched.
 */
final class Workspace
{
    public static function render(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $tabs = self::tabs();
        $requested = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : '';
        $current = isset($tabs[$requested]) ? $requested : (string) array_key_first($tabs);

        echo '<div class="wrap fch-hu">';
        printf(
            '<h1 class="fch-hu-title">%s <span class="fch-hu-version">v%s</span></h1>',
            esc_html__('Magyar integrációk', 'fluentcart-hungary'),
            esc_html(FCH_VERSION)
        );

        self::renderNotices();

        echo '<nav class="nav-tab-wrapper fch-hu-tabs">';
        foreach ($tabs as $id => $tab) {
            printf(
                '<a class="nav-tab%s" href="%s">%s</a>',
                $current === $id ? ' nav-tab-active' : '',
                esc_url(Menu::url(['tab' => $id])),
                esc_html($tab['label'])
            );
        }
        echo '</nav>';

        echo '<div class="fch-hu-body fch-hu-tab-' . esc_attr($current) . '">';
        ($tabs[$current]['render'])();
        echo '</div></div>';
    }

    /** @return array<string,array{label:string,render:callable}> */
    public static function tabs(): array
    {
        $tabs = [
            'overview' => [
                'label' => __('Áttekintés', 'fluentcart-hungary'),
                'render' => [Diagnostics::class, 'renderContent'],
            ],
            'settings' => [
                'label' => __('Beállítások', 'fluentcart-hungary'),
                'render' => [SettingsPage::class, 'renderContent'],
            ],
        ];

        $conditional = [
            'billing' => [
                'label' => __('Számlázás', 'fluentcart-hungary'),
                'render' => [\FluentCartHungary\Modules\Billing\BillingAdminScreen::class, 'renderWorkspace'],
            ],
            'shipping' => [
                'label' => __('Szállítás', 'fluentcart-hungary'),
                'render' => [\FluentCartHungary\Modules\Shipping\CoreShipping\ShippingAdminScreen::class, 'renderContent'],
            ],
            'reconciliation' => [
                'label' => __('Egyeztetés', 'fluentcart-hungary'),
                'render' => [ReconciliationQueue::class, 'renderContent'],
            ],
            'orders' => [
                'label' => __('Rendelések', 'fluentcart-hungary'),
                'render' => [OrdersScreen::class, 'renderContent'],
            ],
        ];
        foreach ($conditional as $id => $tab) {
            if (class_exists($tab['render'][0])) {
                $tabs[$id] = $tab;
            }
        }

        return $tabs;
    }

    /** Query-arg based notices (PRG pattern after admin_post actions). */
    public static function renderNotices(): void
    {
        $code = isset($_GET['fch_notice']) ? sanitize_key(wp_unslash((string) $_GET['fch_notice'])) : '';
        if ($code === '') {
            return;
        }
        $map = [
            'saved' => ['success', __('A beállítások mentve.', 'fluentcart-hungary')],
            'secret_saved' => ['success', __('A titok elmentve (titkosítva).', 'fluentcart-hungary')],
            'secret_preserved' => ['info', __('Üres mező: a meglévő érték megmaradt.', 'fluentcart-hungary')],
            'secret_deleted' => ['success', __('A tárolt érték törölve.', 'fluentcart-hungary')],
            'credit_missing' => ['error', __('A művelethez megadott érték szükséges.', 'fluentcart-hungary')],
            'invalid' => ['error', __('Érvénytelen adat; a művelet nem futott le.', 'fluentcart-hungary')],
            'queued' => ['success', __('A művelet a háttérsorba került; az eredmény az Egyeztetés fülön követhető.', 'fluentcart-hungary')],
            'reconcile_done' => ['success', __('Read-back egyeztetés elindítva.', 'fluentcart-hungary')],
            'reconciled' => ['success', __('Az egyeztetés megtörtént.', 'fluentcart-hungary')],
            'not_found' => ['error', __('A keresett rendelés vagy művelet nem található.', 'fluentcart-hungary')],
            'document_missing' => ['warning', __('A kért dokumentum nem érhető el (nincs tárolt fájl, vagy a művelet még nem fejeződött be).', 'fluentcart-hungary')],
            'queue_failed' => ['error', __('A háttérművelet nem került sorba állításra. Próbálja újra.', 'fluentcart-hungary')],
            'no_selection' => ['warning', __('Nem jelölt ki elemet.', 'fluentcart-hungary')],
            'selftest_run' => ['info', __('A core önteszt lefutott.', 'fluentcart-hungary')],
            'refund_done' => ['success', __('A visszatérítés megerősítést nyert a szolgáltatónál.', 'fluentcart-hungary')],
            'refund_uncertain' => ['warning', __('A szolgáltató válasza bizonytalan: a visszatérítés egyeztetés alatt áll, az összeg foglalt marad. Indítson read-back egyeztetést vagy döntsön kézzel.', 'fluentcart-hungary')],
            'refund_rejected' => ['error', __('A visszatérítés nem valósult meg.', 'fluentcart-hungary')],
            'refund_failed' => ['error', __('A visszatérítés indítása nem sikerült.', 'fluentcart-hungary')],
            'refund_unsupported' => ['warning', __('Ehhez a fizetési módhoz nincs gateway-visszatérítés; használja a kézi rögzítést.', 'fluentcart-hungary')],
            'refund_manual_recorded' => ['success', __('A kézi visszatérítés rögzítve (FluentCart + ledger).', 'fluentcart-hungary')],
            'correction_confirmed' => ['success', __('A korrekciós bizonylat megerősítést nyert a szolgáltatónál; az eredeti bizonylat SUPERSEDED állapotba került, a PDF letölthető.', 'fluentcart-hungary')],
            'correction_uncertain' => ['warning', __('A korrekció eredménye bizonytalan vagy folyamatban van: a rendszer nem indít új hívást, az egyeztetés (read-back) vagy a „Folytatás" gomb dönt. Az eredeti bizonylat addig érintetlen.', 'fluentcart-hungary')],
            'correction_rejected' => ['error', __('A szolgáltató a korrekciót elutasította vagy a dokumentum már nem korrigálható; az eredeti bizonylat változatlan maradt.', 'fluentcart-hungary')],
            'correction_local_error' => ['warning', __('A korrekció nem indult el (pl. hiányzó fiók-kulcs vagy a tételsnapshot hiánya). A javítás után a „Folytatás" gombbal ugyanaz a szándék folytatható — az eredeti bizonylat érintetlen.', 'fluentcart-hungary')],
            'correction_blocked' => ['warning', __('A korrekció ezen a bizonylattípuson nem támogatott ebben a verzióban. Kézi feloldás: a szolgáltatói felületen módosítsa/sztornózza a bizonylatot, vagy állítson ki új bizonylatot — az eredeti és annak PDF-je megmarad.', 'fluentcart-hungary')],
            'shipment_cancelled' => ['success', __('A küldemény visszavonása a szolgáltatónál bizonyítottan megtörtént; a szállítmánysor SUPERSEDED, a címkefájl (audit) megmaradt.', 'fluentcart-hungary')],
            'shipment_cancelled_local' => ['success', __('A még el nem indított címekérés helyben visszavonva — a szolgáltatót nem hívtuk, külső csomag nem jött létre.', 'fluentcart-hungary')],
            'shipment_cancel_deferred' => ['warning', __('A címekérés eredménye még nem bizonyított (folyamatban/bizonytalan): a visszavonás ütemezve, a tényleges eredményből folytatódik. Amíg nem zárul le, ne indítson kézzel újat.', 'fluentcart-hungary')],
            'shipment_cancel_uncertain' => ['warning', __('A visszavonás eredménye bizonytalan: a rendszer nem indít új hívást, a read-back vagy a kézi lezárás dönt. A csomag addig létezőnek tekintendő.', 'fluentcart-hungary')],
            'shipment_cancel_rejected' => ['error', __('A szolgáltató a visszavonást elutasította (pl. már feladott csomag). A küldemény érintetlen; visszaküldés (claim) vagy szolgáltatói ügyintézés szükséges.', 'fluentcart-hungary')],
            'shipment_cancel_blocked' => ['warning', __('Ennél a szállítónál nincs dokumentált külső visszavonási út ebben a verzióban (a címkefájl törlése nem azonos a feladás visszavonásával). Kézi feloldás a szolgáltatói felületen.', 'fluentcart-hungary')],
            'claim_confirmed' => ['success', __('A visszaküldés (claim) létrejött a Packeta felé; a 9 jegyű jelszó a privát tárolóban, a „Claim jelszó letöltése" gombbal érhető el.', 'fluentcart-hungary')],
            'claim_uncertain' => ['warning', __('A visszaküldés eredménye bizonytalan, és a Packeta nem ad dokumentált visszaolvasást: ellenőrizze a szolgáltatói felületen, majd használja a „Kézi lezárás" műveletet. A rendszer nem küldi el újra a kérést.', 'fluentcart-hungary')],
            'claim_rejected' => ['error', __('A Packeta a visszaküldést elutasította; a csomag és a címke érintetlen.', 'fluentcart-hungary')],
            'claim_local_error' => ['warning', __('A visszaküldés nem indult el (pl. hiányzó fiók-kulcs vagy jelszó-tárolási hiba). A hiba javítása után ugyanaz a szándék folytatható.', 'fluentcart-hungary')],
            'claim_blocked' => ['warning', __('A visszaküldés ezen a szállítmányon nem indítható (nincs megerősített csomag, vagy hiányos vevőadat). Ellenőrizze a csomagot és a vevő elérhetőségét.', 'fluentcart-hungary')],
            'manual_resolved' => ['success', __('A művelet kézileg lezárva (auditált megjegyzéssel). Külső újraküldés nem történt; a döntés a szolgáltatónál ellenőrzött tényen alapuljon.', 'fluentcart-hungary')],
            'carrier_assigned' => ['success', __('A rendelés futárkötése rögzítve (rendelésszintű pillanatkép). Automatikus találgatás nem történt.', 'fluentcart-hungary')],
            'routing_saved' => ['success', __('A legacy-default futár-policy mentve.', 'fluentcart-hungary')],
            'routing_invalid' => ['error', __('Érvénytelen futár-policy vagy rendelés; a művelet nem futott le.', 'fluentcart-hungary')],
        ];
        if (!isset($map[$code])) {
            return;
        }
        [$type, $message] = $map[$code];
        $detail = '';
        if (isset($_GET['fch_detail'])) {
            $decoded = base64_decode((string) wp_unslash((string) $_GET['fch_detail']), true);
            if (is_string($decoded) && $decoded !== '') {
                $detail = sanitize_text_field($decoded);
            }
        }
        printf(
            '<div class="notice notice-%s is-dismissible fch-hu-notice"><p>%s</p>%s</div>',
            esc_attr($type),
            esc_html($message),
            $detail !== '' ? '<p><small>' . esc_html($detail) . '</small></p>' : ''
        );
    }
}
