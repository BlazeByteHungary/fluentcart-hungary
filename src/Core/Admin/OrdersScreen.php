<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Shipping\CarrierBinding;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierRegistry;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupPointsModule;

/**
 * Orders tab: order-context lookup with the FluentCart Hungary surfaces for one order (native FC order
 * screen stays authoritative; this screen adds the provider/ledger context and the safe operations).
 *
 * Safe operations only: reprint = read the stored artifact; re-issue/re-create = enqueue a background job
 * (never an inline provider call); reconcile = enqueue the read-back. No manual money movement.
 */
final class OrdersScreen
{
    public const ACTION = 'fch_order_action';
    public const NONCE = 'fch_order_action';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handleAction']);
    }

    public static function renderContent(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }

        $term = '';
        if (isset($_GET['order_id'])) {
            $term = (string) (int) $_GET['order_id'];
        } elseif (isset($_GET['order_search'])) {
            $term = trim((string) wp_unslash($_GET['order_search']));
        }

        echo '<section class="fch-hu-card">';
        echo '<h2>' . esc_html__('Rendelés keresése', 'fluentcart-hungary') . '</h2>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="fch-hu-orders-search">';
        echo '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG) . '" />';
        echo '<input type="hidden" name="tab" value="orders" />';
        printf(
            '<input type="search" name="order_search" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr($term),
            esc_attr__('rendelés ID, számlaszám vagy e-mail', 'fluentcart-hungary')
        );
        submit_button(__('Keresés', 'fluentcart-hungary'), 'secondary', 'submit', false);
        echo '</form>';
        echo '<p class="description">' . esc_html__('A FluentCart rendelés adatai a natív FluentCart admin felületen maradnak; ez az oldal a magyar integrációk (fizetési kísérletek, bizonylatok, szállítmányok, csomagpont) állapotát és biztonságos műveleteit mutatja.', 'fluentcart-hungary') . '</p>';
        echo '</section>';

        if ($term === '') {
            return;
        }

        $order = self::findOrder($term);
        if ($order === null) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Nem található rendelés a megadott keresésre.', 'fluentcart-hungary') . '</p></div>';
            return;
        }

        self::renderOrder($order);
    }

    /** @return object|null FluentCart Order */
    public static function findOrder(string $term): ?object
    {
        $orderClass = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($orderClass) || $term === '') {
            return null;
        }

        $term = ltrim($term, '#');
        try {
            if (ctype_digit($term)) {
                $order = $orderClass::query()->find((int) $term);
                if (is_object($order)) {
                    return $order;
                }
            }
            if (str_contains($term, '@') && class_exists('FluentCart\\App\\Models\\Customer')) {
                $customer = \FluentCart\App\Models\Customer::query()->where('email', $term)->first();
                if (is_object($customer)) {
                    $order = $orderClass::query()->where('customer_id', (int) $customer->id)->orderBy('id', 'desc')->first();
                    if (is_object($order)) {
                        return $order;
                    }
                }
            }
            $order = $orderClass::query()->where('invoice_no', $term)->orderBy('id', 'desc')->first();
            return is_object($order) ? $order : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function renderOrder(object $order): void
    {
        $orderId = (int) ($order->id ?? 0);
        $settings = new ModuleSettings();
        $mode = $settings->mode();
        $account = $settings->accountId('szamlazz');
        $documents = new InvoiceDocumentRepository();
        $attempts = (new PaymentAttemptRepository())->listForOrder($orderId);
        $docs = $documents->listForOrder($orderId);
        $shipments = (new ShipmentRepository())->listForOrder($orderId);
        $ledgerRows = (new LedgerRepository())->listForOrder($orderId);
        $pickup = self::pickupSnapshot($order);

        // ---- Summary -------------------------------------------------------------------------------
        echo '<section class="fch-hu-card">';
        echo '<h2>' . sprintf(esc_html__('Rendelés #%s', 'fluentcart-hungary'), esc_html((string) $orderId));
        echo ' <a class="button button-small" href="' . esc_url(admin_url('admin.php?page=fluent-cart')) . '">' . esc_html__('FluentCart admin', 'fluentcart-hungary') . '</a></h2>';
        echo '<table class="fch-hu-kv"><tbody>';
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Számlaszám', 'fluentcart-hungary'), esc_html((string) ($order->invoice_no ?? '—')));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Állapot', 'fluentcart-hungary'), esc_html((string) ($order->status ?? '—')));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Összeg', 'fluentcart-hungary'), esc_html(self::money((int) ($order->total_amount ?? 0), (string) ($order->currency ?? 'HUF'))));
        $email = self::customerEmail($order);
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Vásárló', 'fluentcart-hungary'), esc_html($email !== '' ? $email : '—'));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Létrehozva', 'fluentcart-hungary'), esc_html((string) ($order->created_at ?? '—')));
        printf('<tr><th>%s</th><td>%s / <code>%s</code></td></tr>', esc_html__('Aktív mód', 'fluentcart-hungary'), esc_html($mode), esc_html($account));
        echo '</tbody></table>';
        echo '</section>';

        echo '<div class="fch-hu-panels">';

        // ---- Pickup --------------------------------------------------------------------------------
        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Csomagpont / átvétel', 'fluentcart-hungary') . '</h3>';
        if ($pickup === null) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs csomagpont adat ehhez a rendeléshez.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="fch-hu-kv"><tbody>';
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Szolgáltató', 'fluentcart-hungary'), esc_html((string) ($pickup['carrier'] ?? '—')));
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Átvételi mód', 'fluentcart-hungary'), esc_html((string) ($pickup['delivery_mode'] ?? '—')));
            $point = is_array($pickup['point'] ?? null) ? $pickup['point'] : [];
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Pont', 'fluentcart-hungary'), esc_html((string) ($point['name'] ?? '—')));
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Cím', 'fluentcart-hungary'), esc_html((string) ($point['address'] ?? '—')));
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('A csomagpont pillanatkép a rendelés létrehozásakor rögzült; a checkout sosem hagyatkozik újra a külső listára.', 'fluentcart-hungary') . '</p>';
        }
        echo '</div>';

        // ---- Carrier routing (3/3 gate) ------------------------------------------------------------
        self::renderRoutingPanel($order);

        // ---- Payment attempts ----------------------------------------------------------------------
        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Fizetési kísérletek', 'fluentcart-hungary') . '</h3>';
        if ($attempts === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs rögzített fizetési kísérlet.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>#</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Ellenőrzés', 'fluentcart-hungary') . '</th><th>' . esc_html__('Külső azonosító', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
            foreach ($attempts as $row) {
                echo '<tr>';
                printf('<td>%d<br /><small>%s</small></td>', (int) $row->attempt_no, esc_html((string) $row->provider . '/' . (string) $row->mode));
                echo '<td><code>' . esc_html((string) $row->state) . '</code></td>';
                echo '<td>' . esc_html((string) ($row->verification_source ?? '—')) . '</td>';
                echo '<td>' . esc_html((string) ($row->external_payment_id ?? '—')) . '</td>';
                echo '<td>' . self::reconcileButton((int) $row->operation_id, $orderId, __('Read-back', 'fluentcart-hungary')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // ---- Refunds (W4) --------------------------------------------------------------------------
        self::renderRefundsPanel($orderId, $order);

        // ---- Invoice documents ---------------------------------------------------------------------
        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Bizonylatok', 'fluentcart-hungary') . '</h3>';
        if ($docs === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs bizonylat ehhez a rendeléshez.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>#</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Bizonylatszám', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
            foreach ($docs as $row) {
                echo '<tr>';
                printf('<td>%d<br /><small>%s</small></td>', (int) $row->sequence_no, esc_html((string) $row->doc_role));
                echo '<td><code>' . esc_html((string) $row->state) . '</code></td>';
                echo '<td>' . esc_html((string) ($row->document_number ?? '—')) . '</td>';
                echo '<td>';
                if ((string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED) {
                    echo self::actionButton('download_document', ['fch_document' => (int) $row->id, 'fch_return' => $orderId], __('Újraletöltés', 'fluentcart-hungary'));
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('Az újraletöltés a tárolt bizonylatot adja vissza; az újrakiállítás külön, sorszámozott bizonylatot készít.', 'fluentcart-hungary') . '</p>';
        }
        // W1 lifecycle: one automatic document per order (single-invoicer invariant). A further document
        // requires a corrective/void path that this release does not implement, so the button is only
        // offered while no document exists (first issue / retry) instead of silently doing nothing.
        if ($docs === []) {
            echo '<div class="fch-hu-actions">' . self::actionButton('reissue_invoice', ['fch_return' => $orderId], __('Bizonylat kiállítása', 'fluentcart-hungary'), 'reissue') . '</div>';
        } else {
            echo '<p class="description fch-hu-lifecycle-note">' . esc_html__('Ehhez a rendeléshez már készült bizonylat. Sztornó/helyesbítő a Számlázás fül korrekciós műveleteivel készíthető; itt az újraletöltés használható.', 'fluentcart-hungary') . '</p>';
        }
        echo '</div>';

        // ---- Shipments -----------------------------------------------------------------------------
        $mplShipments = array_values(array_filter(
            $shipments,
            static fn ($row): bool => (string) ($row->carrier ?? 'mpl') === 'mpl'
        ));
        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Szállítmányok (MPL)', 'fluentcart-hungary') . '</h3>';
        if ($mplShipments === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs MPL szállítmány ehhez a rendeléshez.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>#</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Csomagszám', 'fluentcart-hungary') . '</th><th>' . esc_html__('Címke', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
            foreach ($mplShipments as $row) {
                echo '<tr>';
                printf('<td>%d<br /><small>%s</small></td>', (int) $row->shipment_no, esc_html((string) $row->carrier));
                echo '<td><code>' . esc_html((string) $row->state) . '</code></td>';
                echo '<td>' . esc_html((string) ($row->tracking_number ?? '—')) . '</td>';
                echo '<td>' . esc_html((string) $row->label_state) . '</td>';
                echo '<td>';
                if (trim((string) ($row->label_ref ?? '')) !== '') {
                    // W4: the label file is KEPT after an external cancellation (audit); downloading it
                    // must stay possible, and a label file is not the same as a withdrawn consignment.
                    echo self::actionButton('download_label', ['fch_shipment' => (int) $row->id, 'fch_return' => $orderId], __('Címke letöltése', 'fluentcart-hungary'));
                }
                if ((string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED && \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has('mpl')) {
                    echo self::actionButton('shipment_cancel', ['fch_shipment' => (int) $row->id, 'fch_return' => $orderId], __('Visszavonás (MPL)', 'fluentcart-hungary'));
                }
                echo self::reconcileButton((int) $row->operation_id, $orderId, __('Read-back', 'fluentcart-hungary'));
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        foreach (self::failedJobsForOrder($orderId) as $job) {
            printf(
                '<p class="description fch-hu-error">%s <code>%s</code>: %s</p>',
                esc_html__('Háttérművelet hiba', 'fluentcart-hungary'),
                esc_html((string) $job->job_key),
                esc_html((string) $job->last_error)
            );
        }
        echo '<div class="fch-hu-actions">' . self::actionButton('recreate_label', ['fch_return' => $orderId], __('MPL címke készítése', 'fluentcart-hungary')) . '</div>';
        echo '</div>';

        // ---- W2 carriers (registry-driven; MPL keeps its dedicated panel above) ---------------------
        foreach (CarrierRegistry::all() as $carrier => $meta) {
            if ($carrier === 'mpl') {
                continue;
            }
            $carrierShipments = array_values(array_filter(
                $shipments,
                static fn ($row): bool => (string) ($row->carrier ?? '') === $carrier
            ));
            echo '<div class="fch-hu-panel"><h3>' . esc_html(sprintf(
                /* translators: %s: carrier label */
                __('Szállítmányok (%s)', 'fluentcart-hungary'),
                (string) $meta['label']
            )) . '</h3>';
            if ($carrierShipments === []) {
                echo '<p class="fch-hu-empty">' . esc_html(sprintf(
                    /* translators: %s: carrier label */
                    __('Nincs %s szállítmány ehhez a rendeléshez.', 'fluentcart-hungary'),
                    (string) $meta['label']
                )) . '</p>';
            } else {
                echo '<table class="widefat striped fch-hu-table"><thead><tr><th>#</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Csomagszám', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
                foreach ($carrierShipments as $row) {
                    echo '<tr>';
                    printf('<td>%d</td>', (int) $row->shipment_no);
                    echo '<td><code>' . esc_html((string) $row->state) . '</code></td>';
                    echo '<td>' . esc_html((string) ($row->tracking_number ?? '—')) . '</td>';
                    echo '<td>';
                    if (trim((string) ($row->label_ref ?? '')) !== '') {
                        // Retained label file: remains downloadable after a cancellation (audit trail).
                        echo self::actionButton('download_label', ['fch_shipment' => (int) $row->id, 'fch_return' => $orderId], __('Címke letöltése', 'fluentcart-hungary'));
                    }
                    if ((string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED && \FluentCartHungary\Core\Shipping\CancelDispatcherRegistry::has($carrier)) {
                        echo self::actionButton('shipment_cancel', ['fch_shipment' => (int) $row->id, 'fch_return' => $orderId], __('Visszavonás', 'fluentcart-hungary'));
                    }
                    if ($carrier === 'packeta' && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED) {
                        echo self::actionButton('packeta_claim', ['fch_shipment' => (int) $row->id, 'fch_return' => $orderId], __('Visszaküldés (claim)', 'fluentcart-hungary'));
                    }
                    echo self::reconcileButton((int) $row->operation_id, $orderId, __('Read-back', 'fluentcart-hungary'));
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '<div class="fch-hu-actions">' . self::actionButton('recreate_label', ['fch_return' => $orderId, 'fch_carrier' => $carrier], (string) $meta['button']) . '</div>';
            if ($carrier === 'packeta' && is_object($order) && method_exists($order, 'getMeta')) {
                $claimRef = trim((string) ($order->getMeta('_fch_packeta_claim_ref') ?? ''));
                $claimPwRef = trim((string) ($order->getMeta('_fch_packeta_claim_password_ref') ?? ''));
                if ($claimRef !== '' || $claimPwRef !== '') {
                    echo '<p class="description">' . esc_html(sprintf('Visszaküldés (claim): %s', $claimRef !== '' ? $claimRef : '—')) . ' ';
                    if ($claimPwRef !== '') {
                        echo self::actionButton('claim_password', ['fch_return' => $orderId], __('Claim jelszó letöltése', 'fluentcart-hungary'));
                    }
                    echo '</p>';
                }
            }
            echo '</div>';
        }

        // ---- Ledger --------------------------------------------------------------------------------
        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Külső műveletek (ledger)', 'fluentcart-hungary') . '</h3>';
        if ($ledgerRows === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs rögzített külső művelet.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>ID</th><th>' . esc_html__('Szolgáltató', 'fluentcart-hungary') . '</th><th>' . esc_html__('Típus', 'fluentcart-hungary') . '</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
            foreach ($ledgerRows as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row->id) . '</td>';
                echo '<td>' . esc_html((string) $row->provider) . ' <small>' . esc_html((string) $row->mode) . '</small></td>';
                echo '<td>' . esc_html((string) $row->kind) . '</td>';
                echo '<td><code>' . esc_html((string) $row->state) . '</code></td>';
                echo '<td>' . self::reconcileButton((int) $row->id, $orderId, __('Read-back', 'fluentcart-hungary'));
                if (in_array((string) $row->state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                    echo '<form method="post" style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">';
                    wp_nonce_field(self::NONCE);
                    echo '<input type="hidden" name="fch_do" value="manual_resolve" />';
                    echo '<input type="hidden" name="fch_return" value="' . esc_attr((string) $orderId) . '" />';
                    echo '<input type="hidden" name="fch_ledger" value="' . esc_attr((string) $row->id) . '" />';
                    printf('<input type="text" name="fch_note" placeholder="%s" style="max-width:220px" /> ', esc_attr__('megjegyzés (szolgáltatónál ellenőrizve)', 'fluentcart-hungary'));
                    echo '<button type="submit" class="button button-small">' . esc_html__('Kézi lezárás', 'fluentcart-hungary') . '</button></form>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '</div>'; // panels
    }

    // ---- Handlers ----------------------------------------------------------------------------------

    public function handleAction(): void
    {
        if (!AdminShell::userCanAccess()) {
            wp_die(esc_html__('Nincs megfelelő jogosultsága.', 'fluentcart-hungary'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);

        $do = isset($_POST['fch_do']) ? sanitize_key(wp_unslash((string) $_POST['fch_do'])) : '';
        $return = isset($_POST['fch_return']) ? (int) $_POST['fch_return'] : 0;

        switch ($do) {
            case 'download_document':
                $this->downloadDocument(isset($_POST['fch_document']) ? (int) $_POST['fch_document'] : 0, $return);
                return;

            case 'download_label':
                $this->downloadLabel(isset($_POST['fch_shipment']) ? (int) $_POST['fch_shipment'] : 0, $return);
                return;

            case 'reissue_invoice':
                $this->reissueInvoice($return);
                return;

            case 'recreate_label':
                $carrier = isset($_POST['fch_carrier']) ? sanitize_key(wp_unslash((string) $_POST['fch_carrier'])) : 'mpl';
                $this->recreateLabel($return, $carrier !== '' ? $carrier : 'mpl');
                return;

            case 'reconcile':
                $ledgerId = isset($_POST['fch_ledger']) ? (int) $_POST['fch_ledger'] : 0;
                $ok = $ledgerId > 0 && (new OperationRunner())->enqueueReconcile($ledgerId);
                $this->redirect($return, $ok ? 'reconcile_done' : 'not_found');
                return;

            case 'refund_request':
                $this->requestRefund($return);
                return;

            case 'refund_manual':
                $this->recordManualRefund($return);
                return;

            case 'refund_reconcile':
                $this->reconcileRefund($return);
                return;

            case 'shipment_cancel':
                $this->cancelShipment(isset($_POST['fch_shipment']) ? (int) $_POST['fch_shipment'] : 0, $return);
                return;

            case 'packeta_claim':
                $this->packetaClaim(isset($_POST['fch_shipment']) ? (int) $_POST['fch_shipment'] : 0, $return);
                return;

            case 'claim_password':
                $this->downloadClaimPassword($return);
                return;

            case 'manual_resolve':
                $this->manualResolve(
                    isset($_POST['fch_ledger']) ? (int) $_POST['fch_ledger'] : 0,
                    (string) ($_POST['fch_note'] ?? ''),
                    $return
                );
                return;

            default:
                $this->redirect($return, 'invalid');
        }
    }

    private function downloadDocument(int $documentId, int $return): void
    {
        $service = new \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService();
        $result = $documentId > 0 ? $service->reprint($documentId) : null;
        if ($result === null || empty($result['pdf'])) {
            $this->redirect($return, 'document_missing');
            return;
        }
        self::streamDownload(
            (string) $result['pdf'],
            'szamla-' . ($result['document_number'] !== null && $result['document_number'] !== '' ? $result['document_number'] : (string) $documentId) . '.pdf'
        );
    }

    private function downloadLabel(int $shipmentId, int $return): void
    {
        $row = $shipmentId > 0 ? (new ShipmentRepository())->getById($shipmentId) : null;
        $ref = $row !== null ? trim((string) ($row->label_ref ?? '')) : '';
        $carrier = $row !== null ? (string) ($row->carrier ?? 'mpl') : 'mpl';
        $label = null;
        if ($ref !== '') {
            $label = $carrier === 'mpl'
                ? (new \FluentCartHungary\Modules\Shipping\Mpl\MplLabelStore())->read($ref)
                : (new CarrierLabelStore($carrier))->read($ref);
        }
        if ($label === null) {
            $this->redirect($return, 'document_missing');
            return;
        }
        self::streamDownload($label, $carrier . '-cimke-' . ($row->tracking_number !== null && $row->tracking_number !== '' ? (string) $row->tracking_number : (string) $shipmentId) . '.pdf');
    }

    /**
     * W4 logistics: merchant-initiated shipment withdrawal. The shared service continues from the proven
     * create state (local cancel, provider cancel or deferred job) — an FC order status alone never calls it.
     */
    private function cancelShipment(int $shipmentId, int $orderId): void
    {
        if ($shipmentId <= 0) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        $result = (new \FluentCartHungary\Core\Shipping\ShipmentCancellationService())->request($shipmentId);
        $notice = match ((string) ($result['status'] ?? '')) {
            'cancelled_externally', 'already_cancelled' => 'shipment_cancelled',
            'cancelled_local' => 'shipment_cancelled_local',
            'deferred' => 'shipment_cancel_deferred',
            'uncertain', 'in_flight' => 'shipment_cancel_uncertain',
            'rejected', 'not_cancellable', 'not_retryable' => 'shipment_cancel_rejected',
            default => 'shipment_cancel_blocked',
        };
        $this->redirect($orderId, $notice);
    }

    /** W4 (PKT-007): ledger-routed Packeta return claim; the 9-digit password is stored sealed. */
    private function packetaClaim(int $shipmentId, int $orderId): void
    {
        if ($shipmentId <= 0) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        $result = (new \FluentCartHungary\Modules\Shipping\Packeta\PacketaClaimService())->request($shipmentId);
        $notice = match ((string) ($result['status'] ?? '')) {
            'confirmed', 'already_confirmed' => 'claim_confirmed',
            'rejected' => 'claim_rejected',
            'uncertain', 'in_flight' => 'claim_uncertain',
            'local_error', 'error' => 'claim_local_error',
            default => 'claim_blocked',
        };
        $this->redirect($orderId, $notice);
    }

    private function downloadClaimPassword(int $orderId): void
    {
        $orderClass = 'FluentCart\\App\\Models\\Order';
        $order = ($orderId > 0 && class_exists($orderClass)) ? $orderClass::find($orderId) : null;
        if (!is_object($order) || !method_exists($order, 'getMeta')) {
            $this->redirect($orderId, 'document_missing');
            return;
        }
        $ref = trim((string) ($order->getMeta('_fch_packeta_claim_password_ref') ?? ''));
        $claim = trim((string) ($order->getMeta('_fch_packeta_claim_ref') ?? ''));
        $content = $ref !== '' ? (new CarrierLabelStore('packeta'))->read($ref) : null;
        if ($content === null || $content === '') {
            $this->redirect($orderId, 'document_missing');
            return;
        }
        self::streamDownload($content, 'packeta-claim-jelszo-' . ($claim !== '' ? $claim : (string) $orderId) . '.txt');
    }

    /** Operator resolution for an unprovable outcome (claims, DPD cancel): audited note, no re-dispatch. */
    private function manualResolve(int $ledgerId, string $note, int $orderId): void
    {
        $note = function_exists('sanitize_text_field') ? sanitize_text_field(wp_unslash($note)) : trim($note);
        $ok = $ledgerId > 0 && (new OperationRunner())->resolveManually($ledgerId, $note);
        $this->redirect($orderId, $ok ? 'manual_resolved' : 'not_found');
    }

    /** Re-issue enqueues a background job only; the provider call happens in IssueInvoiceJob. */
    private function reissueInvoice(int $orderId): void
    {
        if ($orderId <= 0 || !class_exists('FluentCart\\App\\Models\\Order')) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        $settings = new ModuleSettings();
        $documents = new InvoiceDocumentRepository();
        $next = $documents->nextSequence(
            $orderId,
            \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzInvoiceService::DOC_ROLE_INVOICE,
            'szamlazz',
            $settings->mode(),
            $settings->accountId('szamlazz')
        );

        // Retry the OPEN sequence (same business identity) instead of advancing past a document that was
        // never confirmed; a new sequence is only justified after a confirmed one.
        $latest = null;
        $maxSequence = 0;
        foreach ($documents->listForOrder($orderId, 20) as $row) {
            if ((int) $row->sequence_no > $maxSequence) {
                $maxSequence = (int) $row->sequence_no;
                $latest = $row;
            }
        }
        if ($latest !== null && (string) $latest->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            $next = $maxSequence;
        }

        // Key format matches the module's automatic path for sequence 1 (one business identity).
        if ($next <= 1) {
            $key = sprintf('fch:%d:invoice_job', $orderId);
            $args = ['order_id' => $orderId];
        } else {
            $key = sprintf('fch:%d:invoice_job:%d', $orderId, $next);
            $args = ['order_id' => $orderId, 'sequence' => $next];
        }

        $queued = \FluentCartHungary\Core\Jobs\JobRunner::instance()->enqueueRerunnable(new \FluentCartHungary\Core\Jobs\JobSpec(
            $key,
            \FluentCartHungary\Modules\Billing\Szamlazz\IssueInvoiceJob::HOOK,
            $args,
            10,
            3,
            \FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzModule::ID
        ));
        $this->redirect($orderId, $queued ? 'queued' : 'queue_failed');
    }

    /** Label re-create enqueues a background job only (never a live provider POST from the admin request). */
    private function recreateLabel(int $orderId, string $carrier = 'mpl'): void
    {
        if ($orderId <= 0 || !class_exists('FluentCart\\App\\Models\\Order')) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        if ($carrier === 'mpl') {
            $hook = \FluentCartHungary\Modules\Shipping\Mpl\CreateLabelJob::HOOK;
            $moduleId = \FluentCartHungary\Modules\Shipping\Mpl\MplModule::ID;
            $keyPrefix = sprintf('fch:%d:label_job', $orderId);
        } else {
            $meta = CarrierRegistry::get($carrier);
            if ($meta === null) {
                $this->redirect($orderId, 'invalid');
                return;
            }
            $hook = (string) $meta['create_hook'];
            $moduleId = (string) $meta['module_id'];
            $keyPrefix = sprintf('fch:%d:%s:label_job', $orderId, $carrier);
        }
        $repo = new ShipmentRepository();

        // Retry the OPEN shipment sequence (same business identity: NOT_STARTED/IN_PROGRESS/UNCERTAIN)
        // instead of advancing past a label that was never confirmed; a new sequence (new parcel,
        // separate audit trail) is only created after a CONFIRMED one. Sequences are per carrier.
        $latest = null;
        $shipmentNo = 1;
        foreach ($repo->listForOrder($orderId, 50) as $row) {
            if ((string) ($row->carrier ?? 'mpl') !== $carrier) {
                continue;
            }
            if ((int) $row->shipment_no >= $shipmentNo) {
                $shipmentNo = (int) $row->shipment_no;
                $latest = $row;
            }
        }
        if ($latest !== null && (string) $latest->state === ExternalOperation::EXTERNAL_CONFIRMED) {
            $shipmentNo = (int) $latest->shipment_no + 1;
        }
        if ($shipmentNo <= 0) {
            $shipmentNo = 1;
        }

        $key = sprintf('%s:%d', $keyPrefix, $shipmentNo);

        $runner = \FluentCartHungary\Core\Jobs\JobRunner::instance();
        $queued = $runner->enqueueRerunnable(new \FluentCartHungary\Core\Jobs\JobSpec(
            $key,
            $hook,
            ['order_id' => $orderId, 'shipment_no' => $shipmentNo],
            10,
            3,
            $moduleId
        ));
        if (!$queued) {
            // A concurrent/duplicate click may hit the dedupe guard on a still-PENDING row: the intent is
            // already queued, so report success instead of a bogus failure.
            $existing = method_exists($runner->store(), 'findByKey') ? $runner->store()->findByKey($key) : null;
            $queued = $existing !== null && in_array((string) ($existing->state ?? ''), ['PENDING', 'RUNNING'], true);
        }
        $this->redirect($orderId, $queued ? 'queued' : 'queue_failed');
    }

    // ---- Refunds (W4) -----------------------------------------------------------------------------

    /** Latest succeeded charge transaction of the order (the original payment refunds bind to). */
    private static function chargeTransaction(int $orderId): ?object
    {
        if (!class_exists('\\FluentCart\\App\\Models\\OrderTransaction')) {
            return null;
        }
        try {
            return \FluentCart\App\Models\OrderTransaction::query()
                ->where('order_id', $orderId)
                ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE)
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function requestRefund(int $orderId): void
    {
        $txn = self::chargeTransaction($orderId);
        if ($txn === null) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        $currency = strtoupper((string) ($txn->currency ?? 'HUF'));
        $minor = \FluentCartHungary\Core\Money\Money::toMinor((string) ($_POST['fch_refund_amount'] ?? '0'), $currency);
        $cents = \FluentCartHungary\Core\Compat\FluentCartMoney::toCartCents($minor, $currency);
        $reason = isset($_POST['fch_refund_reason']) ? sanitize_text_field(wp_unslash((string) $_POST['fch_refund_reason'])) : '';
        $intent = isset($_POST['fch_refund_intent']) ? sanitize_text_field(wp_unslash((string) $_POST['fch_refund_intent'])) : '';

        $provider = strtolower((string) ($txn->payment_method ?? ''));
        $gateway = class_exists('\\FluentCart\\App\\App') ? \FluentCart\App\App::gateway($provider) : null;
        if ($gateway === null || !method_exists($gateway, 'has') || !$gateway->has('refund')) {
            $this->redirect($orderId, 'refund_unsupported', __('Ehhez a fizetési módhoz nincs gateway-visszatérítés ebben a verzióban; használja a kézi rögzítést.', 'fluentcart-hungary'));
            return;
        }
        if ($cents <= 0 || !class_exists('\\FluentCart\\App\\Services\\Payments\\Refund')) {
            $this->redirect($orderId, 'invalid');
            return;
        }

        $result = (new \FluentCart\App\Services\Payments\Refund())->processRefund($txn, $cents, [
            'fch_refund_intent' => $intent !== '' ? $intent : wp_generate_uuid4(),
            'reason' => $reason,
        ]);

        if (is_wp_error($result)) {
            $code = (string) $result->get_error_code();
            $message = (string) $result->get_error_message();
            if ($code === 'fch_refund_uncertain') {
                $this->redirect($orderId, 'refund_uncertain', $message);
                return;
            }
            if ($code === 'fch_refund_rejected' || $code === 'fch_refund_confirmed_no_id') {
                $this->redirect($orderId, 'refund_rejected', $message);
                return;
            }
            $this->redirect($orderId, 'refund_failed', $message);
            return;
        }
        $this->redirect($orderId, 'refund_done');
    }

    private function recordManualRefund(int $orderId): void
    {
        $txn = self::chargeTransaction($orderId);
        if ($txn === null) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        $currency = strtoupper((string) ($txn->currency ?? 'HUF'));
        $minor = \FluentCartHungary\Core\Money\Money::toMinor((string) ($_POST['fch_refund_amount'] ?? '0'), $currency);
        $cents = \FluentCartHungary\Core\Compat\FluentCartMoney::toCartCents($minor, $currency);
        $reason = isset($_POST['fch_refund_reason']) ? sanitize_text_field(wp_unslash((string) $_POST['fch_refund_reason'])) : '';

        $service = new \FluentCartHungary\Core\Payments\RefundService();
        $check = $service->validateManualRefund($orderId, $cents, $currency);
        if ($check['outcome'] !== 'ok') {
            $this->redirect($orderId, 'refund_rejected', __('A kézi visszatérítés nem érvényes (összeg vagy nincs elég visszatéríthető egyenleg).', 'fluentcart-hungary'));
            return;
        }

        $filter = static function ($value) {
            return ['status' => 'yes', 'source' => 'fch_manual'];
        };
        add_filter('fluent_cart/order_refund_manually', $filter, 10, 2);
        $result = (new \FluentCart\App\Services\Payments\Refund())->processRefund($txn, $cents, ['reason' => $reason]);
        remove_filter('fluent_cart/order_refund_manually', $filter, 10);

        if (is_wp_error($result)) {
            $this->redirect($orderId, 'refund_failed', (string) $result->get_error_message());
            return;
        }

        $service->request([
            'order_id' => $orderId,
            'parent_txn_id' => (int) $txn->id,
            'amount_minor' => $minor,
            'currency' => $currency,
            'provider' => 'manual',
            'mode' => (string) ($txn->payment_mode ?? (new ModuleSettings())->mode()),
            'account_id' => 'default',
            'reason' => $reason,
            'manual' => true,
        ]);
        $this->redirect($orderId, 'refund_manual_recorded');
    }

    private function reconcileRefund(int $orderId): void
    {
        $opId = isset($_POST['fch_refund_op']) ? (int) $_POST['fch_refund_op'] : 0;
        if ($opId <= 0) {
            $this->redirect($orderId, 'not_found');
            return;
        }
        $state = (new \FluentCartHungary\Core\Payments\RefundService())->reconcileNow($opId);
        $notice = $state === ExternalOperation::EXTERNAL_CONFIRMED ? 'reconciled' : ($state === ExternalOperation::PROVEN_REJECTED ? 'refund_rejected' : 'reconcile_done');
        $this->redirect($orderId, $notice);
    }

    private static function refundStateLabel(string $state): string
    {
        $map = [
            'NOT_STARTED' => __('Szándék rögzítve (még nincs hívás)', 'fluentcart-hungary'),
            'IN_PROGRESS' => __('Feldolgozás alatt', 'fluentcart-hungary'),
            'EXTERNAL_CONFIRMED' => __('Visszatérítve (megerősítve)', 'fluentcart-hungary'),
            'PROVEN_REJECTED' => __('Elutasítva', 'fluentcart-hungary'),
            'UNCERTAIN' => __('Bizonytalan eredmény (egyeztetés alatt)', 'fluentcart-hungary'),
            'AWAITING_RECONCILIATION' => __('Egyeztetésre vár (kézi döntés lehetséges)', 'fluentcart-hungary'),
            'MANUALLY_RESOLVED' => __('Kézzel lezárva', 'fluentcart-hungary'),
            'SUPERSEDED' => __('Felülírva', 'fluentcart-hungary'),
        ];
        return $map[$state] ?? $state;
    }

    private static function renderRefundsPanel(int $orderId, object $order): void
    {
        $currency = strtoupper((string) ($order->currency ?? 'HUF'));
        $service = new \FluentCartHungary\Core\Payments\RefundService();
        $txn = self::chargeTransaction($orderId);
        $refunds = $service->refundsForOrder($orderId);

        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Pénzvisszatérítések', 'fluentcart-hungary') . '</h3>';
        if ($txn === null) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs rögzített terhelés; visszatérítés nem indítható.', 'fluentcart-hungary') . '</p>';
        } else {
            $availability = $service->availabilityForTransaction($txn);
            echo '<table class="fch-hu-kv"><tbody>';
            printf('<tr><th>%s</th><td>%s / %s</td></tr>', esc_html__('Eredeti fizetés', 'fluentcart-hungary'), esc_html(strtoupper((string) ($txn->payment_method ?? '—'))), esc_html((string) ($txn->payment_mode ?? '—')));
            printf('<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__('Szolgáltatói azonosító', 'fluentcart-hungary'), esc_html(trim((string) ($txn->vendor_charge_id ?? '')) !== '' ? (string) $txn->vendor_charge_id : '—'));
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Terhelt összeg', 'fluentcart-hungary'), esc_html(self::money((int) $availability['charged_minor'], $currency)));
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Már visszatérítve (megerősítve)', 'fluentcart-hungary'), esc_html(self::money((int) $availability['confirmed_refunded_minor'], $currency)));
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Függő foglalás', 'fluentcart-hungary'), esc_html(self::money((int) $availability['open_reserved_minor'], $currency)));
            printf('<tr><th>%s</th><td><strong>%s</strong></td></tr>', esc_html__('Még visszatéríthető', 'fluentcart-hungary'), esc_html(self::money((int) $availability['available_minor'], $currency)));
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('A kérelem beadása még nem pénzvisszatérítés: a függő összeg foglalt marad, és csak a szolgáltatói megerősítés után jelenik meg végrehajtott visszatérítésként. Bizonytalan eredmény esetén az egyeztetés kézi döntéssel zárható.', 'fluentcart-hungary') . '</p>';

            if ((int) $availability['available_minor'] > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-refund-form">';
                wp_nonce_field(self::NONCE);
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
                echo '<input type="hidden" name="fch_do" value="refund_request" />';
                echo '<input type="hidden" name="fch_return" value="' . esc_attr((string) $orderId) . '" />';
                echo '<input type="hidden" name="fch_refund_intent" value="' . esc_attr(wp_generate_uuid4()) . '" />';
                printf('<input type="text" name="fch_refund_amount" value="%s" class="small-text" /> ', esc_attr(\FluentCartHungary\Core\Money\Money::fromMinor((int) $availability['available_minor'], $currency)));
                printf('<input type="text" name="fch_refund_reason" class="regular-text" placeholder="%s" /> ', esc_attr__('ok (opcionális)', 'fluentcart-hungary'));
                submit_button(__('Visszatérítés kérése (gateway)', 'fluentcart-hungary'), 'secondary', 'submit', false);
                echo '</form>';

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-refund-form">';
                wp_nonce_field(self::NONCE);
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
                echo '<input type="hidden" name="fch_do" value="refund_manual" />';
                echo '<input type="hidden" name="fch_return" value="' . esc_attr((string) $orderId) . '" />';
                printf('<input type="text" name="fch_refund_amount" value="%s" class="small-text" /> ', esc_attr(\FluentCartHungary\Core\Money\Money::fromMinor((int) $availability['available_minor'], $currency)));
                printf('<input type="text" name="fch_refund_reason" class="regular-text" placeholder="%s" /> ', esc_attr__('kézi/utalásos visszaadás oka', 'fluentcart-hungary'));
                submit_button(__('Kézi visszatérítés rögzítése', 'fluentcart-hungary'), 'secondary', 'submit', false);
                echo '</form>';
            }
        }

        if ($refunds === []) {
            echo '<p class="fch-hu-empty">' . esc_html__('Még nincs visszatérítési művelet.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<table class="widefat striped fch-hu-table"><thead><tr><th>#</th><th>' . esc_html__('Összeg', 'fluentcart-hungary') . '</th><th>' . esc_html__('Állapot', 'fluentcart-hungary') . '</th><th>' . esc_html__('Szolgáltatói visszatérítés', 'fluentcart-hungary') . '</th><th>' . esc_html__('Időpont', 'fluentcart-hungary') . '</th><th></th></tr></thead><tbody>';
            foreach ($refunds as $row) {
                $state = (string) $row->state;
                echo '<tr>';
                printf('<td>%d<br /><small>%s/%s</small></td>', (int) $row->id, esc_html((string) $row->provider), esc_html((string) $row->mode));
                echo '<td>' . esc_html(self::money((int) $row->amount_minor, strtoupper((string) $row->currency))) . '</td>';
                echo '<td>' . esc_html(self::refundStateLabel($state)) . '<br /><code>' . esc_html($state) . '</code></td>';
                echo '<td><code>' . esc_html(trim((string) ($row->external_id ?? '')) !== '' ? (string) $row->external_id : '—') . '</code></td>';
                echo '<td>' . esc_html((string) ($row->created_at ?? '')) . ((string) ($row->reconciled_at ?? '') !== '' ? '<br /><small>' . esc_html__('egyeztetve:', 'fluentcart-hungary') . ' ' . esc_html((string) $row->reconciled_at) . '</small>' : '') . '</td>';
                echo '<td>';
                if (in_array($state, ['UNCERTAIN', 'AWAITING_RECONCILIATION', 'NOT_STARTED'], true)) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                    wp_nonce_field(self::NONCE);
                    echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
                    echo '<input type="hidden" name="fch_do" value="refund_reconcile" />';
                    echo '<input type="hidden" name="fch_return" value="' . esc_attr((string) $orderId) . '" />';
                    echo '<input type="hidden" name="fch_refund_op" value="' . esc_attr((string) $row->id) . '" />';
                    submit_button(__('Egyeztetés', 'fluentcart-hungary'), 'small', 'submit', false);
                    echo '</form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private function redirect(int $orderId, string $notice, string $detail = ''): void
    {
        $args = ['tab' => 'orders', 'fch_notice' => $notice];
        if ($orderId > 0) {
            $args['order_id'] = $orderId;
        }
        if ($detail !== '') {
            $args['fch_detail'] = base64_encode($detail);
        }
        wp_safe_redirect(Menu::url($args));
        exit;
    }

    private static function streamDownload(string $binary, string $filename): void
    {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($binary));
        header('X-Content-Type-Options: nosniff');
        echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput -- binary attachment
        exit;
    }

    // ---- Small render helpers ---------------------------------------------------------------------

    private static function actionButton(string $do, array $fields, string $label, string $confirm = ''): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 2px 0">';
        $html .= wp_nonce_field(self::NONCE, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        $html .= '<input type="hidden" name="fch_do" value="' . esc_attr($do) . '" />';
        foreach ($fields as $name => $value) {
            $html .= sprintf('<input type="hidden" name="%s" value="%s" />', esc_attr((string) $name), esc_attr((string) $value));
        }
        $html .= sprintf(
            '<button type="submit" class="button button-small"%s>%s</button>',
            $confirm !== '' ? ' data-fch-confirm="' . esc_attr($confirm) . '"' : '',
            esc_html($label)
        );
        $html .= '</form>';
        return $html;
    }

    private static function reconcileButton(int $ledgerId, int $orderId, string $label): string
    {
        if ($ledgerId <= 0) {
            return '';
        }
        return self::actionButton('reconcile', ['fch_ledger' => $ledgerId, 'fch_return' => $orderId], $label, 'reconcile');
    }

    /**
     * Failed/dead-letter background jobs for this order, so operator-fixable refusals (e.g. a missing
     * recipient phone) are visible in the order context instead of only in the job table.
     *
     * @return list<object>
     */
    private static function failedJobsForOrder(int $orderId): array
    {
        global $wpdb;
        if (!is_object($wpdb) || $orderId <= 0) {
            return [];
        }
        $table = $wpdb->prefix . 'fch_jobs';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT job_key, last_error FROM `{$table}` WHERE job_key LIKE %s AND state IN ('FAILED','DEAD_LETTER') ORDER BY id DESC LIMIT 3",
            'fch:' . $orderId . ':%'
        ));
        return is_array($rows) ? $rows : [];
    }

    private static function money(int $minor, string $currency): string
    {
        if (class_exists('FluentCart\\App\\Helpers\\Helper') && method_exists('FluentCart\\App\\Helpers\\Helper', 'toDecimal')) {
            try {
                return (string) \FluentCart\App\Helpers\Helper::toDecimal($minor, true, strtoupper($currency));
            } catch (\Throwable $e) {
                // fall through to the plain formatter
            }
        }
        return number_format_i18n($minor) . ' ' . strtoupper($currency);
    }

    private static function customerEmail(object $order): string
    {
        try {
            if (class_exists('FluentCart\\App\\Models\\Customer')) {
                $customer = \FluentCart\App\Models\Customer::query()->find((int) ($order->customer_id ?? 0));
                if (is_object($customer)) {
                    return (string) ($customer->email ?? '');
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /** @return array{point:array<string,mixed>,carrier:string,delivery_mode:string}|null */
    /**
     * Order-time carrier routing (3/3 release gate): show the immutable snapshot decision, or an
     * actionable warning + manual assignment when nothing was resolved. Automatic label creation never
     * runs for an unresolved order; a manual assignment is the merchant's explicit choice.
     */
    private static function renderRoutingPanel(object $order): void
    {
        $orderId = (int) ($order->id ?? 0);
        $resolution = CarrierBinding::resolveForOrder($order, false);
        $hasShipping = (int) ($order->shipping_method_id ?? 0) > 0 || (float) ($order->shipping_total ?? 0) > 0;

        echo '<div class="fch-hu-panel"><h3>' . esc_html__('Futár-routing', 'fluentcart-hungary') . '</h3>';
        if ($resolution['resolved']) {
            $meta = CarrierRegistry::get((string) $resolution['carrier']);
            $label = is_array($meta) && trim((string) ($meta['label'] ?? '')) !== ''
                ? (string) $meta['label']
                : strtoupper((string) $resolution['carrier']);
            echo '<table class="fch-hu-kv"><tbody>';
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Futár', 'fluentcart-hungary'), esc_html($label));
            printf('<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__('Szolgáltatás', 'fluentcart-hungary'), esc_html((string) $resolution['service']));
            printf('<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__('Forrás', 'fluentcart-hungary'), esc_html((string) $resolution['source']));
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('A rendeléskori kötés rögzített; későbbi módszer-átkötés ezt nem változtatja meg.', 'fluentcart-hungary') . '</p>';
        } elseif (!$hasShipping) {
            echo '<p class="fch-hu-empty">' . esc_html__('Nincs szállítási igény (nincs szállítási módszer/díj) — automatikus carrier-művelet nem indul.', 'fluentcart-hungary') . '</p>';
        } else {
            echo '<div class="notice notice-warning inline" style="margin:6px 0"><p>'
                . esc_html__('Nincs egyértelmű futárkötés ehhez a rendeléshez: automatikus címkekészítés nem indul. Rendeljen hozzá futárt manuálisan, vagy állítson be legacy-default policy-t a Szállítás fülön.', 'fluentcart-hungary')
                . '</p></div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fch-hu-actions">';
            wp_nonce_field(PickupPointsModule::ASSIGN_ACTION, '_wpnonce');
            echo '<input type="hidden" name="action" value="' . esc_attr(PickupPointsModule::ASSIGN_ACTION) . '" />';
            echo '<input type="hidden" name="fch_order" value="' . esc_attr((string) $orderId) . '" />';
            echo '<select name="fch_carrier">';
            foreach (CarrierRegistry::all() as $slug => $meta) {
                printf('<option value="%s">%s</option>', esc_attr((string) $slug), esc_html((string) ($meta['label'] ?? strtoupper((string) $slug))));
            }
            echo '</select> ';
            echo '<select name="fch_service"><option value="">' . esc_html__('Automatikus (pont/mód szerint)', 'fluentcart-hungary') . '</option>';
            foreach ([CarrierBinding::SERVICE_HOME => __('Házhozszállítás', 'fluentcart-hungary'), CarrierBinding::SERVICE_PICKUP => __('Csomagpont', 'fluentcart-hungary'), CarrierBinding::SERVICE_LOCKER => __('Automata', 'fluentcart-hungary')] as $service => $serviceLabel) {
                printf('<option value="%s">%s</option>', esc_attr((string) $service), esc_html((string) $serviceLabel));
            }
            echo '</select> ';
            submit_button(__('Futár hozzárendelése', 'fluentcart-hungary'), 'small', 'submit', false);
            echo '</form>';
        }
        echo '</div>';
    }

    private static function pickupSnapshot(object $order): ?array
    {
        if (!method_exists($order, 'getMeta')) {
            return null;
        }
        try {
            $raw = $order->getMeta('_fch_pickup_point', '');
            $carrier = (string) $order->getMeta('_fch_carrier', '');
            $mode = (string) $order->getMeta('_fch_delivery_mode', '');
        } catch (\Throwable $e) {
            return null;
        }
        $point = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $point = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $point = $raw;
        }
        if ($point === [] && $carrier === '' && $mode === '') {
            return null;
        }
        return ['point' => $point, 'carrier' => $carrier, 'delivery_mode' => $mode];
    }
}
