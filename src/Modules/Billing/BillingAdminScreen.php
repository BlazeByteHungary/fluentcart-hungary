<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing;

use FluentCartHungary\Core\Admin\AdminShell;
use FluentCartHungary\Core\Admin\Menu;
use FluentCartHungary\Core\Billing\CorrectionDispatcherRegistry;
use FluentCartHungary\Core\Billing\CorrectionPolicy;
use FluentCartHungary\Core\Billing\InvoiceCorrectionService;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Billing\Billingo\BillingoCorrectionService;
use FluentCartHungary\Modules\Billing\Billingo\BillingoDocumentStore;
use FluentCartHungary\Modules\Billing\Billingo\BillingoInvoiceService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzCorrectionService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzDocumentStore;

/**
 * Shared invoicing workspace (W3): the authoritative provider selector, the cross-provider document
 * browser (old Számlázz documents stay readable/downloadable after switching to Billingo), manual
 * issuance for any supported document type, and the recovery actions (reconcile / retry PDF / status /
 * provider-side email). Every action is capability-checked (AdminShell::userCanAccess) + nonce-checked.
 */
final class BillingAdminScreen
{
    public const ACTION_SAVE = 'fch_billing_save';
    public const ACTION_DOWNLOAD = 'fch_billing_download';
    public const ACTION_RECONCILE = 'fch_billing_reconcile';
    public const ACTION_ISSUE = 'fch_billing_issue';
    public const ACTION_RETRY_PDF = 'fch_billing_retry_pdf';
    public const ACTION_SEND_EMAIL = 'fch_billing_send_email';
    public const ACTION_REFRESH_STATUS = 'fch_billing_refresh_status';
    public const ACTION_CORRECT = 'fch_billing_correct';
    public const ACTION_CORRECTION_RESUME = 'fch_billing_correction_resume';

    public function register(): void
    {
        foreach ([
            self::ACTION_SAVE, self::ACTION_DOWNLOAD, self::ACTION_RECONCILE, self::ACTION_ISSUE,
            self::ACTION_RETRY_PDF, self::ACTION_SEND_EMAIL, self::ACTION_REFRESH_STATUS,
            self::ACTION_CORRECT, self::ACTION_CORRECTION_RESUME,
        ] as $action) {
            add_action('admin_post_' . $action, [$this, 'handle']);
        }
    }

    /** Billing workspace composite: shared selector/browser + both provider screens. */
    public static function renderWorkspace(): void
    {
        self::renderContent();
        if (class_exists('\\FluentCartHungary\\Modules\\Billing\\Billingo\\BillingoAdminScreen')) {
            \FluentCartHungary\Modules\Billing\Billingo\BillingoAdminScreen::renderContent();
        }
        if (class_exists('\\FluentCartHungary\\Modules\\Billing\\Szamlazz\\SzamlazzAdminScreen')) {
            \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzAdminScreen::renderContent();
        }
    }

    public static function renderContent(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $settings = new ModuleSettings();
        $selected = InvoicerSelection::selectedProvider();
        $codEvent = InvoicerSelection::codInvoiceEvent();

        // ---- provider selector ----
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Számlázó választó', 'fluentcart-hungary') . '</h2>';
        echo '<p class="description">' . esc_html__('Egy normál számlázási szándékot pontosan egy szolgáltató kap. A váltás nem irányítja át a meglévő (függő vagy UNCERTAIN) bizonylatokat, és nem számlázza újra a már számlázott rendelést. Amíg a közös választót nem mentik, a Számlázz beállítás érvényes.', 'fluentcart-hungary') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION_SAVE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="fch_billing_invoicer">' . esc_html__('Bizonylatkiállító', 'fluentcart-hungary') . '</label></th><td>';
        printf('<select id="fch_billing_invoicer" name="selected_invoicer">');
        foreach (['szamlazz' => 'Számlázz.hu', 'billingo' => 'Billingo', 'off' => __('Ki (fail-safe)', 'fluentcart-hungary')] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($selected, $value, false), esc_html($label));
        }
        echo '</select>';
        printf('<p class="description">%s</p>', esc_html__('„Ki" esetén az automatizmus leáll, a beállítások megmaradnak.', 'fluentcart-hungary'));
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="fch_billing_cod_event">' . esc_html__('Utánvétes rendelés számlázása', 'fluentcart-hungary') . '</label></th><td>';
        printf('<select id="fch_billing_cod_event" name="cod_invoice_event">');
        foreach (['payment' => __('Fizetettség után (payment)', 'fluentcart-hungary'), 'before_dispatch' => __('Feladás előtt (before_dispatch)', 'fluentcart-hungary')] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($codEvent, $value, false), esc_html($label));
        }
        echo '</select>';
        printf('<p class="description">%s</p>', esc_html__('A kiállítás önmagában nem teszi fizetetté a rendelést; a „before_dispatch" a címkekérésből indul, feladás előtt.', 'fluentcart-hungary'));
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="fch_billing_refund_policy">' . esc_html__('Visszatérítés → számlakorrekció', 'fluentcart-hungary') . '</label></th><td>';
        printf('<select id="fch_billing_refund_policy" name="refund_correction_policy">');
        foreach ([
            CorrectionPolicy::OFF => __('Ki (kézi döntés, alapérték)', 'fluentcart-hungary'),
            CorrectionPolicy::STORNO_ON_FULL_REFUND => __('Teljes visszatérítés után automatikus sztornó', 'fluentcart-hungary'),
        ] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected(InvoicerSelection::refundCorrectionPolicy(), $value, false), esc_html($label));
        }
        echo '</select>';
        printf('<p class="description">%s</p>', esc_html__('Részleges visszatérítés soha nem indít automatikus teljes sztornót. A helyesbítő bizonylat mindig kézi, indokolt művelet; a pénzvisszatérítés és a bizonylatkorrekció külön, egymást nem ismétlő lépések.', 'fluentcart-hungary'));
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Választás mentése', 'fluentcart-hungary'));
        echo '</form>';
        echo '</section>';

        // ---- document browser ----
        $orderId = isset($_GET['fch_order_id']) ? (int) $_GET['fch_order_id'] : 0;
        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Bizonylatok', 'fluentcart-hungary') . '</h2>';
        echo '<form method="get" action="' . esc_url(Menu::url(['tab' => 'billing'])) . '" style="margin-bottom:8px">';
        echo '<input type="hidden" name="page" value="' . esc_attr((string) ($_GET['page'] ?? '')) . '" />';
        echo '<input type="hidden" name="tab" value="billing" />';
        printf('<input type="number" name="fch_order_id" value="%s" min="1" class="small-text" placeholder="%s" /> ', esc_attr((string) ($orderId ?: '')), esc_attr__('rendelés ID', 'fluentcart-hungary'));
        submit_button(__('Keresés', 'fluentcart-hungary'), 'secondary', 'submit', false);
        echo '</form>';

        if ($orderId > 0) {
            $rows = (new InvoiceDocumentRepository())->listForOrder($orderId, 50);
            if ($rows === []) {
                printf('<p>%s</p>', esc_html__('Nincs bizonylat ehhez a rendeléshez.', 'fluentcart-hungary'));
            } else {
                echo '<table class="widefat striped"><thead><tr>';
                foreach ([__('#', 'fluentcart-hungary'), __('Szolgáltató', 'fluentcart-hungary'), __('Típus', 'fluentcart-hungary'), __('Állapot', 'fluentcart-hungary'), __('Bizonylatszám', 'fluentcart-hungary'), __('Bruttó', 'fluentcart-hungary'), __('Kapcsolat', 'fluentcart-hungary'), __('PDF', 'fluentcart-hungary'), __('Műveletek', 'fluentcart-hungary')] as $th) {
                    echo '<th>' . esc_html($th) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $docId = (int) $row->id;
                    $provider = (string) $row->provider;
                    $state = (string) $row->state;
                    $docRole = (string) ($row->doc_role ?? '');
                    $docType = strtolower(trim((string) ($row->provider_doc_type ?? '')));
                    $isCorrection = in_array($docRole, InvoiceCorrectionService::KINDS, true);
                    echo '<tr>';
                    echo '<td>' . $docId . '</td>';
                    echo '<td>' . esc_html($provider) . '</td>';
                    echo '<td>' . esc_html((string) ($row->provider_doc_type ?? '')) . '</td>';
                    echo '<td>' . esc_html($state) . '</td>';
                    echo '<td>' . esc_html((string) ($row->document_number ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($row->gross_minor ?? '') . ' ' . (string) ($row->currency ?? '')) . '</td>';
                    $parentId = (int) ($row->storno_of_document_id ?? 0);
                    echo '<td>' . ($parentId > 0 ? '→ #' . $parentId : '—') . '</td>';
                    echo '<td>';
                    if (!empty($row->pdf_ref)) {
                        echo self::actionLink(self::ACTION_DOWNLOAD, $docId, __('Letöltés', 'fluentcart-hungary'));
                    } else {
                        echo '—';
                    }
                    echo '</td><td>';
                    if (in_array($state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                        echo self::actionLink(self::ACTION_RECONCILE, $docId, __('Egyeztetés (read-back)', 'fluentcart-hungary')) . ' ';
                    }
                    if ($provider === 'billingo' && !$isCorrection && $state === ExternalOperation::EXTERNAL_CONFIRMED && empty($row->pdf_ref)) {
                        echo self::actionLink(self::ACTION_RETRY_PDF, $docId, __('PDF újrapróba', 'fluentcart-hungary')) . ' ';
                    }
                    if ($isCorrection && $state === ExternalOperation::EXTERNAL_CONFIRMED && empty($row->pdf_ref)) {
                        echo self::actionLink(self::ACTION_RETRY_PDF, $docId, __('PDF újrapróba', 'fluentcart-hungary')) . ' ';
                    }
                    if ($isCorrection && $state === ExternalOperation::NOT_STARTED) {
                        echo self::actionLink(self::ACTION_CORRECTION_RESUME, $docId, __('Folytatás', 'fluentcart-hungary')) . ' ';
                    }
                    if ($provider === 'billingo' && !$isCorrection && $state === ExternalOperation::EXTERNAL_CONFIRMED) {
                        echo self::actionLink(self::ACTION_REFRESH_STATUS, $docId, __('Státusz frissítés', 'fluentcart-hungary')) . ' ';
                    }

                    // W4: correction actions on a confirmed document, in the context of the original.
                    $canStorno = $state === ExternalOperation::EXTERNAL_CONFIRMED
                        && CorrectionDispatcherRegistry::has($provider)
                        && InvoiceCorrectionService::supportsDocumentType($provider, InvoiceCorrectionService::STORNO, $docType);
                    $canCorrect = $state === ExternalOperation::EXTERNAL_CONFIRMED
                        && $parentId === 0
                        && CorrectionDispatcherRegistry::has($provider)
                        && InvoiceCorrectionService::supportsDocumentType($provider, InvoiceCorrectionService::CORRECTED, $docType);
                    if ($canStorno || $canCorrect) {
                        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">';
                        wp_nonce_field(self::ACTION_CORRECT);
                        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CORRECT) . '" />';
                        echo '<input type="hidden" name="document_id" value="' . esc_attr((string) $docId) . '" />';
                        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $row->order_id) . '" />';
                        echo '<select name="kind">';
                        if ($canStorno) {
                            printf('<option value="%s">%s</option>', esc_attr(InvoiceCorrectionService::STORNO), esc_html__('Sztornó', 'fluentcart-hungary'));
                        }
                        if ($canCorrect) {
                            printf('<option value="%s">%s</option>', esc_attr(InvoiceCorrectionService::CORRECTED), esc_html__('Helyesbítő', 'fluentcart-hungary'));
                        }
                        echo '</select>';
                        printf('<input type="text" name="reason" placeholder="%s" style="max-width:150px" /> ', esc_attr__('indok', 'fluentcart-hungary'));
                        echo '<button type="submit" class="button button-small">' . esc_html__('Korrekció', 'fluentcart-hungary') . '</button>';
                        echo '</form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }

            // ---- manual issuance ----
            echo '<h3>' . esc_html__('Kézi kiállítás', 'fluentcart-hungary') . '</h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::ACTION_ISSUE);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_ISSUE) . '" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $orderId) . '" />';
            echo '<select name="provider">';
            foreach (['billingo' => 'Billingo', 'szamlazz' => 'Számlázz.hu'] as $value => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($selected, $value, false), esc_html($label));
            }
            echo '</select> ';
            echo '<select name="doc_type">';
            foreach (['invoice' => 'invoice', 'proforma' => 'proforma', 'advance' => 'advance (előleg)', 'draft' => 'draft', 'receipt' => 'receipt'] as $value => $label) {
                printf('<option value="%s">%s</option>', esc_attr($value), esc_html($label));
            }
            echo '</select> ';
            submit_button(__('Kiállítás', 'fluentcart-hungary'), 'secondary', 'submit', false);
            echo '</form>';
            printf('<p class="description">%s</p>', esc_html__('A kézi kiállítás is a single-invoicer guard alatt fut: a normál számlát csak a kötött/választott szolgáltató állíthatja ki; a PDF-hiba nem indít új kiállítást.', 'fluentcart-hungary'));

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
            wp_nonce_field(self::ACTION_SEND_EMAIL);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SEND_EMAIL) . '" />';
            printf('<input type="number" name="document_id" min="1" placeholder="%s" class="small-text" /> ', esc_attr__('dokumentum ID', 'fluentcart-hungary'));
            printf('<input type="text" name="emails" placeholder="%s" class="regular-text" /> ', esc_attr__('e-mail(ek), vesszővel', 'fluentcart-hungary'));
            submit_button(__('Billingo e-mail küldés', 'fluentcart-hungary'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';
    }

    /** All admin-post operations funnel here (nonce + capability checked per action). */
    public function handle(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        $action = isset($_POST['action']) ? sanitize_key((string) $_POST['action']) : (isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '');
        check_admin_referer($action);

        switch ($action) {
            case self::ACTION_SAVE:
                $this->saveSelection();
                return;
            case self::ACTION_DOWNLOAD:
                $this->download();
                return;
            case self::ACTION_RECONCILE:
                $this->reconcile();
                return;
            case self::ACTION_ISSUE:
                $this->issue();
                return;
            case self::ACTION_RETRY_PDF:
                $this->retryPdf();
                return;
            case self::ACTION_SEND_EMAIL:
                $this->sendEmail();
                return;
            case self::ACTION_REFRESH_STATUS:
                $this->refreshStatus();
                return;
            case self::ACTION_CORRECT:
                $this->correct();
                return;
            case self::ACTION_CORRECTION_RESUME:
                $this->resumeCorrection();
                return;
        }
        wp_die(esc_html__('Ismeretlen művelet.', 'fluentcart-hungary'), '', ['response' => 400]);
    }

    private function saveSelection(): void
    {
        $selected = isset($_POST['selected_invoicer']) ? sanitize_key((string) $_POST['selected_invoicer']) : '';
        $selected = in_array($selected, array_merge(InvoicerSelection::providers(), [InvoicerSelection::SELECTION_OFF]), true)
            ? $selected
            : InvoicerSelection::SELECTION_OFF;
        $cod = isset($_POST['cod_invoice_event']) ? sanitize_key((string) $_POST['cod_invoice_event']) : InvoicerSelection::INVOICE_ON_PAYMENT;
        if (!in_array($cod, [InvoicerSelection::INVOICE_ON_PAYMENT, InvoicerSelection::INVOICE_ON_BEFORE_DISPATCH], true)) {
            $cod = InvoicerSelection::INVOICE_ON_PAYMENT;
        }
        $settings = new ModuleSettings();
        $settings->set(InvoicerSelection::SHARED_SCOPE, 'selected_invoicer', $selected);
        $settings->set(InvoicerSelection::SHARED_SCOPE, 'cod_invoice_event', $cod);
        $policy = CorrectionPolicy::normalize((string) ($_POST['refund_correction_policy'] ?? CorrectionPolicy::OFF));
        $settings->set(InvoicerSelection::SHARED_SCOPE, 'refund_correction_policy', $policy);
        $this->redirect('selection_saved');
    }

    private function download(): void
    {
        $docId = isset($_GET['document_id']) ? (int) $_GET['document_id'] : 0;
        $row = (new InvoiceDocumentRepository())->getById($docId);
        if ($row === null || empty($row->pdf_ref)) {
            $this->redirect('document_missing');
        }
        $provider = (string) $row->provider;
        $pdf = $provider === 'billingo'
            ? (new BillingoDocumentStore())->read((string) $row->pdf_ref)
            : (new SzamlazzDocumentStore())->read((string) $row->pdf_ref);
        if ($pdf === null || $pdf === '') {
            $this->redirect('pdf_missing');
        }
        $number = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($row->document_number ?? (string) $docId));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $provider . '-invoice-' . $number . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    private function reconcile(): void
    {
        $docId = isset($_REQUEST['document_id']) ? (int) $_REQUEST['document_id'] : 0;
        $row = (new InvoiceDocumentRepository())->getById($docId);
        if ($row === null || (int) ($row->operation_id ?? 0) <= 0) {
            $this->redirect('reconcile_unavailable');
        }
        (new OperationRunner())->enqueueReconcile((int) $row->operation_id);
        $this->redirect('reconcile_queued');
    }

    private function issue(): void
    {
        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $provider = isset($_POST['provider']) ? sanitize_key((string) $_POST['provider']) : '';
        $docType = isset($_POST['doc_type']) ? sanitize_key((string) $_POST['doc_type']) : 'invoice';
        if ($orderId <= 0 || !in_array($provider, InvoicerSelection::providers(), true)) {
            $this->redirect('issue_invalid');
        }
        $settings = new ModuleSettings();
        $role = $docType === 'invoice' ? 'invoice' : $docType;
        $sequence = (new InvoiceDocumentRepository())->nextSequence(
            $orderId,
            $role,
            $provider,
            $settings->mode(),
            $settings->accountId($provider)
        );
        if ($provider === 'billingo') {
            $result = (new BillingoInvoiceService())->issue($orderId, $sequence, $docType);
        } else {
            $result = (new \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService())->issue($orderId, $sequence);
        }
        $this->redirect('issue_' . (string) ($result['status'] ?? 'unknown'));
    }

    private function retryPdf(): void
    {
        $docId = isset($_REQUEST['document_id']) ? (int) $_REQUEST['document_id'] : 0;
        $row = (new InvoiceDocumentRepository())->getById($docId);
        $isCorrection = $row !== null && in_array((string) ($row->doc_role ?? ''), InvoiceCorrectionService::KINDS, true);
        if ($isCorrection && (string) $row->provider === 'szamlazz') {
            $result = (new SzamlazzCorrectionService())->retryPdf($docId);
        } elseif ($isCorrection) {
            $result = (new BillingoCorrectionService())->retryPdf($docId);
        } else {
            $result = (new BillingoInvoiceService())->retryPdf($docId);
        }
        $this->redirect('pdf_' . (string) ($result['status'] ?? 'unknown'));
    }

    /**
     * W4: request a correction of a confirmed document. The shared service enforces the original
     * binding, the documented-capability fence, the per-intent idempotency and the read-back recovery;
     * the admin path only relays the merchant's reason/kind and maps the outcome to a notice.
     */
    private function correct(): void
    {
        $docId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
        $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
        $reason = sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')));
        $document = (new InvoiceDocumentRepository())->getById($docId);
        if ($document === null || $docId <= 0) {
            $this->redirect('correction_blocked', isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0);
        }
        $result = (new InvoiceCorrectionService())->request($docId, $kind, [
            'reason' => $reason,
            'requested_by' => (string) get_current_user_id(),
        ]);
        $this->redirect($this->correctionNotice((string) ($result['status'] ?? 'blocked')), (int) $document->order_id);
    }

    /** W4: continue a not-yet-dispatched correction (local error) or queue its read-back. */
    private function resumeCorrection(): void
    {
        $docId = isset($_REQUEST['document_id']) ? (int) $_REQUEST['document_id'] : 0;
        $document = (new InvoiceDocumentRepository())->getById($docId);
        $result = (new InvoiceCorrectionService())->resume($docId);
        $this->redirect(
            $this->correctionNotice((string) ($result['status'] ?? 'blocked')),
            (int) ($document->order_id ?? 0)
        );
    }

    private function correctionNotice(string $status): string
    {
        return match ($status) {
            'confirmed', 'already_confirmed' => 'correction_confirmed',
            'rejected', 'not_retryable' => 'correction_rejected',
            'uncertain', 'in_flight', 'queued' => 'correction_uncertain',
            'local_error', 'error' => 'correction_local_error',
            default => 'correction_blocked',
        };
    }

    private function sendEmail(): void
    {
        $docId = isset($_REQUEST['document_id']) ? (int) $_REQUEST['document_id'] : 0;
        $emails = array_filter(array_map('trim', explode(',', (string) ($_REQUEST['emails'] ?? ''))));
        $result = (new BillingoInvoiceService())->sendEmail($docId, array_values($emails));
        $this->redirect('email_' . (string) ($result['status'] ?? 'unknown'));
    }

    private function refreshStatus(): void
    {
        $docId = isset($_REQUEST['document_id']) ? (int) $_REQUEST['document_id'] : 0;
        $result = (new BillingoInvoiceService())->refreshStatus($docId);
        $this->redirect('status_' . (string) ($result['status'] ?? 'unknown'));
    }

    private function redirect(string $notice, int $orderId = 0): void
    {
        if ($orderId <= 0) {
            $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        }
        $args = ['tab' => 'billing', 'fch_notice' => $notice];
        if ($orderId > 0) {
            $args['fch_order_id'] = $orderId;
        }
        wp_safe_redirect(Menu::url($args));
        exit;
    }

    private static function actionLink(string $action, int $documentId, string $label): string
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . $action . '&document_id=' . $documentId),
            $action
        );
        return '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
}
