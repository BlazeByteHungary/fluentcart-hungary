<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

/**
 * PURE Számlázz.hu Agent invoice XML builder.
 *
 * Builds the `xmlszamla` request exactly in the element order required by the published XSD
 * (04_SZAMLAZZ_HU_CONTRACT.md §2.2 auth inside `<beallitasok>`, §4.2 item/tax mapping, §4.4 dates,
 * §4.5 HUF rounding, §4.6 VAT codes verbatim; local mirror
 * DOCS/SZAMLAZZ_HU/SZAMLAZZ_API_DOKS_HU.md#minta-xml--xsd-séma, XSD order at lines 2234-2243).
 *
 * No WordPress, no FluentCart, no provider call: this class only turns an already-collected snapshot
 * into XML and computes integer minor-unit amounts. It is unit-testable in isolation.
 *
 * Money rule (constraints §2 / ADR-007): all money is integer minor units; no float arithmetic is
 * performed on amounts. Quantity/percent are parsed as exact decimal strings into integer ratios.
 */
final class SzamlazzXmlBuilder
{
    public const NS = 'http://www.szamlazz.hu/xmlszamla';
    public const XSD = 'https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd';
    public const DEFAULT_UNIT = 'db';
    public const CANONICAL_VERSION = 1;
    public const ORDER_REF_PREFIX = 'FCH-';

    /** Numeric rates (04 SZAMLAZZ §4.6) plus named VAT codes, sent verbatim in `<afakulcs>`. */
    public const VAT_CODES = [
        '0', '5', '7', '12', '18', '19', '20', '21', '25', '25.5', '27',
        'TAHK', 'TAM', 'AAM', 'EUT', 'EUKT', 'F.AFA', 'K.AFA', 'HO',
        'EUE', 'EUFADE', 'EUFAD37', 'ATK', 'NAM', 'EAM', 'KBAUK', 'KBAET',
    ];

    /**
     * @param array<string,mixed> $snapshot see shape in the module docs; keys:
     *   seller{agent_key,eszamla,szamla_letoltes,valasz_verzio,szamla_kulso_azon,bank,bankszamlaszam,
     *          email_replyto,email_targy,email_szoveg,szamlaszam_elotag,szamla_nyelve},
     *   header{kelt_datum,teljesites_datum,fizetesi_hatarido_datum,fizmod,penznem,megjegyzes,
     *          rendeles_szam,arfolyam_bank,arfolyam,doc_flags},
     *   buyer{nev,orszag,irsz,telepules,cim,email,send_email,adoszam,adoszamEU,adoalany,
     *         postazasi_nev,megjegyzes},
     *   items[]{name,quantity,unit,unit_net_minor,unit_gross_minor,vat_percent,vat_code,note},
     *   amount_basis ('net'|'gross'), currency
     * @throws \InvalidArgumentException on a structurally incomplete snapshot (never a silent partial XML).
     */
    public static function build(array $snapshot): string
    {
        $seller = self::requireArray($snapshot, 'seller');
        $header = self::requireArray($snapshot, 'header');
        $buyer = self::requireArray($snapshot, 'buyer');
        $items = $snapshot['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Snapshot must contain at least one item.');
        }

        $currency = strtoupper((string) ($snapshot['currency'] ?? $header['penznem'] ?? 'HUF'));
        $basis = self::normalizeBasis((string) ($snapshot['amount_basis'] ?? 'gross'));
        $rendeles = (string) ($header['rendeles_szam'] ?? '');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<xmlszamla xmlns="' . self::NS . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="' . self::NS . ' ' . self::XSD . '">' . "\n";

        // --- <beallitasok> (agent key is embedded here; never logged) -----------------------------
        $xml .= "  <beallitasok>\n";
        $xml .= self::tag('szamlaagentkulcs', (string) ($seller['agent_key'] ?? ''), 4);
        $xml .= self::tag('eszamla', self::bool($seller['eszamla'] ?? false), 4);
        $xml .= self::tag('szamlaLetoltes', self::bool($seller['szamla_letoltes'] ?? false), 4);
        $xml .= self::tag('valaszVerzio', (string) ($seller['valasz_verzio'] ?? 2), 4);
        if ((string) ($seller['szamla_kulso_azon'] ?? '') !== '') {
            $xml .= self::tag('szamlaKulsoAzon', (string) $seller['szamla_kulso_azon'], 4);
        }
        $xml .= "  </beallitasok>\n";

        // --- <fejlec> ---------------------------------------------------------------------------
        $flags = is_array($header['doc_flags'] ?? null) ? $header['doc_flags'] : [];
        $xml .= "  <fejlec>\n";
        $xml .= self::tag('keltDatum', (string) ($header['kelt_datum'] ?? ''), 4);
        $xml .= self::tag('teljesitesDatum', (string) ($header['teljesites_datum'] ?? ''), 4);
        $xml .= self::tag('fizetesiHataridoDatum', (string) ($header['fizetesi_hatarido_datum'] ?? ''), 4);
        $xml .= self::tag('fizmod', (string) ($header['fizmod'] ?? 'Átutalás'), 4);
        $xml .= self::tag('penznem', $currency, 4);
        $xml .= self::tag('szamlaNyelve', (string) ($header['szamla_nyelve'] ?? 'hu'), 4);
        $xml .= self::tag('megjegyzes', (string) ($header['megjegyzes'] ?? ''), 4);
        $xml .= self::tag('arfolyamBank', (string) ($header['arfolyam_bank'] ?? ''), 4);
        $xml .= self::tag('arfolyam', (string) ($header['arfolyam'] ?? ''), 4);
        $xml .= self::tag('rendelesSzam', $rendeles, 4);
        $xml .= self::tag('dijbekeroSzamlaszam', (string) ($flags['dijbekeroSzamlaszam'] ?? ''), 4);
        $xml .= self::tag('elolegszamla', self::bool($flags['elolegszamla'] ?? false), 4);
        $xml .= self::tag('vegszamla', self::bool($flags['vegszamla'] ?? false), 4);
        $xml .= self::tag('helyesbitoszamla', self::bool($flags['helyesbitoszamla'] ?? false), 4);
        $xml .= self::tag('helyesbitettSzamlaszam', (string) ($flags['helyesbitettSzamlaszam'] ?? ''), 4);
        $xml .= self::tag('dijbekero', self::bool($flags['dijbekero'] ?? false), 4);
        $xml .= self::tag('szamlaszamElotag', (string) ($seller['szamlaszam_elotag'] ?? ''), 4);
        $xml .= self::tag('simpleItems', self::bool($flags['simpleItems'] ?? false), 4);
        $xml .= "  </fejlec>\n";

        // --- <elado> (seller tax data lives in the Számlázz account; only bank/e-mail fields here) --
        $xml .= "  <elado>\n";
        $xml .= self::tag('bank', (string) ($seller['bank'] ?? ''), 4);
        $xml .= self::tag('bankszamlaszam', (string) ($seller['bankszamlaszam'] ?? ''), 4);
        $xml .= self::tag('emailReplyto', (string) ($seller['email_replyto'] ?? ''), 4);
        $xml .= self::tag('emailTargy', (string) ($seller['email_targy'] ?? ''), 4);
        $xml .= self::tag('emailSzoveg', (string) ($seller['email_szoveg'] ?? ''), 4);
        $xml .= "  </elado>\n";

        // --- <vevo> (B2C minimal name/zip/city/street; B2B adds adoszam/adoszamEU) ------------------
        $xml .= "  <vevo>\n";
        $xml .= self::tag('nev', (string) ($buyer['nev'] ?? ''), 4);
        $xml .= self::tag('orszag', (string) ($buyer['orszag'] ?? ''), 4);
        $xml .= self::tag('irsz', (string) ($buyer['irsz'] ?? ''), 4);
        $xml .= self::tag('telepules', (string) ($buyer['telepules'] ?? ''), 4);
        $xml .= self::tag('cim', (string) ($buyer['cim'] ?? ''), 4);
        $xml .= self::tag('email', (string) ($buyer['email'] ?? ''), 4);
        $xml .= self::tag('sendEmail', self::bool($buyer['send_email'] ?? false), 4);
        if (array_key_exists('adoalany', $buyer) && $buyer['adoalany'] !== null && $buyer['adoalany'] !== '') {
            $xml .= self::tag('adoalany', (string) $buyer['adoalany'], 4);
        }
        if ((string) ($buyer['adoszam'] ?? '') !== '') {
            $xml .= self::tag('adoszam', (string) $buyer['adoszam'], 4);
        }
        if ((string) ($buyer['adoszamEU'] ?? '') !== '') {
            $xml .= self::tag('adoszamEU', (string) $buyer['adoszamEU'], 4);
        }
        if ((string) ($buyer['postazasi_nev'] ?? '') !== '') {
            $xml .= self::tag('postazasiNev', (string) $buyer['postazasi_nev'], 4);
        }
        if ((string) ($buyer['megjegyzes'] ?? '') !== '') {
            $xml .= self::tag('megjegyzes', (string) $buyer['megjegyzes'], 4);
        }
        $xml .= "  </vevo>\n";

        // Order in XSD: fuvarlevel optional, before tetelek (XSD lines 2242-2243).
        $xml .= "  <fuvarlevel>\n";
        $xml .= self::tag('uticel', '', 4);
        $xml .= self::tag('futarSzolgalat', '', 4);
        $xml .= "  </fuvarlevel>\n";

        // --- <tetelek> (no discount/shipping fields exist; caller passes them as signed line items) -
        $xml .= "  <tetelek>\n";
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException(sprintf('Item #%d is not an array.', $index));
            }
            $amounts = self::computeItemAmounts($item, $basis, $currency);
            $xml .= "    <tetel>\n";
            $xml .= self::tag('megnevezes', (string) ($item['name'] ?? ''), 6);
            $xml .= self::tag('mennyiseg', self::qtyString($item['quantity'] ?? 1), 6);
            $xml .= self::tag('mennyisegiEgyseg', (string) ($item['unit'] ?? self::DEFAULT_UNIT), 6);
            $xml .= self::tag('nettoEgysegar', $amounts['unit_net'], 6);
            $xml .= self::tag('afakulcs', $amounts['vat_code'], 6);
            $xml .= self::tag('nettoErtek', (string) $amounts['net'], 6);
            $xml .= self::tag('afaErtek', (string) $amounts['vat'], 6);
            $xml .= self::tag('bruttoErtek', (string) $amounts['gross'], 6);
            if ((string) ($item['note'] ?? '') !== '') {
                $xml .= self::tag('megjegyzes', (string) $item['note'], 6);
            }
            $xml .= "    </tetel>\n";
        }
        $xml .= "  </tetelek>\n";

        $xml .= "</xmlszamla>\n";

        return $xml;
    }

    /**
     * Compute integer minor-unit net/VAT/gross for one line item per 04_SZAMLAZZ §4.5.
     *
     * net-based (B2B):   net = round(unit_net × qty); vat = round(net × pct/100); gross = net + vat
     * gross-based (B2C): gross = round(unit_gross × qty); vat = round(gross × pct/(100+pct)); net = gross - vat
     *
     * @param array<string,mixed> $item
     * @return array{net:int,vat:int,gross:int,unit_net:string,vat_code:string}
     */
    public static function computeItemAmounts(array $item, string $basis, string $currency): array
    {
        $basis = self::normalizeBasis($basis);
        [$vatCode, $percent] = self::vat($item);

        // Preferred path: the caller already holds the order-exact integer net/VAT/gross (FluentCart
        // line_total/tax_amount). We preserve those exact values and only derive the display unit price,
        // so the invoice totals match the order (04 SZAMLAZZ §4.2 consistency checks).
        if (isset($item['net_minor'], $item['vat_minor'], $item['gross_minor'])) {
            $net = (int) $item['net_minor'];
            $vat = (int) $item['vat_minor'];
            $gross = (int) $item['gross_minor'];
            if ($net + $vat !== $gross) {
                throw new \LogicException('Explicit item net + VAT must equal gross.');
            }
            $unitNetString = array_key_exists('unit_net_minor', $item) && $item['unit_net_minor'] !== null
                ? (string) (int) $item['unit_net_minor']
                : self::decimalDiv($net, $item['quantity'] ?? 1, 2);
            return ['net' => $net, 'vat' => $vat, 'gross' => $gross, 'unit_net' => $unitNetString, 'vat_code' => $vatCode];
        }

        if ($basis === 'net') {
            if (!array_key_exists('unit_net_minor', $item) || $item['unit_net_minor'] === null) {
                throw new \InvalidArgumentException('net-based item requires unit_net_minor.');
            }
            $unitNet = (int) $item['unit_net_minor'];
            $net = self::mulQty($unitNet, $item['quantity'] ?? 1);
            $vat = self::applyPercent($net, $percent);
            $gross = $net + $vat;
            $unitNetString = (string) $unitNet;
        } else {
            if (!array_key_exists('unit_gross_minor', $item) || $item['unit_gross_minor'] === null) {
                throw new \InvalidArgumentException('gross-based item requires unit_gross_minor.');
            }
            $unitGross = (int) $item['unit_gross_minor'];
            $gross = self::mulQty($unitGross, $item['quantity'] ?? 1);
            $vat = self::applyPercentGross($gross, $percent);
            $net = $gross - $vat;
            // Provider's own gross-based sample prints the derived net unit to 2 decimals (§4.5 example).
            $unitNetString = self::decimalDiv($net, $item['quantity'] ?? 1, 2);
        }

        // HUF item net/VAT/gross must be integers (04 SZAMLAZZ §4.5). Integer minor units guarantee this.
        if (self::exponent($currency) === 0) {
            foreach ([$net, $vat, $gross] as $amount) {
                if (!is_int($amount)) {
                    throw new \InvalidArgumentException('HUF item amounts must be integers.');
                }
            }
        }
        if ($net + $vat !== $gross) {
            throw new \LogicException('Item net + VAT must equal gross.');
        }

        return ['net' => $net, 'vat' => $vat, 'gross' => $gross, 'unit_net' => $unitNetString, 'vat_code' => $vatCode];
    }

    /**
     * Deterministic canonical financial snapshot + SHA-256 (05_DATA_SCHEMA §3.6). All amounts are integer
     * minor units; item order is preserved so the same order always hashes the same.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function hashSnapshot(array $snapshot): string
    {
        $canonical = self::canonicalSnapshot($snapshot);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode canonical snapshot.');
        }
        return hash('sha256', $json);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function canonicalSnapshot(array $snapshot): array
    {
        $fin = is_array($snapshot['financials'] ?? null) ? $snapshot['financials'] : [];
        $lines = [];
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $vatCode = (string) ($item['vat_code'] ?? '');
            $lines[] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => self::qtyString($item['quantity'] ?? 1),
                'vat_code' => $vatCode,
                'net_minor' => (int) ($item['net_minor'] ?? 0),
                'vat_minor' => (int) ($item['vat_minor'] ?? 0),
                'gross_minor' => (int) ($item['gross_minor'] ?? 0),
            ];
        }

        $string = static fn(array $source, string $key): string => (string) ($source[$key] ?? '');
        $int = static fn(array $source, string $key): int => (int) ($source[$key] ?? 0);

        return [
            'v' => self::CANONICAL_VERSION,
            'order_id' => $string($snapshot, 'order_id'),
            'currency' => strtoupper($string($snapshot, 'currency')),
            'subtotal' => $int($fin, 'subtotal'),
            'discount_tax' => $int($fin, 'discount_tax'),
            'manual_discount_total' => $int($fin, 'manual_discount_total'),
            'coupon_discount_total' => $int($fin, 'coupon_discount_total'),
            'shipping_tax' => $int($fin, 'shipping_tax'),
            'shipping_total' => $int($fin, 'shipping_total'),
            'fee_total' => $int($fin, 'fee_total'),
            'tax_total' => $int($fin, 'tax_total'),
            'total_amount' => $int($fin, 'total_amount'),
            'tax_behavior' => $int($fin, 'tax_behavior'),
            'items' => $lines,
        ];
    }

    /** Stable business order reference used for `rendelesSzam` (contract §5 / §6 reconciliation). */
    public static function orderReference(int|string $orderId): string
    {
        return self::ORDER_REF_PREFIX . preg_replace('/[^0-9A-Za-z_-]/', '', (string) $orderId);
    }

    /**
     * Exact decimal multiplication by a quantity string using integer math only.
     * @param string|int|float $quantity
     */
    public static function mulQty(int $perUnitMinor, string|int|float $quantity): int
    {
        [$num, $den] = self::ratio($quantity);
        return self::divRound($perUnitMinor * $num, $den);
    }

    /** Exact `base × percent/100` with half-away-from-zero rounding (integer math only). */
    public static function applyPercent(int $baseMinor, $percent): int
    {
        [$num, $den] = self::ratio($percent);
        return self::divRound($baseMinor * $num, 100 * $den);
    }

    /** Exact `base × percent/(100+percent)` used by the gross-based method (§4.5). */
    public static function applyPercentGross(int $grossMinor, $percent): int
    {
        [$num, $den] = self::ratio($percent);
        return self::divRound($grossMinor * $num, $den * 100 + $num);
    }

    /** Truncating integer division rendered with `$scale` decimals (e.g. net-unit display price). */
    public static function decimalDiv(int $amountMinor, string|int|float $quantity, int $scale = 2): string
    {
        [$num, $den] = self::ratio($quantity);
        if ($num === 0) {
            throw new \InvalidArgumentException('Quantity must not be zero.');
        }
        $scale = max(0, $scale);
        $factor = 1;
        for ($i = 0; $i < $scale; $i++) {
            $factor *= 10;
        }
        // value = amount / (num/den) = amount*den/num, scaled by factor.
        $scaled = intdiv($amountMinor * $den * $factor, $num);
        if ($scale === 0) {
            return (string) $scaled;
        }
        $sign = $scaled < 0 ? '-' : '';
        $abs = $scaled < 0 ? -$scaled : $scaled;
        $whole = intdiv($abs, $factor);
        $frac = $abs % $factor;
        return $sign . $whole . '.' . str_pad((string) $frac, $scale, '0', STR_PAD_LEFT);
    }

    /** @return array{0:string,1:string} [vatCode, percentAsString] */
    private static function vat(array $item): array
    {
        $code = $item['vat_code'] ?? null;
        if ($code === null && isset($item['vat_percent'])) {
            $code = self::normalizePercent($item['vat_percent']);
        }
        $code = trim((string) $code);
        if ($code === '') {
            $code = '0';
        }
        if (!in_array($code, self::VAT_CODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported VAT code "%s" (04 SZAMLAZZ §4.6).', $code));
        }
        // Only numeric codes carry a rate; named codes (AAM/TAM/EUT…) are zero-rated here and sent verbatim.
        $percent = is_numeric($code) ? $code : '0';
        return [$code, $percent];
    }

    private static function normalizePercent($percent): string
    {
        if (is_int($percent)) {
            return (string) $percent;
        }
        $value = trim((string) $percent);
        if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException(sprintf('Malformed VAT percent "%s".', $value));
        }
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    /**
     * Parse an int|float|string quantity into an exact rational [numerator, denominator].
     * Floats are only accepted as *quantity* (not money) and are stringified deterministically.
     *
     * @return array{0:int,1:int}
     */
    private static function ratio(string|int|float $value): array
    {
        if (is_int($value)) {
            return [$value, 1];
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
            if ($value === '' || $value === '-0') {
                $value = '0';
            }
        }
        $value = trim((string) $value);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException(sprintf('Malformed decimal "%s".', $value));
        }
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
        $parts = explode('.', $value, 2);
        $whole = $parts[0];
        $frac = $parts[1] ?? '';
        $den = 1;
        for ($i = 0, $n = strlen($frac); $i < $n; $i++) {
            $den *= 10;
        }
        $num = (int) ($whole . $frac);
        return [$negative ? -$num : $num, $den];
    }

    /** Integer division rounded half away from zero. */
    private static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new \InvalidArgumentException('Division by zero.');
        }
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);
        $quotient = intdiv($numerator * 2 + $denominator, $denominator * 2);
        return $sign * $quotient;
    }

    private static function qtyString($quantity): string
    {
        if (is_int($quantity)) {
            return (string) $quantity;
        }
        if (is_float($quantity)) {
            $string = rtrim(rtrim(sprintf('%.4F', $quantity), '0'), '.');
            return $string === '' ? '0' : $string;
        }
        return (string) $quantity;
    }

    private static function exponent(string $currency): int
    {
        $known = ['HUF' => 0, 'EUR' => 2, 'USD' => 2, 'CZK' => 2, 'RON' => 2, 'PLN' => 2, 'GBP' => 2];
        $currency = strtoupper(trim($currency));
        if (!array_key_exists($currency, $known)) {
            throw new \InvalidArgumentException(sprintf('Unknown currency exponent for "%s".', $currency));
        }
        return $known[$currency];
    }

    private static function normalizeBasis(string $basis): string
    {
        $basis = strtolower(trim($basis));
        if (!in_array($basis, ['net', 'gross'], true)) {
            throw new \InvalidArgumentException('amount_basis must be "net" or "gross".');
        }
        return $basis;
    }

    private static function bool(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    private static function tag(string $name, string $value, int $indent): string
    {
        return str_repeat(' ', $indent) . '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function requireArray(array $source, string $key): array
    {
        if (!isset($source[$key]) || !is_array($source[$key])) {
            throw new \InvalidArgumentException(sprintf('Snapshot is missing "%s".', $key));
        }
        return $source[$key];
    }
}
