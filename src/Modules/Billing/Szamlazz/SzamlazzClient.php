<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;

/**
 * Számlázz.hu Számla Agent HTTP client (04_SZAMLAZZ_HU_CONTRACT.md §2 auth, §3.3 operations, §5 field
 * names, §6 timeout). Transport is always HTTPS POST multipart/form-data to the single endpoint
 * `https://www.szamlazz.hu/szamla/`; test vs live is a property of the agent key, not the host (§2.1).
 *
 * The agent key is embedded in each request XML by the caller/builder and is never logged, never
 * returned, and never placed into a ProviderCallResult payload. All traffic goes through BaseClient so
 * the `fch/http/pre_request` mock seam applies; TLS verification is on (BaseClient) and timeouts explicit.
 */
final class SzamlazzClient extends BaseClient
{
    public const ENDPOINT = 'https://www.szamlazz.hu/szamla/';

    public const FIELD_CREATE_INVOICE = 'action-xmlagentxmlfile';
    public const FIELD_STORNO = 'action-szamla_agent_st';
    public const FIELD_DELETE_PROFORMA = 'action-szamla_agent_dijbekero_torlese';
    public const FIELD_PDF = 'action-szamla_agent_pdf';
    public const FIELD_XML = 'action-szamla_agent_xml';
    public const FIELD_TAXPAYER = 'action-szamla_agent_taxpayer';

    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 30;

    private string $agentKey;

    public function __construct(string $agentKey)
    {
        parent::__construct(self::ENDPOINT);
        $this->agentKey = $agentKey;
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);
    }

    public function hasCredentials(): bool
    {
        return trim($this->agentKey) !== '';
    }

    /** Create an invoice/proforma/advance/final/corrective/delivery note (04 SZAMLAZZ §3.3). */
    public function createInvoice(string $xml): Response
    {
        return $this->submit(self::FIELD_CREATE_INVOICE, $xml);
    }

    /**
     * Storno (void) an issued invoice: `action-szamla_agent_st` with the `xmlszamlast` XML
     * (04 SZAMLAZZ §3.3/§5; local mirror #számla-sztornó). `szamlaszam` is mandatory; the optional
     * `szamlaKulsoAzon` makes the storno itself findable by OUR external key for read-back.
     *
     * @param array{szamla_kulso_azon?:string,kelt_datum?:string,megjegyzes?:string,eszamla?:bool} $opts
     */
    public function stornoInvoice(string $invoiceNumber, array $opts = []): Response
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<xmlszamlast xmlns="http://www.szamlazz.hu/xmlszamlast" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.szamlazz.hu/xmlszamlast https://www.szamlazz.hu/szamla/docs/xsds/agentst/xmlszamlast.xsd">' . "\n";
        $xml .= "  <beallitasok>\n";
        $xml .= '    <szamlaagentkulcs>' . self::esc($this->agentKey) . "</szamlaagentkulcs>\n";
        $xml .= '    <eszamla>' . (!empty($opts['eszamla']) ? 'true' : 'false') . "</eszamla>\n";
        $xml .= "    <szamlaLetoltes>false</szamlaLetoltes>\n";
        $xml .= "    <valaszVerzio>2</valaszVerzio>\n";
        $externalKey = trim((string) ($opts['szamla_kulso_azon'] ?? ''));
        if ($externalKey !== '') {
            $xml .= '    <szamlaKulsoAzon>' . self::esc($externalKey) . "</szamlaKulsoAzon>\n";
        }
        $xml .= "  </beallitasok>\n";
        $xml .= "  <fejlec>\n";
        $xml .= '    <szamlaszam>' . self::esc($invoiceNumber) . "</szamlaszam>\n";
        $kelt = trim((string) ($opts['kelt_datum'] ?? ''));
        if ($kelt !== '') {
            $xml .= '    <keltDatum>' . self::esc($kelt) . "</keltDatum>\n";
        }
        $reason = trim((string) ($opts['megjegyzes'] ?? ''));
        if ($reason !== '') {
            $xml .= '    <megjegyzes>' . self::esc($reason) . "</megjegyzes>\n";
        }
        $xml .= "  </fejlec>\n";
        $xml .= "</xmlszamlast>\n";
        return $this->submit(self::FIELD_STORNO, $xml);
    }

    /**
     * Delete a proforma (díjbekérő): `action-szamla_agent_dijbekero_torlese` with `xmlszamladbkdel`
     * (04 SZAMLAZZ §3.3; local mirror #díjbekérő-törlése). Identify by `szamlaszam` or `rendelesszam`.
     */
    public function deleteProforma(string $documentNumber, string $orderNumber = ''): Response
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<xmlszamladbkdel xmlns="http://www.szamlazz.hu/xmlszamladbkdel" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.szamlazz.hu/xmlszamladbkdel http://www.szamlazz.hu/docs/xsds/szamladbkdel/xmlszamladbkdel.xsd">' . "\n";
        $xml .= "  <beallitasok>\n";
        $xml .= '    <szamlaagentkulcs>' . self::esc($this->agentKey) . "</szamlaagentkulcs>\n";
        $xml .= "  </beallitasok>\n";
        $xml .= "  <fejlec>\n";
        if (trim($documentNumber) !== '') {
            $xml .= '    <szamlaszam>' . self::esc($documentNumber) . "</szamlaszam>\n";
        } elseif (trim($orderNumber) !== '') {
            $xml .= '    <rendelesszam>' . self::esc($orderNumber) . "</rendelesszam>\n";
        }
        $xml .= "  </fejlec>\n";
        $xml .= "</xmlszamladbkdel>\n";
        return $this->submit(self::FIELD_DELETE_PROFORMA, $xml);
    }

    /**
     * Read back the issued invoice PDF (invariant §1.6: a read; never creates a document).
     * valaszVerzio=2 => structured XML with base64 `<pdf>`; accepts a raw PDF body too (§5 Query PDF).
     *
     * @param array{szamla_kulso_azon?:string} $opts
     */
    public function downloadInvoicePdf(string $documentNumber, array $opts = []): Response
    {
        $xml = $this->simpleXml('xmlszamlapdf', [
            'szamlaszam' => $documentNumber,
            'valaszVerzio' => '2',
        ], [
            'szamlaKulsoAzon' => (string) ($opts['szamla_kulso_azon'] ?? ''),
        ]);
        return $this->submit(self::FIELD_PDF, $xml);
    }

    /**
     * Query an issued document by szamlaszam / rendelesSzam / szamlaKulsoAzon (04 SZAMLAZZ §5, §6).
     * Response: the full `szamla` XML document, or an error document with code 7 when absent.
     *
     * @param array{szamlaszam?:string,rendeles_szam?:string,szamla_kulso_azon?:string,pdf?:bool} $opts
     */
    public function queryInvoice(array $opts = []): Response
    {
        $xml = $this->simpleXml('xmlszamlaxml', [
            'szamlaszam' => (string) ($opts['szamlaszam'] ?? ''),
            'rendelesSzam' => (string) ($opts['rendeles_szam'] ?? ''),
            'pdf' => !empty($opts['pdf']) ? 'true' : 'false',
            'szamlaKulsoAzon' => (string) ($opts['szamla_kulso_azon'] ?? ''),
        ]);
        return $this->submit(self::FIELD_XML, $xml);
    }

    /** NAV taxpayer lookup (04 SZAMLAZZ §5 taxpayer). */
    public function lookupTaxpayer(string $torzsszam): Response
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<xmltaxpayer xmlns="http://www.szamlazz.hu/xmltaxpayer"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . "  <beallitasok>\n"
            . '    <szamlaagentkulcs>' . self::esc($this->agentKey) . "</szamlaagentkulcs>\n"
            . "  </beallitasok>\n"
            . '  <torzsszam>' . self::esc($torzsszam) . "</torzsszam>\n"
            . "</xmltaxpayer>\n";
        return $this->submit(self::FIELD_TAXPAYER, $xml);
    }

    /**
     * Best-effort PDF extraction from a response (raw `%PDF` body or base64 `<pdf>` element).
     * Returns binary PDF bytes or null when the response carries no PDF.
     */
    public static function extractPdf(Response $response): ?string
    {
        $body = $response->body;
        if ($body === '') {
            return null;
        }
        if (str_starts_with(ltrim($body, "\xEF\xBB\xBF \t\r\n"), '%PDF')) {
            return $body;
        }
        if (!str_contains($body, '<')) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $nodes = $dom->getElementsByTagName('pdf');
        if ($nodes->length === 0) {
            return null;
        }
        $decoded = base64_decode(trim((string) $nodes->item(0)->textContent), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<string,string> $required ordered child tags (always present)
     * @param array<string,string> $optional optional child tags (omitted when empty)
     */
    private function simpleXml(string $root, array $required, array $optional = []): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<' . $root . ' xmlns="http://www.szamlazz.hu/' . $root . '"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= '  <szamlaagentkulcs>' . self::esc($this->agentKey) . "</szamlaagentkulcs>\n";
        // <beallitasok> vs flat: xmlszamlapdf/taxpayer nest under a settings block; keep the documented order.
        if ($root === 'xmlszamlapdf') {
            // XSD order: szamlaszam, valaszVerzio, rendelesSzam, szamlaKulsoAzon (all flat, after the key).
            foreach ($required as $tag => $value) {
                $xml .= '  <' . $tag . '>' . self::esc($value) . '</' . $tag . ">\n";
            }
            foreach ($optional as $tag => $value) {
                if ($value !== '') {
                    $xml .= '  <' . $tag . '>' . self::esc($value) . '</' . $tag . ">\n";
                }
            }
        } else {
            foreach ($required as $tag => $value) {
                if ($value !== '') {
                    $xml .= '  <' . $tag . '>' . self::esc($value) . '</' . $tag . ">\n";
                }
            }
            foreach ($optional as $tag => $value) {
                if ($value !== '') {
                    $xml .= '  <' . $tag . '>' . self::esc($value) . '</' . $tag . ">\n";
                }
            }
        }
        $xml .= '</' . $root . ">\n";
        return $xml;
    }

    /** multipart/form-data POST with the XML as a *file* part (required — error 53 if sent as a field). */
    private function submit(string $field, string $xml): Response
    {
        $boundary = '----fchSzamlazz' . bin2hex(random_bytes(12));
        $safeField = preg_replace('/[^a-z0-9_\-]/i', '', $field);
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $safeField . '"; filename="szamla.xml"' . "\r\n"
            . "Content-Type: text/xml; charset=UTF-8\r\n\r\n"
            . $xml . "\r\n"
            . '--' . $boundary . "--\r\n";

        return $this->request('POST', self::ENDPOINT, [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Accept' => 'application/xml, application/pdf, text/plain, */*',
            ],
        ]);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
