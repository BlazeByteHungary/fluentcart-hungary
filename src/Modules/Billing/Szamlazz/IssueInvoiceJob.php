<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Jobs\JobInterface;
use FluentCartHungary\Modules\Billing\InvoiceRouter;

/**
 * Async invoice-issuance job (hook `fch_issue_invoice`), enqueued once per order from
 * `fluent_cart/order_paid_done` / `fch/shipping/label_started` with the stable job key
 * `fch:{order}:invoice_job` (both billing modules enqueue the same key, so one intent = one job).
 *
 * The job is provider-neutral since W3: InvoiceRouter decides the provider from the existing document
 * binding first, then from the shared invoicer selection (W3_BILLINGO_REPORT §routing). Idempotency
 * lives in the ledger + document unique keys: a duplicate/retried run finds the terminal row and
 * performs no external call (05_DATA_SCHEMA §8; invariants §1.3/§1.5). Local errors (before dispatch)
 * stay NOT_STARTED and are retried by the queue under the job's attempt budget.
 */
final class IssueInvoiceJob implements JobInterface
{
    public const HOOK = 'fch_issue_invoice';

    public function hook(): string
    {
        return self::HOOK;
    }

    public function handle(array $args): void
    {
        $orderId = (int) ($args['order_id'] ?? 0);
        $sequence = (int) ($args['sequence'] ?? 1);
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('order_id is required for ' . self::HOOK . '.');
        }
        if ($sequence <= 0) {
            $sequence = 1;
        }

        $route = InvoiceRouter::route($orderId, 'invoice');
        if ($route['provider'] === null) {
            do_action('fch/invoice/skipped', $orderId, $route['reason'], $route['doc_role']);
            return;
        }

        if ($route['provider'] === 'billingo') {
            $serviceClass = '\\FluentCartHungary\\Modules\\Billing\\Billingo\\BillingoInvoiceService';
            if (!class_exists($serviceClass)) {
                do_action('fch/invoice/skipped', $orderId, 'billingo_service_missing', $route['doc_role']);
                return;
            }
            $result = (new $serviceClass())->issue($orderId, $sequence);
            do_action('fch/billingo/invoice_result', $orderId, $result);
        } else {
            $result = (new SzamlazzInvoiceService())->issue($orderId, $sequence);
            do_action('fch/szamlazz/invoice_result', $orderId, $result);
        }

        do_action('fch/invoice/result', $orderId, $route['provider'], $result);
    }
}
