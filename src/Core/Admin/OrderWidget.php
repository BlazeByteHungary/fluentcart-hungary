<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Admin;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\PaymentAttemptRepository;
use FluentCartHungary\Core\Ledger\ShipmentRepository;

/**
 * Native FluentCart order-screen widget. Hooks `fluent_cart/widgets/single_order_page`, which the FluentCart
 * SPA actually calls (GET /wp-json/fluent-cart/v2/widgets?filter=single_order_page&data[order_id]=…,
 * see FluentCart Core `WidgetsController` and the admin `DynamicTemplates` renderer). The widget payload
 * contract is `['type' => 'html', 'title', 'subtitle', 'content']` with `content` rendered as innerHTML.
 *
 * (`fluent_cart/widgets/single_order` exists in Core's OrderController::getStats but is not wired to any
 * route in the tested FluentCart 1.7.0 build — see ADMIN_INTEGRATION_REPORT.md.)
 *
 * Read-only summary for one order plus a link to the plugin's orders tab. Never performs a provider call,
 * never mutates data, and never throws into FluentCart (a widget failure must not break the native screen).
 */
final class OrderWidget
{
    public function register(): void
    {
        add_filter('fluent_cart/widgets/single_order_page', [$this, 'append'], 10, 2);
    }

    /**
     * @param mixed $widgets
     * @param mixed $data payload from WidgetsController: ['order_id'=>int,'order_uuid'=>string,'order'=>object]
     * @return array<int,array<string,string>>
     */
    public function append($widgets, $data): array
    {
        $widgets = is_array($widgets) ? $widgets : [];
        try {
            $order = is_array($data) ? ($data['order'] ?? null) : (is_object($data) && isset($data->order) ? $data->order : null);
            $orderId = is_array($data) ? (int) ($data['order_id'] ?? 0) : (int) ($data->order_id ?? 0);
            if ($orderId <= 0 && is_object($order)) {
                $orderId = (int) ($order->id ?? 0);
            }
            if ($orderId <= 0) {
                return $widgets;
            }
            $content = self::content($orderId);
            if ($content === '') {
                return $widgets;
            }
            $widgets[] = [
                'type' => 'html',
                'title' => __('Magyar integrációk', 'fluentcart-hungary'),
                'subtitle' => __('Számlázás, fizetés és szállítás állapota', 'fluentcart-hungary'),
                'content' => $content,
            ];
        } catch (\Throwable $e) {
            return $widgets;
        }
        return $widgets;
    }

    private static function content(int $orderId): string
    {
        $attempts = (new PaymentAttemptRepository())->listForOrder($orderId, 5);
        $docs = (new InvoiceDocumentRepository())->listForOrder($orderId, 5);
        $shipments = (new ShipmentRepository())->listForOrder($orderId, 5);
        $unresolved = 0;
        foreach ((new LedgerRepository())->listForOrder($orderId, 50) as $row) {
            if (in_array((string) $row->state, [ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
                $unresolved++;
            }
        }

        if ($attempts === [] && $docs === [] && $shipments === [] && $unresolved === 0) {
            return '';
        }

        $lines = [];

        if ($attempts !== []) {
            $latest = $attempts[0];
            $lines[] = sprintf(
                '<strong>%s:</strong> <code>%s</code> <small>%s/%s · #%d</small>',
                esc_html__('Fizetés', 'fluentcart-hungary'),
                esc_html((string) $latest->state),
                esc_html((string) $latest->provider),
                esc_html((string) $latest->mode),
                (int) $latest->attempt_no
            );
        }

        foreach ($docs as $doc) {
            $lines[] = sprintf(
                '<strong>%s:</strong> %s <code>%s</code>',
                esc_html__('Bizonylat', 'fluentcart-hungary'),
                esc_html(trim((string) ($doc->document_number ?? '')) !== '' ? (string) $doc->document_number : '—'),
                esc_html((string) $doc->state)
            );
        }

        foreach ($shipments as $shipment) {
            $tracking = trim((string) ($shipment->tracking_number ?? ''));
            $lines[] = sprintf(
                '<strong>%s:</strong> <code>%s</code> %s',
                esc_html__('Szállítmány', 'fluentcart-hungary'),
                esc_html((string) $shipment->state),
                $tracking !== '' ? esc_html($tracking) : ''
            );
        }

        if ($unresolved > 0) {
            $lines[] = sprintf(
                '<span class="fch-hu-badge fch-hu-badge-warn">%s</span>',
                esc_html(sprintf(
                    /* translators: %d: number of unresolved operations */
                    __('%d egyeztetésre váró művelet', 'fluentcart-hungary'),
                    $unresolved
                ))
            );
        }

        $url = Menu::url(['tab' => 'orders', 'order_id' => $orderId]);
        $lines[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Részletek a Magyar integrációk oldalon', 'fluentcart-hungary')
        );

        return '<div class="fch-order-widget">' . implode('<br />', $lines) . '</div>';
    }
}
