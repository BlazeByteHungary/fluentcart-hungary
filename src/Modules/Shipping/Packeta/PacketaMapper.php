<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Money\Money;
use FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits;

/**
 * PURE Packeta `PacketAttributes` mapper — no WordPress, no HTTP, no FluentCart. It validates a
 * normalized packet snapshot against the documented limits and builds the exact attribute array that
 * {@see PacketaClient::createPacket()} encodes into the `<createPacket>` envelope.
 *
 * Evidence (04_PACKETA_CONTRACT.md §5.1 "PacketAttributes" unless noted):
 *  - `number` 1–36 alphanumeric (order reference for operator reconciliation; uniqueness NOT enforced);
 *  - `name`/`surname` 1–32 with regex `/^[\p{L}\p{N} ,.'\-&()]+$/u`;
 *  - `email` or `phone` (at least one); per-destination phone formats (§5.1, `PACKETA_DOKS.md` phone table);
 *  - `addressId` required — a branch/Z-BOX id for PUDO, or a home-delivery carrier id for HD
 *    (`PACKETA_DOKS.md` HD example uses `addressId` of the carrier);
 *  - `currency` ∈ {CZK, EUR, HUF, PLN, RON};
 *  - `cod` ≤ 2 dp, whole for CZK, **multiple of 5 for HUF**; value maxima are Packeta TOS per destination
 *    (04 §2.3/§10 item 5) → enforced only from operator settings;
 *  - `value` (insurance) and `weight` required; `deliverOn` within the next 14 days;
 *  - `adultContent` only for Packeta internal PUDOs in CZ/SK/HU/RO (§5.1);
 *  - HD needs `street`/`houseNumber`/`city`/`zip`; non-EU needs customs `items`/`attributes` (§5.1, §Customs).
 *
 * Money is integer DOMAIN minor units (HUF = whole forint) and is rendered with {@see Money::fromMinor()}
 * at the provider boundary; no float arithmetic is performed anywhere on the money path.
 */
final class PacketaMapper
{
    public const DELIVERY_PUDO = 'PUDO';
    public const DELIVERY_HD = 'HD';

    public const CURRENCIES = ['CZK', 'EUR', 'HUF', 'PLN', 'RON'];
    public const ADULT_CONTENT_COUNTRIES = ['CZ', 'SK', 'HU', 'RO'];

    /** EU member states — customs are only required outside the EU (04 §Customs; MPL/FC conventions). */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Default home-delivery carrier `addressId` per destination, transcribed from the legacy adapter
     * (HS-041 audit: `hu|4159, cz|106, sk|131, pl|4162, ro|4161, at|80, de|111`). These are defaults only:
     * the operator setting `hd_address_id` wins, and carriers are account/contract dependent (04 §2.3).
     */
    public const HD_ADDRESS_IDS = [
        'HU' => '4159',
        'CZ' => '106',
        'SK' => '131',
        'PL' => '4162',
        'RO' => '4161',
        'AT' => '80',
        'DE' => '111',
    ];

    /**
     * @param array<string,mixed> $snapshot
     * @return array{ok:bool,errors:list<string>,payload:array<string,mixed>}
     */
    public static function build(array $snapshot): array
    {
        $errors = self::validate($snapshot);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values($errors), 'payload' => []];
        }
        return ['ok' => true, 'errors' => [], 'payload' => self::compose($snapshot)];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<string> stable machine codes (never throws)
     */
    public static function validate(array $snapshot): array
    {
        $errors = [];

        $number = trim((string) ($snapshot['number'] ?? ''));
        if ($number === '') {
            $errors[] = 'missing_number';
        } elseif (preg_match('/^[A-Za-z0-9]{1,36}$/', $number) !== 1) {
            $errors[] = 'invalid_number';
        }

        $name = trim((string) ($snapshot['name'] ?? ''));
        $surname = trim((string) ($snapshot['surname'] ?? ''));
        if ($name === '') {
            $errors[] = 'missing_name';
        } elseif (!self::isValidPersonName($name)) {
            $errors[] = 'invalid_name';
        }
        if ($surname === '') {
            $errors[] = 'missing_surname';
        } elseif (!self::isValidPersonName($surname)) {
            $errors[] = 'invalid_surname';
        }

        $email = trim((string) ($snapshot['email'] ?? ''));
        $phone = trim((string) ($snapshot['phone'] ?? ''));
        if ($email === '' && $phone === '') {
            $errors[] = 'missing_contact';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'invalid_email';
        }

        $country = strtoupper(trim((string) ($snapshot['country'] ?? 'HU')));
        if (strlen($country) !== 2) {
            $errors[] = 'invalid_country';
            $country = 'HU';
        }
        if ($phone !== '' && str_starts_with($phone, '+') && !self::isValidPhone($phone, $country)) {
            $errors[] = 'invalid_phone';
        }

        $addressId = trim((string) ($snapshot['address_id'] ?? ''));
        if ($addressId === '') {
            $errors[] = 'missing_address_id';
        }

        $currency = strtoupper(trim((string) ($snapshot['currency'] ?? 'HUF')));
        if (!in_array($currency, self::CURRENCIES, true)) {
            $errors[] = 'invalid_currency';
        }

        $deliveryMode = strtoupper(trim((string) ($snapshot['delivery_mode'] ?? self::DELIVERY_HD)));
        if (!in_array($deliveryMode, [self::DELIVERY_PUDO, self::DELIVERY_HD], true)) {
            $errors[] = 'invalid_delivery_mode';
        }

        // COD: shared registry rule (HUF must be a multiple of 5) with operator settings overriding the
        // TOS-driven min/max (04 §5.1 "multiple of 5 for HUF"; 04 §2.3 value maxima per TOS).
        $codMinor = (int) ($snapshot['cod_minor'] ?? 0);
        if ($codMinor > 0) {
            $check = CodValueLimits::check(
                PacketaModule::CARRIER,
                $codMinor,
                self::nullableInt($snapshot['cod_min_minor'] ?? null),
                self::nullableInt($snapshot['cod_max_minor'] ?? null)
            );
            if (!$check['ok']) {
                $errors[] = (string) $check['reason'];
            }
            // 04 §5.1: CZK COD must be a WHOLE number even though CZK has a 2-dp exponent.
            if ($currency === 'CZK' && $codMinor % 100 !== 0) {
                $errors[] = 'cod_czk_not_whole';
            }
        } elseif ($codMinor < 0) {
            $errors[] = 'cod_negative';
        }

        $valueMinor = (int) ($snapshot['value_minor'] ?? 0);
        if ($valueMinor < 0) {
            $errors[] = 'value_negative';
        }
        $valueMax = self::nullableInt($snapshot['value_max_minor'] ?? null);
        if ($valueMax !== null && $valueMax > 0 && $valueMinor > $valueMax) {
            $errors[] = 'value_over_max';
        }
        // 04 §5.1: `value` is required (insurance declaration).
        if ($valueMinor <= 0) {
            $errors[] = 'value_required';
        }

        $weight = trim((string) ($snapshot['weight_kg'] ?? ''));
        if ($weight === '' || preg_match('/^\d+(\.\d{1,3})?$/', $weight) !== 1) {
            $errors[] = 'weight_invalid';
        } elseif ((float) $weight <= 0.0) {
            $errors[] = 'weight_missing';
        }

        $deliverOn = trim((string) ($snapshot['deliver_on'] ?? ''));
        if ($deliverOn !== '' && !self::isDeliverOnInWindow($deliverOn, (string) ($snapshot['today'] ?? ''))) {
            $errors[] = 'deliver_on_invalid';
        }

        if ($deliveryMode === self::DELIVERY_HD) {
            if (trim((string) ($snapshot['street'] ?? '')) === '') {
                $errors[] = 'missing_street';
            }
            if (trim((string) ($snapshot['city'] ?? '')) === '') {
                $errors[] = 'missing_city';
            }
            if (trim((string) ($snapshot['zip'] ?? '')) === '') {
                $errors[] = 'missing_zip';
            }
        }

        // 04 §5.1: adultContent only on Packeta internal PUDOs in CZ/SK/HU/RO.
        if (!empty($snapshot['adult_content'])) {
            if ($deliveryMode !== self::DELIVERY_PUDO || !in_array($country, self::ADULT_CONTENT_COUNTRIES, true)) {
                $errors[] = 'adult_content_not_allowed';
            }
        }

        // Non-EU home delivery needs customs items (§5.1, §Customs; `customsDeclaration` deprecated).
        if (!self::isEu($country)) {
            $items = (array) ($snapshot['items'] ?? []);
            if ($items === []) {
                $errors[] = 'customs_required_outside_eu';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed> exact PacketAttributes payload
     */
    private static function compose(array $snapshot): array
    {
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'HUF'));

        $attributes = [
            'number' => (string) ($snapshot['number'] ?? ''),
            'name' => (string) ($snapshot['name'] ?? ''),
            'surname' => (string) ($snapshot['surname'] ?? ''),
        ];

        $company = trim((string) ($snapshot['company'] ?? ''));
        if ($company !== '') {
            $attributes['company'] = $company;
        }

        $email = trim((string) ($snapshot['email'] ?? ''));
        if ($email !== '') {
            $attributes['email'] = $email;
        }
        $phone = trim((string) ($snapshot['phone'] ?? ''));
        if ($phone !== '') {
            $attributes['phone'] = $phone;
        }

        $attributes['addressId'] = (string) ($snapshot['address_id'] ?? '');
        $attributes['currency'] = $currency;

        $codMinor = (int) ($snapshot['cod_minor'] ?? 0);
        if ($codMinor > 0) {
            $attributes['cod'] = Money::fromMinor($codMinor, $currency);
        }
        $attributes['value'] = Money::fromMinor((int) ($snapshot['value_minor'] ?? 0), $currency);
        $attributes['weight'] = (string) ($snapshot['weight_kg'] ?? '0');

        $deliverOn = trim((string) ($snapshot['deliver_on'] ?? ''));
        if ($deliverOn !== '') {
            $attributes['deliverOn'] = $deliverOn;
        }

        $eshop = trim((string) ($snapshot['eshop'] ?? ''));
        if ($eshop !== '') {
            $attributes['eshop'] = $eshop;
        }

        if (!empty($snapshot['adult_content'])) {
            $attributes['adultContent'] = 1;
        }

        $note = trim((string) ($snapshot['note'] ?? ''));
        if ($note !== '') {
            $attributes['note'] = $note;
        }

        foreach (['street' => 'street', 'house_number' => 'houseNumber', 'city' => 'city', 'zip' => 'zip', 'province' => 'province'] as $field => $key) {
            $value = trim((string) ($snapshot[$field] ?? ''));
            if ($value !== '') {
                $attributes[$key] = $value;
            }
        }

        $carrierService = trim((string) ($snapshot['carrier_service'] ?? ''));
        if ($carrierService !== '') {
            $attributes['carrierService'] = $carrierService;
        }
        $carrierPickupPoint = trim((string) ($snapshot['carrier_pickup_point'] ?? ''));
        if ($carrierPickupPoint !== '') {
            $attributes['carrierPickupPoint'] = $carrierPickupPoint;
        }

        // Customs attribute/item collections (04 §Customs) — already normalized to {key,value} lists.
        $customsAttributes = self::attributeList((array) ($snapshot['attributes'] ?? []));
        if ($customsAttributes !== []) {
            $attributes['attributes'] = $customsAttributes;
        }
        $items = self::itemList((array) ($snapshot['items'] ?? []));
        if ($items !== []) {
            $attributes['items'] = $items;
        }

        $security = (array) ($snapshot['security'] ?? []);
        if ($security !== []) {
            $attributes['security'] = self::scalarAssoc($security);
        }
        $services = (array) ($snapshot['services'] ?? []);
        if ($services !== []) {
            $attributes['services'] = self::scalarAssoc($services);
        }

        $affiliateId = trim((string) ($snapshot['affiliate_id'] ?? ''));
        if ($affiliateId !== '') {
            $attributes['affiliateId'] = $affiliateId;
        }

        return $attributes;
    }

    // ---- pure helpers -------------------------------------------------------------------------------

    /** 04 §5.1 name/surname regex + 1–32 length. */
    public static function isValidPersonName(string $value): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 1 || $length > 32) {
            return false;
        }
        return preg_match('/^[\p{L}\p{N} ,.\'\-&()]+$/u', $value) === 1;
    }

    /**
     * Split a FluentCart full name into Packeta `name` + `surname`. First token -> name, remainder ->
     * surname; a single-token name is used for both (assumption — see the report).
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? '');
        if ($full === '') {
            return ['', ''];
        }
        $parts = explode(' ', $full, 2);
        $name = $parts[0];
        $surname = $parts[1] ?? $parts[0];
        return [$name, $surname];
    }

    /** `deliverOn` must be YYYY-MM-DD within today..today+14 (04 §5.1). */
    public static function isDeliverOnInWindow(string $date, string $today = ''): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        $target = strtotime($date . ' 00:00:00 UTC');
        if ($target === false) {
            return false;
        }
        $base = $today !== '' ? strtotime($today . ' 00:00:00 UTC') : strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
        if ($base === false) {
            return false;
        }
        return $target >= $base && $target <= $base + (14 * 86400);
    }

    public static function isEu(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::EU_COUNTRIES, true);
    }

    /** Legacy HS-041 default HD carrier addressId for a destination (settings override it). */
    public static function defaultHdAddressId(string $country): string
    {
        return self::HD_ADDRESS_IDS[strtoupper(trim($country))] ?? '';
    }

    /**
     * E.164 phone validation for a known destination: the number (without '+') must start with the
     * destination dialling prefix and its digit length must fit the documented format (04 §5.1 /
     * `PACKETA_DOKS.md` phone table). Unknown countries and local (non-`+`) numbers are accepted because
     * Packeta interprets a missing country code against the destination (PACKETA_DOKS.md phone section).
     */
    public static function isValidPhone(string $phone, string $country): bool
    {
        $national = preg_replace('/\D+/', '', $phone) ?? '';
        if ($national === '') {
            return false;
        }
        $prefix = self::phonePrefix(strtoupper(trim($country)));
        if ($prefix === null) {
            return true;
        }
        return str_starts_with($national, $prefix);
    }

    /** @return string|null dialling prefix for the documented destination set */
    private static function phonePrefix(string $country): ?string
    {
        $map = [
            'AE' => '971', 'AT' => '43', 'BE' => '32', 'BG' => '359', 'CH' => '41', 'CY' => '357',
            'CZ' => '420', 'DE' => '49', 'DK' => '45', 'EE' => '372', 'ES' => '34', 'FI' => '358',
            'FR' => '33', 'GB' => '44', 'GR' => '30', 'HR' => '385', 'HU' => '36', 'IE' => '353',
            'IT' => '39', 'LT' => '370', 'LU' => '352', 'LV' => '371', 'NL' => '31', 'PL' => '48',
            'PT' => '351', 'RO' => '40', 'SE' => '46', 'SI' => '386', 'SK' => '421', 'TR' => '90',
            'UA' => '380', 'US' => '1',
        ];
        return $map[$country] ?? null;
    }

    /** Integer grams -> decimal kg string without float rounding (1000 -> "1", 1250 -> "1.25"). */
    public static function gramsToKgString(int $grams): string
    {
        if ($grams <= 0) {
            return '0';
        }
        $whole = intdiv($grams, 1000);
        $rest = $grams % 1000;
        if ($rest === 0) {
            return (string) $whole;
        }
        return $whole . '.' . rtrim(str_pad((string) $rest, 3, '0', STR_PAD_LEFT), '0');
    }

    /**
     * Normalize an attribute list: accepts `[{key,value}]` or `{key:value}` and returns `[{key,value}]`.
     *
     * @param array<mixed> $value
     * @return list<array{key:string,value:string}>
     */
    private static function attributeList(array $value): array
    {
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $k = trim((string) $entry['key']);
                $v = $entry['value'] ?? '';
                if ($k !== '' && is_scalar($v)) {
                    $out[] = ['key' => $k, 'value' => (string) $v];
                }
            } elseif (is_string($key) && is_scalar($entry)) {
                $out[] = ['key' => $key, 'value' => (string) $entry];
            }
        }
        return $out;
    }

    /**
     * Normalize customs items: accepts `[{attributes:[{key,value}]}]`, `[{key,value}]`, or a map.
     *
     * @param array<mixed> $value
     * @return list<array{attributes:list<array{key:string,value:string}>}>
     */
    private static function itemList(array $value): array
    {
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['attributes']) && is_array($entry['attributes'])) {
                $attrs = self::attributeList($entry['attributes']);
                if ($attrs !== []) {
                    $out[] = ['attributes' => $attrs];
                }
                continue;
            }
            $attrs = self::attributeList($entry);
            if ($attrs !== []) {
                $out[] = ['attributes' => $attrs];
            }
        }
        return $out;
    }

    /** @param array<mixed> $value @return array<string,string> */
    private static function scalarAssoc(array $value): array
    {
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_scalar($entry)) {
                $out[(string) $key] = is_bool($entry) ? ($entry ? '1' : '0') : (string) $entry;
            }
        }
        return $out;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
