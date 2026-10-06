<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing;

use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\ModuleRegistry;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Shared invoicing-selection contract for the two billing providers (W3 goal §3; ADR-008).
 *
 * Contract:
 *  - A normal invoicing intent is handled by exactly ONE provider: the provider already bound to the
 *    (order, doc_role) by an active automatic document row, otherwise the explicitly selected provider.
 *    A switch of the global selection never reroutes an existing row (including UNCERTAIN ones) and never
 *    re-invoices an invoiced order — binding wins.
 *  - Selection lives in the shared `billing` settings scope (`selected_invoicer` ∈ {szamlazz, billingo,
 *    off}). Until the shared selector is saved for the first time the legacy `szamlazz.selected_invoicer`
 *    value is honoured (W1 installs: unset → szamlazz, explicit empty → fail-safe off).
 *  - `cod_invoice_event` is likewise shared (`billing.cod_invoice_event`), falling back to the legacy
 *    `szamlazz.cod_invoice_event` value.
 *  - Issuing a document NEVER changes the FluentCart payment status (policy invariant).
 */
final class InvoicerSelection
{
    public const PROVIDER_SZAMLAZZ = 'szamlazz';
    public const PROVIDER_BILLINGO = 'billingo';
    public const SELECTION_OFF = 'off';

    public const SHARED_SCOPE = 'billing';
    public const LEGACY_SCOPE = 'szamlazz';

    public const INVOICE_ON_PAYMENT = 'payment';
    public const INVOICE_ON_BEFORE_DISPATCH = 'before_dispatch';

    /** @return list<string> */
    public static function providers(): array
    {
        return [self::PROVIDER_SZAMLAZZ, self::PROVIDER_BILLINGO];
    }

    public static function moduleId(string $provider): string
    {
        return 'billing/' . $provider;
    }

    /** Selected provider, or SELECTION_OFF for the explicit fail-safe. */
    public static function selectedProvider(): string
    {
        try {
            $settings = new ModuleSettings();
            $shared = $settings->get(self::SHARED_SCOPE, 'selected_invoicer', null);
            if (is_string($shared) && $shared !== '') {
                return in_array($shared, self::providers(), true) ? $shared : self::SELECTION_OFF;
            }

            // Shared selector never saved: legacy Számlázz scope is authoritative.
            $legacy = $settings->get(self::LEGACY_SCOPE, 'selected_invoicer', null);
            if ($legacy === null) {
                return self::PROVIDER_SZAMLAZZ; // W1 default
            }
            return in_array($legacy, self::providers(), true) ? (string) $legacy : self::SELECTION_OFF;
        } catch (\Throwable $e) {
            return self::SELECTION_OFF; // fail-safe
        }
    }

    public static function isSelected(string $provider): bool
    {
        return self::selectedProvider() === $provider;
    }

    /** Shared COD policy: 'payment' (default) or 'before_dispatch'. */
    public static function codInvoiceEvent(): string
    {
        try {
            $settings = new ModuleSettings();
            $shared = $settings->get(self::SHARED_SCOPE, 'cod_invoice_event', null);
            $value = is_string($shared) && $shared !== ''
                ? $shared
                : (string) $settings->get(self::LEGACY_SCOPE, 'cod_invoice_event', self::INVOICE_ON_PAYMENT);
            return in_array($value, [self::INVOICE_ON_PAYMENT, self::INVOICE_ON_BEFORE_DISPATCH], true)
                ? $value
                : self::INVOICE_ON_PAYMENT;
        } catch (\Throwable $e) {
            return self::INVOICE_ON_PAYMENT;
        }
    }

    public static function isProviderEnabled(string $provider): bool
    {
        try {
            return ModuleRegistry::instance()->isEnabled(self::moduleId($provider));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Shared refund→correction policy (W4): `off` (default) or `storno_on_full_refund`. Read by the
     * Core linker through the `billing` scope; the accessor keeps the UI/save path in one place.
     */
    public static function refundCorrectionPolicy(): string
    {
        try {
            $value = (string) (new ModuleSettings())->get(self::SHARED_SCOPE, 'refund_correction_policy', 'off');
            return in_array($value, ['off', 'storno_on_full_refund'], true) ? $value : 'off';
        } catch (\Throwable $e) {
            return 'off';
        }
    }

    /** Provider already bound to this (order, doc_role) by an active automatic document row. */
    public static function boundProvider(int $orderId, string $docRole = 'invoice'): ?string
    {
        try {
            $row = (new InvoiceDocumentRepository())->findActiveAuto($orderId, $docRole);
            if ($row === null) {
                return null;
            }
            $provider = (string) ($row->provider ?? '');
            return in_array($provider, self::providers(), true) ? $provider : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
