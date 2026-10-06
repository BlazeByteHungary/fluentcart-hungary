<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing;

/**
 * Routes one invoicing intent to exactly one provider (W3 goal §3; ADR-008).
 *
 * Order of decisions:
 *  1. Existing binding: an active automatic document row for (order, doc_role) — including UNCERTAIN /
 *     AWAITING_RECONCILIATION — owns the intent. A global selection change must not reroute it, and a
 *     pending/uncertain document is never resolved by issuing a new one at the other provider.
 *  2. Otherwise the explicitly selected provider, and only if that module is enabled. If the selected
 *     provider is off or disabled the intent is skipped with a diagnostic reason; there is NO silent
 *     fallback to the other provider.
 */
final class InvoiceRouter
{
    public const REASON_BOUND = 'bound_to_existing_document';
    public const REASON_SELECTED = 'selected_provider';
    public const REASON_SELECTION_OFF = 'selection_off';
    public const REASON_PROVIDER_DISABLED = 'selected_provider_disabled';

    /**
     * @return array{provider:?string,reason:string,doc_role:string}
     */
    public static function route(int $orderId, string $docRole = 'invoice'): array
    {
        $bound = InvoicerSelection::boundProvider($orderId, $docRole);
        if ($bound !== null) {
            return ['provider' => $bound, 'reason' => self::REASON_BOUND, 'doc_role' => $docRole];
        }

        $selected = InvoicerSelection::selectedProvider();
        if ($selected === InvoicerSelection::SELECTION_OFF) {
            return ['provider' => null, 'reason' => self::REASON_SELECTION_OFF, 'doc_role' => $docRole];
        }
        if (!InvoicerSelection::isProviderEnabled($selected)) {
            return ['provider' => null, 'reason' => self::REASON_PROVIDER_DISABLED, 'doc_role' => $docRole];
        }

        return ['provider' => $selected, 'reason' => self::REASON_SELECTED, 'doc_role' => $docRole];
    }
}
