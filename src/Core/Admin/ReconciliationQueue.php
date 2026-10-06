<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;

/**
 * Reconciliation queue (05_ADMIN_AND_CHECKOUT_UX §3; W1.6 item 6): lists UNCERTAIN /
 * AWAITING_RECONCILIATION ledger operations and lets an operator re-run the read-back or resolve
 * the items manually with an audit trail (resolved_by/resolved_note/reconciled_at). Supports
 * single-row quick actions and bulk selection with one shared decision/note.
 *
 * Manual resolution is deliberately ledger-only: provider-side mirrors are reconciled by the module
 * reconciler or operator runbook — the queue never invents provider outcomes.
 */
final class ReconciliationQueue
{
    /** @deprecated legacy page slug; the queue renders as the workspace "reconciliation" tab. */
    public const PAGE_SLUG = 'fluentcart-hungary-reconciliation';
    public const ACTION = 'fch_reconcile_action';
    public const NONCE = 'fch_reconcile_action';
    public const BULK_LIMIT = 200;

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handleAction']);
    }

    /** Legacy wrapper kept for REST-compatible/legacy callers. */
    public static function render(): void
    {
        echo '<div class="wrap fch-admin">';
        self::renderContent();
        echo '</div>';
    }

    public static function renderContent(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $rows = (new LedgerRepository())->findAwaitingReconciliation(50);

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Egyeztetési sor', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Azon külső műveletek, amelyek kimenete még nem bizonyított (UNCERTAIN / AWAITING_RECONCILIATION). Indítható read-back egyeztetés, vagy a tétel manuális lezárása indoklással. A manuális lezárás sosem küldi újra az eredeti kérést.',
            'fluentcart-hungary'
        ) . '</p>';

        if ($rows === []) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Nincs egyeztetésre váró tétel.', 'fluentcart-hungary') . '</p></div>';
            echo '</section>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="fch-reconcile-form">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';

        echo '<table class="widefat striped fch-hu-table" id="fch-reconcile-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" data-fch-select-all="fch_rows" /></td>';
        foreach ([
            __('ID', 'fluentcart-hungary'),
            __('Szolgáltató', 'fluentcart-hungary'),
            __('Típus', 'fluentcart-hungary'),
            __('Rendelés', 'fluentcart-hungary'),
            __('Állapot', 'fluentcart-hungary'),
            __('Ok', 'fluentcart-hungary'),
            __('Frissítve (UTC)', 'fluentcart-hungary'),
            __('Gyorsművelet', 'fluentcart-hungary'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $orderId = (int) ($row->order_id ?? 0);
            echo '<tr>';
            printf('<th scope="row" class="check-column"><input type="checkbox" name="fch_rows[]" value="%d" class="fch-row-check" /></th>', $id);
            echo '<td>' . esc_html((string) $id) . '</td>';
            echo '<td>' . esc_html((string) $row->provider . ' (' . (string) $row->mode . '/' . (string) $row->account_id . ')') . '</td>';
            echo '<td>' . esc_html((string) $row->kind) . '</td>';
            echo '<td>';
            if ($orderId > 0 && class_exists(OrdersScreen::class)) {
                printf(
                    '<a href="%s">#%d</a>',
                    esc_url(Menu::url(['tab' => 'orders', 'order_id' => $orderId])),
                    $orderId
                );
            } else {
                echo esc_html($orderId > 0 ? (string) $orderId : '—');
            }
            echo '</td>';
            echo '<td><code>' . esc_html((string) $row->state) . '</code></td>';
            echo '<td>' . esc_html((string) ($row->uncertain_reason ?? $row->local_error_message ?? '')) . '</td>';
            echo '<td>' . esc_html((string) $row->updated_at) . '</td>';
            echo '<td>';
            printf(
                '<button type="submit" class="button button-small" name="fch_resync_one" value="%d">%s</button>',
                $id,
                esc_html__('Read-back', 'fluentcart-hungary')
            );
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<div class="fch-hu-bulk">';
        echo '<label class="fch-hu-inline"><span>' . esc_html__('Kijelöltekre', 'fluentcart-hungary') . ':</span> ';
        echo '<select name="fch_decision">';
        foreach ([
            'resync' => __('Read-back egyeztetés indítása', 'fluentcart-hungary'),
            'confirmed' => __('Megjelölés megerősítettként', 'fluentcart-hungary'),
            'rejected' => __('Megjelölés elutasítottként', 'fluentcart-hungary'),
            'manual' => __('Manuális lezárás', 'fluentcart-hungary'),
        ] as $decision => $label) {
            printf('<option value="%s">%s</option>', esc_attr($decision), esc_html($label));
        }
        echo '</select></label> ';
        echo '<input type="text" name="fch_note" value="" class="regular-text" placeholder="' . esc_attr__('indoklás (auditnaplóba kerül)', 'fluentcart-hungary') . '" /> ';
        submit_button(__('Alkalmazás a kijelöltekre', 'fluentcart-hungary'), 'primary', 'submit', false, ['data-fch-confirm' => 'reconcile']);
        echo '<p class="description">' . esc_html__('Legfeljebb 200 tétel egyszerre. A megerősített/elutasított/manuális lezárás bejegyzi a felhasználót és az indoklást.', 'fluentcart-hungary') . '</p>';
        echo '</div>';
        echo '</form>';
        echo '</section>';
    }

    public function handleAction(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);

        $decision = isset($_POST['fch_decision']) ? sanitize_key(wp_unslash((string) $_POST['fch_decision'])) : '';
        $note = isset($_POST['fch_note']) ? sanitize_text_field(wp_unslash((string) $_POST['fch_note'])) : '';
        $bulk = isset($_POST['fch_rows']) && is_array($_POST['fch_rows']) ? wp_unslash($_POST['fch_rows']) : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $bulk), static fn(int $id): bool => $id > 0)));

        $single = isset($_POST['fch_resync_one']) ? (int) $_POST['fch_resync_one'] : 0;
        if ($single > 0) {
            $ids = [$single];
            $decision = 'resync';
        }

        if ($ids === []) {
            wp_safe_redirect(Menu::url(['tab' => 'reconciliation', 'fch_notice' => 'no_selection']));
            exit;
        }

        $done = 0;
        $skipped = 0;
        foreach (array_slice($ids, 0, self::BULK_LIMIT) as $id) {
            if ($decision === 'resync') {
                (new OperationRunner())->enqueueReconcile($id) ? $done++ : $skipped++;
                continue;
            }
            if (in_array($decision, ['confirmed', 'rejected', 'manual'], true)) {
                $result = $this->resolve($id, $decision, $note, get_current_user_id());
                $result['ok'] ? $done++ : $skipped++;
                continue;
            }
            $skipped++;
        }

        $notice = $done > 0 ? ($decision === 'resync' ? 'reconcile_done' : 'reconciled') : 'not_found';
        wp_safe_redirect(Menu::url(['tab' => 'reconciliation', 'fch_notice' => $notice]));
        exit;
    }

    /**
     * Manual resolution (audited). Legal outcomes from AWAITING_RECONCILIATION:
     * EXTERNAL_CONFIRMED (confirmed) / PROVEN_REJECTED (rejected) / MANUALLY_RESOLVED (manual).
     * A row still in UNCERTAIN first moves to AWAITING_RECONCILIATION (no skipping).
     *
     * @return array{ok:bool,state:string}
     */
    public function resolve(int $id, string $decision, string $note, int $userId): array
    {
        $map = [
            'confirmed' => ExternalOperation::EXTERNAL_CONFIRMED,
            'rejected' => ExternalOperation::PROVEN_REJECTED,
            'manual' => ExternalOperation::MANUALLY_RESOLVED,
        ];
        if (!isset($map[$decision])) {
            return ['ok' => false, 'state' => 'invalid_decision'];
        }

        $ledger = new LedgerRepository();
        $row = $ledger->getById($id);
        if ($row === null) {
            return ['ok' => false, 'state' => 'not_found'];
        }

        $state = (string) $row->state;
        if ($state === ExternalOperation::UNCERTAIN) {
            $ledger->transition($id, ExternalOperation::AWAITING_RECONCILIATION, [], ExternalOperation::UNCERTAIN);
            $row = $ledger->getById($id) ?? $row;
            $state = (string) $row->state;
        }
        if ($state !== ExternalOperation::AWAITING_RECONCILIATION) {
            return ['ok' => false, 'state' => $state];
        }

        $extra = [
            'resolved_by' => $userId,
            'resolved_note' => $note,
            'reconciled_at' => gmdate('Y-m-d H:i:s'),
        ];
        $ok = $ledger->transition($id, $map[$decision], $extra, ExternalOperation::AWAITING_RECONCILIATION);
        $final = $ledger->getById($id);

        return ['ok' => $ok, 'state' => $final !== null ? (string) $final->state : 'unknown'];
    }
}
