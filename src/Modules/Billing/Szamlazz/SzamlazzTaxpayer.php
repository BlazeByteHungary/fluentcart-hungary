<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

/**
 * NAV taxpayer lookup through the Agent `action-szamla_agent_taxpayer` operation (04_SZAMLAZZ_HU_CONTRACT.md
 * §5 taxpayer, §8 FC-003 "1 h cache"). The request takes an 8-digit `<torzsszam>` and returns the NAV
 * `QueryTaxpayerResponse`.
 *
 * Only called when `taxpayer_lookup_enabled` is truthy. A lookup failure never blocks invoicing unless the
 * account requires it (`taxpayer_lookup_required`): callers treat a failed lookup as "unknown", not as a
 * hard rejection (contract §9 "our integration responsibility").
 */
final class SzamlazzTaxpayer
{
    public const CACHE_TTL = 3600;

    private SzamlazzClient $client;

    public function __construct(?SzamlazzClient $client = null)
    {
        $this->client = $client ?? new SzamlazzClient((string) SzamlazzModule::agentKey());
    }

    public function enabled(): bool
    {
        return (bool) SzamlazzModule::setting('taxpayer_lookup_enabled', false);
    }

    public function required(): bool
    {
        return (bool) SzamlazzModule::setting('taxpayer_lookup_required', false);
    }

    /**
     * @return array{ok:bool,validity:?bool,name:?string,error_code:?string,error_message:?string,from_cache:bool}
     */
    public function lookup(string $taxNumber): array
    {
        $torzsszam = $this->torzsszam($taxNumber);
        if ($torzsszam === null) {
            return $this->result(false, null, null, 'INVALID_TAX_NUMBER', 'A 8 jegyű törzsszám nem kinyerhető.', false);
        }

        $cacheKey = 'fch_szamlazz_taxpayer_' . substr(hash('sha256', SzamlazzModule::accountId() . '|' . $torzsszam), 0, 40);
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        try {
            $response = $this->client->lookupTaxpayer($torzsszam);
            if ($response->error !== null || $response->status === 0) {
                return $this->result(false, null, null, 'LOOKUP_TIMEOUT', 'A NAV lekérdezés nem elérhető.', false);
            }
            $parsed = $this->parse($response->body);
            if (function_exists('set_transient') && $parsed['ok']) {
                set_transient($cacheKey, $parsed, self::CACHE_TTL);
            }
            return $parsed;
        } catch (\Throwable $e) {
            return $this->result(false, null, null, 'LOOKUP_ERROR', 'A NAV lekérdezés sikertelen.', false);
        }
    }

    /** @return array{ok:bool,validity:?bool,name:?string,error_code:?string,error_message:?string,from_cache:bool} */
    private function parse(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $this->result(false, null, null, 'UNPARSEABLE', 'A NAV válasz nem értelmezhető.', false);
        }

        $funcCode = $this->text($dom, 'funcCode');
        if ($funcCode !== null && strtoupper($funcCode) !== 'OK') {
            return $this->result(false, null, null, $this->text($dom, 'errorCode'), $this->text($dom, 'message'), false);
        }

        $validity = $this->text($dom, 'taxpayerValidity');
        return $this->result(
            true,
            $validity === null ? null : in_array(strtolower($validity), ['true', '1'], true),
            $this->text($dom, 'taxpayerName'),
            null,
            null,
            false
        );
    }

    private function text(\DOMDocument $dom, string $name): ?string
    {
        $nodes = $dom->getElementsByTagName($name);
        return $nodes->length > 0 ? (string) $nodes->item(0)->textContent : null;
    }

    /** Extract the 8-digit base number from `XXXXXXXX-Y-ZZ` or a bare 8-digit number (04 SZAMLAZZ §4.6). */
    public function torzsszam(string $taxNumber): ?string
    {
        $digits = preg_replace('/\D/', '', $taxNumber) ?? '';
        if (strlen($digits) === 8) {
            return $digits;
        }
        if (strlen($digits) >= 8) {
            return substr($digits, 0, 8);
        }
        return null;
    }

    /** @return array{ok:bool,validity:?bool,name:?string,error_code:?string,error_message:?string,from_cache:bool} */
    private function result(bool $ok, ?bool $validity, ?string $name, ?string $code, ?string $message, bool $fromCache): array
    {
        return [
            'ok' => $ok,
            'validity' => $validity,
            'name' => $name,
            'error_code' => $code,
            'error_message' => $message,
            'from_cache' => $fromCache,
        ];
    }
}
