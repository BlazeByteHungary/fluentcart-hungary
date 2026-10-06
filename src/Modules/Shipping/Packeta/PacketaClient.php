<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Packeta REST/XML API client (04_PACKETA_CONTRACT.md §2.2, §4, §7).
 *
 * Transport: HTTP POST an XML body to `https://www.zasilkovna.cz/api/rest`; the root element is the
 * method name and each child element is one method argument (04 §2.2 "REST/XML"). `apiPassword` is the
 * first argument of EVERY method (04 §2.1) and is injected as the first child by {@see buildXml()}; it
 * is never placed in the base URI, a query string, or a persisted/logged payload.
 *
 * Packet IDs are ALWAYS strings: the documented 32-bit PHP WSDL replaces `unsignedLong` with `string`,
 * and callers must "always treat packet IDs as strings" (04 §2.2) — see {@see canonicalPacketId()}.
 *
 * SOAP is documented as identical in functionality but is OPTIONAL and not implemented here (04 §2.1);
 * the REST/XML interface covers the same method set. All transport goes through BaseClient so the
 * `fch/http/pre_request` mock seam works and no network is touched in tests. No secret and no raw
 * request/response body is ever logged by this class.
 */
final class PacketaClient extends BaseClient
{
    public const PROVIDER = 'packeta';

    public const REST_BASE = 'https://www.zasilkovna.cz/api/rest';

    public const CONNECT_TIMEOUT = 5;
    /** 04 §7: the legacy SOAP client used a 30 s HTTP timeout (CODE_VERIFIED audit §E). */
    public const TOTAL_TIMEOUT = 30;

    /** Element names decoded as lists even when a single child is present (04 §4/§6 shapes). */
    private const LIST_ELEMENTS = [
        'record',
        'item',
        'attribute',
        'courierInfoItem',
        'courierNumber',
        'courierBarcode',
        'courierTrackingNumber',
        'courierTrackingUrl',
        'packetId',
        'string',
    ];

    private string $apiPassword;

    /**
     * @param string|null $apiPassword 32-char apiPassword (04 §2.1); read from CredentialStore when null
     * @param string|null $baseUrl     explicit override (tests only)
     */
    public function __construct(?string $apiPassword = null, ?string $baseUrl = null)
    {
        // Short-circuit: an explicit password never touches the settings/options layer (unit-testable).
        $this->apiPassword = (string) ($apiPassword ?? (new ModuleSettings())->secret(self::PROVIDER, 'api_password') ?? '');

        parent::__construct($baseUrl ?? self::REST_BASE);
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);
        $this->setHeader('Content-Type', 'text/xml; charset=utf-8');
        $this->setHeader('Accept', 'text/xml, application/xml');
    }

    public function hasCredentials(): bool
    {
        return trim($this->apiPassword) !== '';
    }

    /** Raw method call: builds the full XML envelope, apiPassword first, and POSTs it. */
    public function call(string $method, array $args = []): Response
    {
        if (!$this->hasCredentials()) {
            return new Response(0, [], '', 'packeta_credentials_missing');
        }
        $xml = self::buildXml($method, $args, $this->apiPassword);
        return $this->request('POST', $this->baseUrl, ['body' => $xml]);
    }

    // ---- documented operations (04 §4) --------------------------------------------------------------

    /** PKT-001 `createPacket(PacketAttributes)`. */
    public function createPacket(array $packetAttributes): Response
    {
        return $this->call('createPacket', ['packetAttributes' => $packetAttributes]);
    }

    /** PKT-008 `packetAttributesValid(PacketAttributes)` — pre-flight validation. */
    public function packetAttributesValid(array $packetAttributes): Response
    {
        return $this->call('packetAttributesValid', ['packetAttributes' => $packetAttributes]);
    }

    /** PKT-005 `cancelPacket(packetId)`. */
    public function cancelPacket(string $packetId): Response
    {
        return $this->call('cancelPacket', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-007 `createPacketClaimWithPassword(ClaimWithPasswordAttributes)`. */
    public function createPacketClaimWithPassword(array $claimAttributes): Response
    {
        return $this->call('createPacketClaimWithPassword', ['claimWithPasswordAttributes' => $claimAttributes]);
    }

    /** PKT-009 `packetClaimAttributesValid(ClaimAttributes)`. */
    public function packetClaimAttributesValid(array $claimAttributes): Response
    {
        return $this->call('packetClaimAttributesValid', ['claimWithPasswordAttributes' => $claimAttributes]);
    }

    /** PKT-010 `packetInfo(packetId)` — read-back bridge (packetId <-> carrierBarcode/number). */
    public function packetInfo(string $packetId): Response
    {
        return $this->call('packetInfo', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-011 `packetStatus(packetId)` — current status. */
    public function packetStatus(string $packetId): Response
    {
        return $this->call('packetStatus', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-012 `packetTracking(packetId)` — Packeta history. */
    public function packetTracking(string $packetId): Response
    {
        return $this->call('packetTracking', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-013 `packetCourierTracking(packetId)` — external-carrier history. */
    public function packetCourierTracking(string $packetId): Response
    {
        return $this->call('packetCourierTracking', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-014 `packetCourierNumber(packetId)`. */
    public function packetCourierNumber(string $packetId): Response
    {
        return $this->call('packetCourierNumber', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-015 `packetCourierNumberV2(packetId)`. */
    public function packetCourierNumberV2(string $packetId): Response
    {
        return $this->call('packetCourierNumberV2', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-016 `packetLabelPdf(packetId, format, offset)` — safe to re-request (04 §7). */
    public function packetLabelPdf(string $packetId, string $format = 'A6 on A6', int $offset = 0): Response
    {
        return $this->call('packetLabelPdf', [
            'packetId' => self::canonicalPacketId($packetId),
            'format' => $format,
            'offset' => $offset,
        ]);
    }

    /** PKT-017 `packetsLabelsPdf(packetIds, format, offset)` — collection print. */
    public function packetsLabelsPdf(array $packetIds, string $format = 'A6 on A6', int $offset = 0): Response
    {
        return $this->call('packetsLabelsPdf', [
            'packetIds' => array_values(array_map([self::class, 'canonicalPacketId'], $packetIds)),
            'format' => $format,
            'offset' => $offset,
        ]);
    }

    /** PKT-018 `packetLabelZpl(packetId, dpi)`. */
    public function packetLabelZpl(string $packetId, int $dpi = 300): Response
    {
        return $this->call('packetLabelZpl', ['packetId' => self::canonicalPacketId($packetId), 'dpi' => $dpi]);
    }

    /** PKT-023 `packetSetStoredUntil(packetId, date)` — safe to retry by id (04 §7). */
    public function packetSetStoredUntil(string $packetId, string $date): Response
    {
        return $this->call('packetSetStoredUntil', ['packetId' => self::canonicalPacketId($packetId), 'date' => $date]);
    }

    /** PKT-024 `packetGetStoredUntil(packetId)`. */
    public function packetGetStoredUntil(string $packetId): Response
    {
        return $this->call('packetGetStoredUntil', ['packetId' => self::canonicalPacketId($packetId)]);
    }

    /** PKT-025 `barcodePng(barcode)`. */
    public function barcodePng(string $barcode): Response
    {
        return $this->call('barcodePng', ['barcode' => $barcode]);
    }

    /** PKT-026 `senderGetReturnRouting(senderLabel)`. */
    public function senderGetReturnRouting(string $senderLabel): Response
    {
        return $this->call('senderGetReturnRouting', ['senderLabel' => $senderLabel]);
    }

    /**
     * PKT-027 `createStorageFile(content, name)` — EAD/invoice attachment. Creates a new file on each
     * retry (idempotent in effect, may orphan duplicates; 04 §7/§10 item 9).
     */
    public function createStorageFile(string $contentBase64, string $name): Response
    {
        return $this->call('createStorageFile', ['content' => $contentBase64, 'name' => $name]);
    }

    /** PKT-003 `createShipment(packetIds, customBarcode)` — fulfilment agreement (account-dependent). */
    public function createShipment(array $packetIds, string $customBarcode): Response
    {
        return $this->call('createShipment', [
            'packetIds' => array_values(array_map([self::class, 'canonicalPacketId'], $packetIds)),
            'customBarcode' => $customBarcode,
        ]);
    }

    /** PKT-004 `shipmentPackets(shipmentId)`. */
    public function shipmentPackets(string $shipmentId): Response
    {
        return $this->call('shipmentPackets', ['shipmentId' => $shipmentId]);
    }

    // ---- pure envelope helpers (unit-testable without WordPress/HTTP) -------------------------------

    /**
     * Build the REST/XML envelope: root = method name, first child = apiPassword, then the arguments.
     * Child elements are escaped; arrays are encoded with the documented container element names.
     */
    public static function buildXml(string $method, array $args, string $apiPassword): string
    {
        $method = self::safeName($method);
        if ($method === '') {
            throw new \InvalidArgumentException('Invalid Packeta method name.');
        }

        $xml = '<' . $method . '>';
        $xml .= '<apiPassword>' . self::esc($apiPassword) . '</apiPassword>';
        foreach ($args as $key => $value) {
            $xml .= self::encodeNode((string) $key, $value);
        }
        $xml .= '</' . $method . '>';

        return $xml;
    }

    /**
     * Decode a REST/XML response (`<response><status>ok</status><result>…</result></response>`). Leaf
     * values are returned as STRINGS (packet ids must never be coerced to int — 32-bit WSDL note).
     *
     * @return array{ok:bool,status:string,fault:?string,result:array<string,mixed>,raw:string}
     */
    public static function decode(string $body): array
    {
        $out = ['ok' => false, 'status' => '', 'fault' => null, 'result' => [], 'raw' => $body];
        $body = trim($body);
        if ($body === '') {
            $out['fault'] = 'empty_response';
            return $out;
        }
        if (!class_exists('\DOMDocument')) {
            $out['fault'] = 'xml_unavailable';
            return $out;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $dom->documentElement === null) {
            $out['fault'] = 'malformed_xml';
            return $out;
        }

        $root = $dom->documentElement;
        $status = '';
        $result = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($node->nodeName);
            if ($name === 'status') {
                $status = trim($node->textContent);
            } elseif ($name === 'result') {
                /** @var array<string,mixed>|string $converted */
                $converted = self::xmlToValue($node);
                if (is_array($converted)) {
                    $result = $converted;
                } elseif (is_string($converted)) {
                    // Scalar result (e.g. a base64 label body) is exposed under `value`.
                    $result = ['value' => $converted];
                }
            } elseif ($name === 'fault' || $name === 'faultstring' || $name === 'error' || $name === 'message') {
                $out['fault'] = trim($node->textContent);
            }
        }

        $out['status'] = $status;
        $out['result'] = $result;
        // `<status>ok</status>` is the documented success marker; a fault/other status is a refusal.
        $out['ok'] = ($status === '' || strtolower($status) === 'ok');
        if (!$out['ok'] && $out['fault'] === null) {
            $out['fault'] = $status !== '' ? $status : 'provider_error';
        }

        return $out;
    }

    /**
     * Canonicalise a packet id / barcode: strip whitespace and any non-token characters. The documented
     * barcodeText contains spaces (`Z 415 4090 000`) while the barcode/id does not (`Z4154090000`).
     */
    public static function canonicalPacketId(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', trim($value));
    }

    /**
     * Normalize a decoded value to a list of arrays (a single record becomes a one-element list).
     *
     * @return list<mixed>
     */
    public static function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return $value === null || $value === '' ? [] : [$value];
        }
        if (array_is_list($value)) {
            return array_values($value);
        }
        return [$value];
    }

    /**
     * First non-empty scalar among the given keys of a decoded result, as a string. Never coerces a
     * numeric-looking packet id to an integer type.
     *
     * @param array<string,mixed> $result
     */
    public static function firstString(array $result, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($result[$key]) && is_scalar($result[$key])) {
                $value = trim((string) $result[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    // ---- encoding internals -------------------------------------------------------------------------

    private static function encodeNode(string $key, mixed $value): string
    {
        $key = self::safeName($key);
        if ($key === '') {
            return '';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '<' . $key . '/>';
            }
            $inner = '';
            if (array_is_list($value)) {
                $child = self::listChildName($key);
                foreach ($value as $item) {
                    $inner .= self::encodeNode($child, $item);
                }
            } else {
                foreach ($value as $childKey => $childValue) {
                    $inner .= self::encodeNode((string) $childKey, $childValue);
                }
            }
            return '<' . $key . '>' . $inner . '</' . $key . '>';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        return '<' . $key . '>' . self::esc((string) $value) . '</' . $key . '>';
    }

    /** Documented container child names for list-valued arguments (04 §5.1 attribute/item collections). */
    private static function listChildName(string $key): string
    {
        return match ($key) {
            'attributes' => 'attribute',
            'items' => 'item',
            'packetIds' => 'packetId',
            'courierNumbers' => 'courierNumber',
            'strings' => 'string',
            default => 'item',
        };
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function safeName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', trim($name));
    }

    /**
     * Convert a DOM element to a PHP value: text-only elements become their string text; elements with
     * children become associative arrays; repeated children become lists. Numeric text stays a string.
     *
     * @return array<string,mixed>|string
     */
    private static function xmlToValue(\DOMElement $element): array|string
    {
        $children = [];
        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $children[] = $node;
            }
        }
        if ($children === []) {
            return $element->textContent;
        }

        $grouped = [];
        foreach ($children as $child) {
            $grouped[$child->nodeName][] = $child;
        }

        $assoc = [];
        foreach ($grouped as $name => $elements) {
            if (count($elements) > 1 || in_array($name, self::LIST_ELEMENTS, true)) {
                $assoc[$name] = array_map([self::class, 'xmlToValue'], $elements);
            } else {
                $assoc[$name] = self::xmlToValue($elements[0]);
            }
        }
        return $assoc;
    }
}
