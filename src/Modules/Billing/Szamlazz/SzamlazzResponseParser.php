<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

/**
 * PURE Számlázz.hu Agent response parser.
 *
 * Handles the three documented response shapes (04_SZAMLAZZ_HU_CONTRACT.md §3.4, §5; local mirror
 * DOCS/SZAMLAZZ_HU/SZAMLAZZ_API_DOKS_HU.md#válasz, #számla-készítésre-kapott-válaszok):
 *   - `xmlszamlavalasz` XML (valaszVerzio=2): sikeres/szamlaszam/szamlanetto/szamlabrutto/…/pdf
 *   - a full `szamla` XML document (query by rendelesSzam/szamlaKulsoAzon — response of action-szamla_agent_xml)
 *   - raw PDF body (valaszVerzio=1 with szamlaLetoltes=true), plus the v1 `xmlagentresponse=DONE` / `[ERR]` text forms
 *
 * Unknown structures are reported as a local error (ok=false, local=true), never treated as a provider
 * rejection: only an explicit provider error code (e.g. 71/152/202/491) is a definitive rejection.
 * No WordPress dependency: unit-testable in isolation.
 */
final class SzamlazzResponseParser
{
    /**
     * Provider error codes that are definitive rejections (04 SZAMLAZZ §5 table + local mirror
     * #hibakódok-és-üzenetek lines 224-256). Transient codes are listed separately below.
     */
    public const REJECTION_CODES = [
        '3', '7', '53', '54', '55', '57', '71', '152', '164', '202',
        '259', '260', '261', '262', '263', '264',
        '335', '336', '337', '338', '339', '340',
        '363', '364', '365',
        '491', '537', '538', '539', '551', '552', '553', '554', '555', '556',
    ];

    /** Transient/agent-side codes: not a business rejection, do not mark PROVEN_REJECTED. */
    public const TRANSIENT_CODES = ['1', '135', '136'];

    /**
     * @return array{
     *   ok:bool, invoice_number:?string, external_ref2:?string, document_type:?string,
     *   error_code:?string, error_message:?string,
     *   structure:string, local:bool, definitive:bool, is_pdf:bool, pdf_base64:?string,
     *   net:?string, gross:?string, outstanding:?string, buyer_account_url:?string
     * }
     */
    public static function parse(string $body, array $headers = []): array
    {
        $result = [
            'ok' => false,
            'invoice_number' => null,
            'external_ref2' => null,
            'document_type' => null,
            'error_code' => null,
            'error_message' => null,
            'structure' => 'unknown',
            'local' => false,
            'definitive' => false,
            'is_pdf' => false,
            'pdf_base64' => null,
            'net' => null,
            'gross' => null,
            'outstanding' => null,
            'buyer_account_url' => null,
        ];

        $trimmed = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        if ($trimmed === '') {
            // Legacy plugin reads the invoice number from the szlahu_szamlaszam header on some responses.
            $headerNumber = self::headerValue($headers, 'szlahu_szamlaszam');
            $headerError = self::headerValue($headers, 'szlahu_error_code');
            if ($headerNumber !== null && $headerNumber !== '') {
                $result['ok'] = true;
                $result['invoice_number'] = $headerNumber;
                $result['structure'] = 'headers';
                return $result;
            }
            if ($headerError !== null && $headerError !== '') {
                return self::rejection($result, $headerError, self::headerValue($headers, 'szlahu_error') ?? '');
            }
            $result['error_code'] = 'EMPTY_RESPONSE';
            $result['error_message'] = 'Empty response body.';
            $result['structure'] = 'empty';
            $result['local'] = true;
            return $result;
        }

        if (str_starts_with($trimmed, '%PDF')) {
            $result['ok'] = true;
            $result['is_pdf'] = true;
            $result['pdf_base64'] = base64_encode($body);
            $result['structure'] = 'pdf';
            return $result;
        }

        if (str_contains($trimmed, '<')) {
            return self::parseXml($result, $trimmed);
        }

        if (str_contains($trimmed, 'xmlagentresponse=DONE')) {
            $result['ok'] = true;
            $result['structure'] = 'text';
            $after = substr($trimmed, strpos($trimmed, 'xmlagentresponse=DONE') + strlen('xmlagentresponse=DONE'));
            $after = ltrim($after, "; \t\r\n");
            if ($after !== '' && preg_match('/^([^\s;]+)/', $after, $m)) {
                $result['invoice_number'] = $m[1];
            }
            return $result;
        }

        if (str_starts_with($trimmed, '[ERR]')) {
            $result['error_code'] = 'TEXT_ERROR';
            $result['error_message'] = self::textError($trimmed);
            $result['structure'] = 'text';
            $result['definitive'] = true;
            return $result;
        }

        $result['error_code'] = 'UNKNOWN_RESPONSE';
        $result['error_message'] = 'Unrecognized Számlázz response structure.';
        $result['structure'] = 'unknown';
        $result['local'] = true;
        return $result;
    }

    private static function parseXml(array $result, string $xmlString): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xmlString);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $dom->documentElement === null) {
            $result['error_code'] = 'UNKNOWN_RESPONSE';
            $result['error_message'] = 'Response is not well-formed XML.';
            $result['structure'] = 'unknown';
            $result['local'] = true;
            return $result;
        }

        $rootName = $dom->documentElement->localName;

        if ($rootName === 'szamla') {
            // action-szamla_agent_xml success: the document exists (reconciliation "found").
            $result['ok'] = true;
            $result['structure'] = 'xml_szamla';
            $result['invoice_number'] = self::firstText($dom, 'szamlaszam');
            // Totals only from the document-level <osszegek> block; never fall back to a per-item <netto>.
            $result['net'] = self::firstText($dom, 'totalnetto');
            $result['gross'] = self::firstText($dom, 'totalbrutto');
            $pdf = self::firstText($dom, 'pdf');
            if ($pdf !== null && $pdf !== '') {
                $result['is_pdf'] = true;
                $result['pdf_base64'] = $pdf;
            }
            return $result;
        }

        if ($rootName === 'xmlszamladbkdelvalasz') {
            // Proforma delete response (04 SZAMLAZZ §3.3 #díjbekérő-törlése): sikeres + optional hibakod.
            $result['structure'] = 'xml_dbkdel';
            $sikeres = self::firstText($dom, 'sikeres');
            if ($sikeres === null) {
                $result['error_code'] = 'UNKNOWN_RESPONSE';
                $result['error_message'] = 'xmlszamladbkdelvalasz without <sikeres>.';
                $result['local'] = true;
                return $result;
            }
            if (in_array(strtolower(trim($sikeres)), ['true', '1'], true)) {
                $result['ok'] = true;
                return $result;
            }
            return self::rejection($result, self::firstText($dom, 'hibakod') ?? '', self::firstText($dom, 'hibauzenet') ?? '');
        }

        if ($rootName !== 'xmlszamlavalasz') {
            $result['error_code'] = 'UNKNOWN_RESPONSE';
            $result['error_message'] = sprintf('Unexpected response root "%s".', $rootName);
            $result['structure'] = 'unknown';
            $result['local'] = true;
            return $result;
        }

        $result['structure'] = 'xml';
        $sikeres = self::firstText($dom, 'sikeres');
        if ($sikeres === null) {
            $result['error_code'] = 'UNKNOWN_RESPONSE';
            $result['error_message'] = 'xmlszamlavalasz without <sikeres>.';
            $result['local'] = true;
            return $result;
        }

        $pdf = self::firstText($dom, 'pdf');
        if ($pdf !== null && $pdf !== '') {
            $result['is_pdf'] = true;
            $result['pdf_base64'] = $pdf;
        }

        if (in_array(strtolower(trim($sikeres)), ['true', '1'], true)) {
            $result['ok'] = true;
            $result['invoice_number'] = self::firstText($dom, 'szamlaszam');
            $result['external_ref2'] = self::firstText($dom, 'szamlaKulsoAzon');
            $result['document_type'] = self::firstText($dom, 'tipus');
            $result['net'] = self::firstText($dom, 'szamlanetto');
            $result['gross'] = self::firstText($dom, 'szamlabrutto');
            $result['outstanding'] = self::firstText($dom, 'kintlevoseg');
            $result['buyer_account_url'] = self::firstText($dom, 'vevoifiokurl');
            return $result;
        }

        $code = self::firstText($dom, 'hibakod') ?? '';
        $message = self::firstText($dom, 'hibauzenet') ?? '';
        return self::rejection($result, $code, $message);
    }

    private static function rejection(array $result, string $code, string $message): array
    {
        $result['ok'] = false;
        $result['error_code'] = $code !== '' ? $code : 'UNKNOWN_ERROR';
        $result['error_message'] = $message;
        $result['definitive'] = self::isDefinitive($code);
        // An unknown *code* is still an explicit provider rejection; only an unknown *structure* is local.
        return $result;
    }

    public static function isDefinitive(string $code): bool
    {
        $code = trim($code);
        if (in_array($code, self::TRANSIENT_CODES, true)) {
            return false;
        }
        if (in_array($code, self::REJECTION_CODES, true)) {
            return true;
        }
        // Any other explicit hibakod from the provider is treated as a rejection (conservative).
        return $code !== '';
    }

    private static function textError(string $body): string
    {
        $start = strpos($body, '[ERR]');
        if ($start === false) {
            return $body;
        }
        $start += strlen('[ERR]');
        $end = strpos($body, '----------', $start);
        $message = $end === false ? substr($body, $start) : substr($body, $start, $end - $start);
        return trim($message);
    }

    private static function firstText(\DOMDocument $dom, string $name): ?string
    {
        $nodes = $dom->getElementsByTagName($name);
        if ($nodes->length === 0) {
            return null;
        }
        return (string) $nodes->item(0)->textContent;
    }

    private static function headerValue(array $headers, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        return null;
    }
}
