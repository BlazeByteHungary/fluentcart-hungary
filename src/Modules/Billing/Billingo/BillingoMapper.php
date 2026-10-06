<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

/**
 * Pure mapping from the immutable order snapshot to Billingo API v3 payloads
 * (04_BILLINGO_CONTRACT.md §4; API v3 doc item/partner/settings tables).
 *
 * Money rules:
 *  - HUF amounts are integers, other currencies two-decimals; all values derive from integer minor units.
 *  - `unit_price_type` follows the snapshot amount basis (net|gross); `unit_price` = line amount /
 *    quantity. When the division is exact (the common case) integer arithmetic is used; otherwise the
 *    provider rounds per line, so the service validates the returned `gross_total` against the order
 *    total and raises a loud, non-silent mismatch flag instead of accepting a drifted document.
 *  - Discounts are already reflected in line totals (same convention as the W1 Számlázz snapshot), so no
 *    document-level discount is sent (it would double-apply).
 */
final class BillingoMapper
{
    public static function hashSnapshot(array $snapshot): string
    {
        $canonical = function_exists('wp_json_encode') ? wp_json_encode($snapshot) : json_encode($snapshot);
        return hash('sha256', is_string($canonical) ? $canonical : serialize($snapshot));
    }

    /**
     * Partner find-or-create payload (B2C default; B2B fields from the FC business info).
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function partnerPayload(array $snapshot): array
    {
        $buyer = (array) ($snapshot['buyer'] ?? []);
        $address = [
            'country_code' => strtoupper((string) ($buyer['orszag'] ?? 'HU')),
            'post_code' => (string) ($buyer['irsz'] ?? ''),
            'city' => (string) ($buyer['telepules'] ?? ''),
            'address' => trim((string) ($buyer['cim'] ?? '')),
        ];
        $address2 = trim((string) ($buyer['cim2'] ?? ''));
        if ($address2 !== '') {
            $address['address2'] = $address2;
        }

        $partner = [
            'name' => (string) ($buyer['nev'] ?? 'Vevő'),
            'address' => $address,
            'emails' => array_values(array_filter([(string) ($buyer['email'] ?? '')])),
        ];
        $phone = trim((string) ($buyer['phone'] ?? ''));
        if ($phone !== '') {
            $partner['phone'] = $phone;
        }
        $taxcode = trim((string) ($buyer['adoszam'] ?? ''));
        if ($taxcode !== '') {
            $partner['taxcode'] = $taxcode;
            $partner['tax_type'] = 'HAS_TAX_NUMBER';
        } else {
            $partner['tax_type'] = 'NO_TAX_NUMBER';
        }
        $euTax = trim((string) ($buyer['adoszamEU'] ?? ''));
        if ($euTax !== '') {
            $partner['taxcode'] = $euTax;
            $partner['tax_type'] = 'FOREIGN';
        }
        return $partner;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $config   non-secret settings + resolved ids
     * @return array<string,mixed>
     */
    public static function documentPayload(array $snapshot, array $config, int $partnerId): array
    {
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'HUF'));
        $payload = [
            'vendor_id' => (string) ($snapshot['header']['vendor_id'] ?? ''),
            'partner_id' => $partnerId,
            'block_id' => (int) ($config['block_id'] ?? 0),
            'type' => (string) ($config['type'] ?? 'invoice'),
            'currency' => $currency,
            'conversion_rate' => (float) ($config['conversion_rate'] ?? 1.0),
            'language' => (string) ($config['language'] ?? 'hu'),
            'payment_method' => (string) ($config['payment_method'] ?? 'elore_utalas'),
            'fulfillment_date' => (string) ($snapshot['header']['teljesites_datum'] ?? ''),
            'due_date' => (string) ($snapshot['header']['fizetesi_hatarido_datum'] ?? ''),
            'items' => self::itemsPayload($snapshot, $config),
        ];

        $bankAccountId = (int) ($config['bank_account_id'] ?? 0);
        if ($bankAccountId > 0) {
            $payload['bank_account_id'] = $bankAccountId;
        }
        $comment = trim((string) ($config['comment'] ?? ''));
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }
        if (!empty($config['electronic'])) {
            $payload['electronic'] = true;
        }
        $round = (string) ($config['round'] ?? '');
        if (in_array($round, ['five', 'none', 'one', 'ten'], true)) {
            $payload['settings'] = ['round' => $round];
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $config
     * @return list<array<string,mixed>>
     */
    public static function itemsPayload(array $snapshot, array $config): array
    {
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'HUF'));
        $basis = (string) ($snapshot['amount_basis'] ?? 'net') === 'gross' ? 'gross' : 'net';
        $overrideVat = trim((string) ($config['vat_override'] ?? ''));
        $overrideEntitlement = trim((string) ($config['entitlement'] ?? ''));

        $items = [];
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineMinor = $basis === 'gross' ? (int) ($item['gross_minor'] ?? 0) : (int) ($item['net_minor'] ?? 0);
            $unitPrice = self::unitPrice($lineMinor, $quantity, $currency);
            if ($unitPrice === null) {
                // Inexact division: fall back to a single line carrying the exact total (loud, no drift).
                $item['name'] = (string) ($item['name'] ?? '') . ' × ' . $quantity;
                $quantity = 1;
                $unitPrice = BillingoStatus::amountForProvider($lineMinor, $currency);
            }

            $vatCode = $overrideVat !== '' ? $overrideVat : (string) ($item['vat_code'] ?? '0');
            $entry = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_price_type' => $basis,
                'unit' => (string) ($item['unit'] ?? 'db'),
                'vat' => $vatCode,
            ];
            $entitlement = (string) ($item['entitlement'] ?? $overrideEntitlement);
            if ($entitlement !== '') {
                $entry['entitlement'] = $entitlement;
            }
            $items[] = $entry;
        }
        return $items;
    }

    /**
     * Exact integer unit price when divisible, else two-decimals of the currency (float). Returns null
     * when the division cannot be represented exactly at the currency's precision.
     */
    private static function unitPrice(int $lineMinor, int $quantity, string $currency): int|float|null
    {
        if ($quantity <= 0 || $lineMinor % $quantity !== 0) {
            return null;
        }
        // Unit price in minor units must itself be representable at the currency precision.
        $unitMinor = intdiv($lineMinor, $quantity);
        $exp = \FluentCartHungary\Core\Money\Money::exponent($currency);
        $factor = 10 ** $exp;
        return $unitMinor % $factor === 0 ? intdiv($unitMinor, $factor) : $unitMinor / $factor;
    }

    /**
     * Total minor units of the snapshot items (net/vat/gross) — used for the response cross-check.
     *
     * @param array<string,mixed> $snapshot
     * @return array{net:int,vat:int,gross:int}
     */
    public static function totals(array $snapshot): array
    {
        $net = $vat = $gross = 0;
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            $net += (int) ($item['net_minor'] ?? 0);
            $vat += (int) ($item['vat_minor'] ?? 0);
            $gross += (int) ($item['gross_minor'] ?? 0);
        }
        return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
    }
}
