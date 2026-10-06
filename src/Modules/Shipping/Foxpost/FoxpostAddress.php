<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

/**
 * Foxpost address / geodata helpers (04_FOXPOST_CONTRACT.md §4 op 17-19, §3, §10).
 *
 * Documented:
 *  - return-address CRUD (`GET/POST /api/address`, `DELETE /api/address/{name}`) with `AddressDTO`
 *    fields name/zip/city/address/country/type, `type` ∈ HQ/SITE/INVOICING/MAILING/RETURN (§4 op 18);
 *  - postal-code rule: HU zip 1000..9999 (guide §6.1, error INVALID_ZIP); city/address length rules
 *    (OpenAPI recipientCity maxLength 25, recipientAddress maxLength 150).
 *
 * Not supported / not documented (returns an explicit refusal, never a guess):
 *  - there is **no** server-side geodata/geocode endpoint and **no** server-side point-validate endpoint
 *    (APT validity is client-side against the cached CDN feed, §3 FX-U2). `geocode()` and
 *    `validatePickupPoint()` are therefore `not_supported`.
 */
final class FoxpostAddress
{
    /** AddressDTO.type enum (SPEC_VERIFIED §4 op 18). */
    public const TYPES = ['HQ', 'SITE', 'INVOICING', 'MAILING', 'RETURN'];

    private ?FoxpostClient $client;

    public function __construct(?FoxpostClient $client = null)
    {
        $this->client = $client;
    }

    /** Guide §6.1: HU zip is 1000..9999. */
    public static function isValidHuZip(string $zip): bool
    {
        $zip = trim($zip);
        if (!preg_match('/^\d{4}$/', $zip)) {
            return false;
        }
        $value = (int) $zip;
        return $value >= 1000 && $value <= 9999;
    }

    public static function normalizeZip(string $zip): string
    {
        $zip = trim($zip);
        return preg_match('/^\d{1,4}$/', $zip) ? str_pad($zip, 4, '0', STR_PAD_LEFT) : $zip;
    }

    /**
     * Validate an AddressDTO-shaped array. Returns stable machine codes.
     *
     * @param array<string,mixed> $address
     * @return list<string>
     */
    public static function validateAddress(array $address): array
    {
        $errors = [];
        if (trim((string) ($address['name'] ?? '')) === '') {
            $errors[] = 'MISSING_NAME';
        }
        $city = trim((string) ($address['city'] ?? ''));
        if ($city === '') {
            $errors[] = 'MISSING_CITY';
        } elseif (strlen($city) > 25) {
            $errors[] = 'INVALID_FORMAT';
        }
        if (!self::isValidHuZip((string) ($address['zip'] ?? ''))) {
            $errors[] = 'INVALID_ZIP';
        }
        $line = trim((string) ($address['address'] ?? ''));
        if ($line === '') {
            $errors[] = 'MISSING_ADDRESS';
        } elseif (strlen($line) > 150) {
            $errors[] = 'INVALID_FORMAT';
        }
        $country = strtoupper(trim((string) ($address['country'] ?? 'HU')));
        if ($country === '') {
            $country = 'HU';
        }
        if (strlen($country) > 3) {
            $errors[] = 'INVALID_FORMAT';
        }
        $type = strtoupper(trim((string) ($address['type'] ?? '')));
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            $errors[] = 'INVALID_FORMAT';
        }
        return array_values(array_unique($errors));
    }

    /**
     * @return array{ok:bool,error?:string,addresses?:list<array<string,mixed>>}
     */
    public function listAddresses(): array
    {
        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['ok' => false, 'error' => 'missing_credentials'];
        }
        $response = $client->getAddresses();
        if ($response->error !== null || !$response->isSuccess()) {
            return ['ok' => false, 'error' => 'foxpost_http_' . ($response->status > 0 ? $response->status : 'timeout')];
        }
        $data = $response->json();
        return ['ok' => true, 'addresses' => array_values(array_filter(is_array($data) ? $data : [], 'is_array'))];
    }

    /**
     * @param array<string,mixed> $address
     * @return array{ok:bool,error?:string,errors?:list<string>,address?:array<string,mixed>}
     */
    public function saveAddress(array $address): array
    {
        $errors = self::validateAddress($address);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['ok' => false, 'error' => 'missing_credentials'];
        }
        $response = $client->saveAddress($address);
        if ($response->error !== null || !$response->isSuccess()) {
            return ['ok' => false, 'error' => 'foxpost_http_' . ($response->status > 0 ? $response->status : 'timeout')];
        }
        return ['ok' => true, 'address' => $response->json()];
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function deleteAddress(string $name): array
    {
        if (trim($name) === '') {
            return ['ok' => false, 'error' => 'missing_name'];
        }
        $client = $this->client();
        if ($client === null || !$client->hasCredentials()) {
            return ['ok' => false, 'error' => 'missing_credentials'];
        }
        $response = $client->deleteAddress($name);
        if ($response->error !== null || !$response->isSuccess()) {
            return ['ok' => false, 'error' => 'foxpost_http_' . ($response->status > 0 ? $response->status : 'timeout')];
        }
        return ['ok' => true];
    }

    /**
     * No server-side geocoding is documented (04_FOXPOST §3/§10) — explicit not_supported, never invented.
     *
     * @return array{ok:bool,error:string}
     */
    public static function geocode(string $address): array
    {
        return ['ok' => false, 'error' => 'not_supported'];
    }

    /**
     * No server-side pickup-point validation endpoint exists for Foxpost (04_FOXPOST §3, FX-U2) — point
     * validity is a client-side check against the cached APT feed in PickupDirectory.
     *
     * @return array{ok:bool,error:string}
     */
    public static function validatePickupPoint(string $operatorId): array
    {
        return ['ok' => false, 'error' => 'not_supported'];
    }

    private function client(): ?FoxpostClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        try {
            $this->client = new FoxpostClient(FoxpostModule::mode());
        } catch (\Throwable $e) {
            return null;
        }
        return $this->client;
    }
}
