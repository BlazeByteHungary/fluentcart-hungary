<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Mpl;

/**
 * PURE MPL shipment mapper — no WordPress, no HTTP, no FluentCart. It validates a normalized shipment
 * snapshot against the documented MPL limits and builds the exact `ShipmentCreateRequest` used by
 * MPL-006 `POST /v2/mplapi/shipments` (04_MPL_CONTRACT.md §4 MPL-006).
 *
 * Every rule and field below cites 04_MPL_CONTRACT.md:
 *  - auth/account identifiers: §2.1 (`developer`, `sender.agreement`, `sender.accountNo`);
 *  - bases/paths are the client's concern, not this mapper;
 *  - create request keys: §4 MPL-006 (`developer`, `sender`, `recipient`, `item[]`, `webshopId`,
 *    `labelType`, `labelFormat`, `groupTogether`, `deliveryMode`, `packageRetention`, `paymentMode`);
 *  - `customData1` persists after list close and is the timeout-reconciliation key: §7 (reference L766-771);
 *  - delivery modes HA/RA/PP/CS/PM: §3.1 (L1024);
 *  - pickup addressing `recipient.address.parcelPickupSite` + deliveryMode PP/PM/CS: §3.2 (L620-626);
 *  - COD `item.services.cod` + extra `K_UVT`, `paymentMode` UV_AT|UV_KP, UV_AT needs `sender.accountNo`:
 *    §3.6 (L1012-1034, L1062-1064; error 94);
 *  - value declaration `K_ENY` + `item.services.value`: §3.6 (L944-946, L1021-1023; error 64/63);
 *  - customs `item.customs[]` (produceCount, produceName, produceValue, tariffCode 6/8/10, country,
 *    weightValue) required outside the EU: §5.2 (L590-600; errors 114-125);
 *  - domestic weight per mode (HA 40kg, PM 30kg, PP/CS 20kg, RA 1000kg; Postacsomag HA 10kg): §5.1;
 *  - parcel terminal one parcel + size S/M/L mandatory + COD/value ≤500,000 HUF: §5.1 (errors 66/67/100);
 *  - weight unit is grams only: §5.3 (L1414-1426);
 *  - address lengths postCode 1-4, city 2-35, address 3-60 DOM / ≤64 INT; HU E.164 phone ≤14: §5.3;
 *  - ZPL label only A6/A5E/A5E_EXTRA (error 132): §3.4 (L406/L807-819).
 *
 * Money is integer HUF (exponent 0) — never a float. The mapper returns:
 *   ['ok'=>bool, 'errors'=>list<string>, 'payload'=>array]
 */
final class MplShipmentMapper
{
    public const DELIVERY_MODES = ['HA', 'RA', 'PP', 'CS', 'PM'];
    public const LABEL_TYPES = ['A4', 'A5', 'A5inA4', 'A5E', 'A5E_EXTRA', 'A5E_STAND', 'A6', 'A6inA4', 'A4ONE', 'T_10x10', 'N_10x10inA4'];
    public const LABEL_FORMATS = ['PDF', 'ZPL'];
    public const ZPL_LABEL_TYPES = ['A6', 'A5E', 'A5E_EXTRA'];
    public const PAYMENT_MODES = ['UV_AT', 'UV_KP'];
    public const PARCEL_SIZES = ['S', 'M', 'L'];

    public const COD_EXTRA = 'K_UVT';
    public const VALUE_EXTRA = 'K_ENY';

    /** Domestic parcel-terminal (CS) COD / declared-value cap (04_MPL §5.1; errors 66/36). */
    public const TERMINAL_VALUE_CAP_HUF = 500000;
    /** Other domestic modes' declared-value cap (04_MPL §5.1; errors 37/36). */
    public const DEFAULT_VALUE_CAP_HUF = 2000000;
    /** >40 kg requires a value declaration (04_MPL §5.1; error 64). */
    public const VALUE_DECLARATION_THRESHOLD_G = 40000;

    public const MAX_PARCELS_JOINT = 16;
    public const BASE_SERVICE_POSTACSOMAG = 'A_177_MPC';

    /** EU member states (ISO-2) — customs are only mandatory outside the EU (04_MPL §5.2). */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * @param array<string,mixed> $snapshot normalized shipment snapshot (see class docblock)
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
     * Validate the snapshot against every documented §5 limit. Returns a list of stable machine codes
     * (never a thrown exception) so the caller can record a LOCAL_ERROR before any dispatch.
     *
     * @param array<string,mixed> $snapshot
     * @return list<string>
     */
    public static function validate(array $snapshot): array
    {
        $errors = [];

        $idempotencyKey = trim((string) ($snapshot['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $errors[] = 'missing_idempotency_key';
        }

        $sender = (array) ($snapshot['sender'] ?? []);
        $agreement = trim((string) ($sender['agreement'] ?? ''));
        if ($agreement === '') {
            $errors[] = 'missing_agreement';
        } elseif (strlen($agreement) !== 8) {
            // 04_MPL §2.3: sender.agreement is 8 chars; error 3 = agreement not found.
            $errors[] = 'agreement_must_be_8_chars';
        }
        $accountNo = trim((string) ($sender['account_no'] ?? ''));

        $recipient = (array) ($snapshot['recipient'] ?? []);
        $name = trim((string) ($recipient['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'missing_recipient_name';
        }

        $country = strtoupper(trim((string) ($recipient['country'] ?? 'HU')));
        if (strlen($country) !== 2) {
            $errors[] = 'invalid_recipient_country';
        }
        $isDomestic = $country === 'HU';
        $isEu = $isDomestic || in_array($country, self::EU_COUNTRIES, true);

        $address = (array) ($recipient['address'] ?? []);
        $addressString = trim((string) ($address['address'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $postCode = trim((string) ($address['postCode'] ?? ''));

        // 04_MPL §5.3: postCode 1-4, city 2-35, address 3-60 (DOM) / <=64 (INT).
        $postCodeMax = $isDomestic ? 4 : 10;
        if ($postCode === '' || strlen($postCode) > $postCodeMax) {
            $errors[] = 'invalid_postcode';
        }
        if (strlen($city) < 2 || strlen($city) > 35) {
            $errors[] = 'invalid_city_length';
        }
        $addressMin = 3;
        $addressMax = $isDomestic ? 60 : 64;
        if (strlen($addressString) < $addressMin || strlen($addressString) > $addressMax) {
            $errors[] = 'invalid_address_length';
        }

        $deliveryMode = strtoupper(trim((string) ($snapshot['delivery_mode'] ?? 'HA')));
        if (!in_array($deliveryMode, self::DELIVERY_MODES, true)) {
            $errors[] = 'invalid_delivery_mode';
        }

        $baseService = strtoupper(trim((string) ($snapshot['base_service'] ?? '')));
        $isPostacsomag = $baseService === self::BASE_SERVICE_POSTACSOMAG;

        // Phone: HU E.164 (§5.3). Required and mobile for parcel terminal/PostaPont (§6.2 errors 68/130).
        $phone = trim((string) ($recipient['phone'] ?? ''));
        $needsPickupSite = in_array($deliveryMode, ['PP', 'PM', 'CS'], true);
        if ($needsPickupSite && $phone === '') {
            $errors[] = 'missing_phone_for_pickup_point';
        }
        if ($phone !== '' && !self::isHuE164($phone)) {
            $errors[] = 'invalid_phone_e164';
        }

        // Pickup point must be addressed when the delivery mode is a fixed point (04_MPL §3.2; error 38/60).
        $pickupSite = trim((string) ($recipient['parcel_pickup_site'] ?? ''));
        if ($needsPickupSite && $pickupSite === '') {
            $errors[] = 'missing_parcel_pickup_site';
        }

        // Label format/type. ZPL only supports A6/A5E/A5E_EXTRA (04_MPL §3.4; error 132).
        $labelType = (string) ($snapshot['label_type'] ?? 'A4');
        if (!in_array($labelType, self::LABEL_TYPES, true)) {
            $errors[] = 'invalid_label_type';
        }
        $labelFormat = strtoupper((string) ($snapshot['label_format'] ?? 'PDF'));
        if (!in_array($labelFormat, self::LABEL_FORMATS, true)) {
            $errors[] = 'invalid_label_format';
        }
        if ($labelFormat === 'ZPL' && !in_array($labelType, self::ZPL_LABEL_TYPES, true)) {
            $errors[] = 'error_132_zpl_label_type';
        }

        $parcels = (array) ($snapshot['parcels'] ?? []);
        if ($parcels === []) {
            $errors[] = 'missing_parcels';
        }
        $parcelCount = count($parcels);
        if ($parcelCount > self::MAX_PARCELS_JOINT) {
            $errors[] = 'too_many_parcels';
        }
        if ($isPostacsomag && $parcelCount > 1) {
            // 04_MPL §5.1: Postacsomag = one parcel per consignment (error 95).
            $errors[] = 'postacsomag_single_parcel';
        }
        if ($deliveryMode === 'CS' && $parcelCount > 1) {
            // 04_MPL §5.1: only one parcel may be delivered to a parcel terminal (error 100).
            $errors[] = 'parcel_terminal_single_parcel';
        }

        $maxWeight = self::maxWeightG($deliveryMode, $isPostacsomag);
        $valueCap = $deliveryMode === 'CS' ? self::TERMINAL_VALUE_CAP_HUF : self::DEFAULT_VALUE_CAP_HUF;
        $customsRequired = !$isEu;

        foreach ($parcels as $index => $parcel) {
            $parcel = (array) $parcel;
            $weight = (int) ($parcel['weight_g'] ?? 0);
            if ($weight <= 0) {
                $errors[] = 'parcel_weight_missing';
            } elseif ($weight > $maxWeight) {
                $errors[] = 'weight_over_limit_' . $deliveryMode;
            }
            if ($weight > self::VALUE_DECLARATION_THRESHOLD_G && (int) ($parcel['value_minor'] ?? 0) <= 0) {
                // 04_MPL §5.1: >40 kg or fragile requires a value declaration (K_ENY) — error 64.
                $errors[] = 'value_declaration_required_over_40kg';
            }

            $cod = (int) ($parcel['cod_minor'] ?? 0);
            $value = (int) ($parcel['value_minor'] ?? 0);
            if ($cod < 0 || $value < 0) {
                $errors[] = 'negative_service_amount';
            }
            if ($cod > $valueCap) {
                $errors[] = 'cod_over_value_cap';
            }
            if ($value > $valueCap) {
                $errors[] = 'value_over_value_cap';
            }

            // 04_MPL §3.6: COD requires a payout paymentMode; UV_AT requires sender.accountNo (error 94).
            $paymentMode = strtoupper(trim((string) ($snapshot['payment_mode'] ?? '')));
            if ($cod > 0) {
                if (!in_array($paymentMode, self::PAYMENT_MODES, true)) {
                    $errors[] = 'missing_payment_mode_for_cod';
                } elseif ($paymentMode === 'UV_AT' && $accountNo === '') {
                    $errors[] = 'uv_at_requires_account_no';
                }
            }

            if ($deliveryMode === 'CS') {
                $size = strtoupper(trim((string) ($parcel['size'] ?? '')));
                if (!in_array($size, self::PARCEL_SIZES, true)) {
                    // 04_MPL §5.1: size S/M/L mandatory for parcel terminal (error 67).
                    $errors[] = 'parcel_terminal_size_required';
                }
            }

            if ($customsRequired) {
                $customs = (array) ($parcel['customs'] ?? []);
                if ($customs === []) {
                    $errors[] = 'customs_required_outside_eu';
                } else {
                    foreach ($customs as $custom) {
                        if (!self::isValidCustomsLine((array) $custom)) {
                            $errors[] = 'invalid_customs_line';
                            break;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Build the exact request object for one consignment (MPL-006). Assumes validate() passed.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function compose(array $snapshot): array
    {
        $sender = (array) ($snapshot['sender'] ?? []);
        $recipient = (array) ($snapshot['recipient'] ?? []);
        $address = (array) ($recipient['address'] ?? []);
        $idempotencyKey = (string) ($snapshot['idempotency_key'] ?? '');
        $paymentMode = strtoupper(trim((string) ($snapshot['payment_mode'] ?? '')));

        $items = [];
        foreach ((array) ($snapshot['parcels'] ?? []) as $parcel) {
            $parcel = (array) $parcel;
            $cod = (int) ($parcel['cod_minor'] ?? 0);
            $value = (int) ($parcel['value_minor'] ?? 0);

            $services = [];
            $extras = [];
            if ($cod > 0) {
                $services['cod'] = $cod;
                $extras[] = self::COD_EXTRA; // 04_MPL §3.6: COD requires K_UVT.
            }
            if ($value > 0) {
                $services['value'] = $value;
                $extras[] = self::VALUE_EXTRA; // 04_MPL §3.6: value insurance K_ENY.
            }
            foreach ((array) ($parcel['extras'] ?? []) as $extra) {
                $extra = trim((string) $extra);
                if ($extra !== '') {
                    $extras[] = $extra;
                }
            }
            if ($extras !== []) {
                $services['extras'] = array_values(array_unique($extras));
            }

            $item = [
                // 04_MPL §5.3: weight unit is grams only.
                'weight' => ['value' => (int) ($parcel['weight_g'] ?? 0), 'unit' => 'g'],
            ];
            if ($services !== []) {
                $item['services'] = $services;
            }
            if (isset($parcel['size']) && (string) $parcel['size'] !== '') {
                $item['size'] = (string) $parcel['size'];
            }
            if (!empty($parcel['customs'])) {
                $item['customs'] = array_values((array) $parcel['customs']);
            }
            $items[] = $item;
        }

        $recipientPayload = [
            'name' => (string) ($recipient['name'] ?? ''),
            'address' => [
                'postCode' => (string) ($address['postCode'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'address' => (string) ($address['address'] ?? ''),
            ],
        ];
        if (trim((string) ($recipient['parcel_pickup_site'] ?? '')) !== '') {
            // 04_MPL §3.2: address the fixed point by id/name; the real address is still supplied.
            $recipientPayload['address']['parcelPickupSite'] = (string) $recipient['parcel_pickup_site'];
        }

        $senderPayload = ['agreement' => (string) ($sender['agreement'] ?? '')];
        if (trim((string) ($sender['account_no'] ?? '')) !== '') {
            $senderPayload['accountNo'] = (string) $sender['account_no'];
        }

        $payload = [
            'developer' => (string) ($snapshot['developer'] ?? ''),
            'sender' => $senderPayload,
            'recipient' => $recipientPayload,
            'item' => $items,
            'webshopId' => (string) ($snapshot['webshop_id'] ?? ''),
            'labelType' => (string) ($snapshot['label_type'] ?? 'A4'),
            'labelFormat' => (string) ($snapshot['label_format'] ?? 'PDF'),
            'groupTogether' => (bool) ($snapshot['group_together'] ?? false),
            'deliveryMode' => strtoupper((string) ($snapshot['delivery_mode'] ?? 'HA')),
            'packageRetention' => (int) ($snapshot['package_retention'] ?? 5),
            // 04_MPL §7: customData1 survives list close and is the reconciliation handle.
            'customData1' => $idempotencyKey,
        ];
        if ($paymentMode !== '') {
            $payload['paymentMode'] = $paymentMode;
        }

        return $payload;
    }

    /** 04_MPL §5.1 domestic weight table (grams). */
    public static function maxWeightG(string $deliveryMode, bool $isPostacsomag = false): int
    {
        if ($isPostacsomag) {
            // Postacsomag: HA 10kg (domestic proposal pp.7-9; §5.1).
            return 10000;
        }
        switch (strtoupper($deliveryMode)) {
            case 'RA':
                return 1000000;
            case 'PM':
                return 30000;
            case 'CS':
            case 'PP':
                return 20000;
            case 'HA':
            default:
                return 40000;
        }
    }

    /** 04_MPL §5.3: Hungarian E.164 (`+36…`, total ≤14, digits only). */
    public static function isHuE164(string $phone): bool
    {
        if (strlen($phone) > 14) {
            return false;
        }
        return (bool) preg_match('/^\+36\d{6,12}$/', $phone);
    }

    /**
     * 04_MPL §5.2 customs line shape: produceCount, produceName, produceValue, tariffCode (6/8/10 digits),
     * country, weightValue.
     *
     * @param array<string,mixed> $custom
     */
    public static function isValidCustomsLine(array $custom): bool
    {
        if ((int) ($custom['produceCount'] ?? 0) <= 0) {
            return false;
        }
        if (trim((string) ($custom['produceName'] ?? '')) === '') {
            return false;
        }
        if ((int) ($custom['produceValue'] ?? 0) < 0) {
            return false;
        }
        $tariff = trim((string) ($custom['tariffCode'] ?? ''));
        if (!preg_match('/^\d{6}(\d{2}(\d{2})?)?$/', $tariff)) {
            return false;
        }
        if (strlen(trim((string) ($custom['country'] ?? ''))) !== 2) {
            return false;
        }
        if ((int) ($custom['weightValue'] ?? 0) <= 0) {
            return false;
        }
        return true;
    }
}
