<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

/**
 * PURE MyGLS shipment mapper — no WordPress, no HTTP, no FluentCart. It validates a normalized snapshot
 * against the documented MyGLS limits and builds the `ParcelList[]` entry for GLS-013/GLS-017, then
 * interprets the `PrepareLabels`/`PrintLabels` responses (04_GLS_CONTRACT.md §4).
 *
 * Every rule/field cites 04_GLS_CONTRACT.md:
 *  - `ClientReference` is the caller-supplied correlation key (never a server idempotency token, §7);
 *  - `WebshopEngine` REQUIRED on PrepareLabels/PrintLabels (§2.1, pp.17,23);
 *  - domestic `DeliveryAddress` {Name, Street, City, ZipCode, CountryIsoCode, HouseNumber, HouseNumberInfo}
 *    (§3.1 p.9); HouseNumber cannot be 0 (error 23, §6.2);
 *  - PSD point delivery additionally requires `DeliveryAddress.ContactName/ContactPhone/ContactEmail`
 *    (§3.2 p.36) + `PSDParameter(StringValue, IntegerValue=DropOffPoint ID)` (§3.2 p.36);
 *  - `Count` default 1, maximum 99 (error 29, §5); INS cannot use multi-count (error 28, §5);
 *  - `ParcelPropertyList` {Content, PackageType 1-7, Height, Length, Width, Weight}; PackageType
 *    1 Colli 2 Box 3 Roll 4 Can 5 Case 6 Reel 7 Sack (§5 p.11); XXL max 80 kg/parcel (XXL p.4) — general
 *    non-XXL weight/dimension limits are MISSING_DOC (GLS-UNK-03 / GLS-906);
 *  - COD `CODAmount`/`CODReference`/`CODCurrency`; CODAmount >= 0 (error 8) and currency ISO validated
 *    (error 48) (§3.6/§6.2/§5); per-country COD ranges are NOT in the current doc (GLS-UNK-02) — enforced by
 *    settings via {@see \FluentCartHungary\Modules\Shipping\Carrier\CodValueLimits};
 *  - service dependencies: FSS without FDS (error 30), SRS only HU/SI, LRS only HU, INS Count must be 1
 *    (§3.6, §6.2);
 *  - printer types `A4_2x2, A4_4x1, Connect, Thermo, ThermoZPL, ShipItThermoPdf, ThermoZPL_300DPI,
 *    ShipItThermoZpl`; PrintPosition 1-4, A4 only (§3.4).
 *
 * Money is integer ISO minor units (HUF = forint); the mapper never applies a global ×100/÷100.
 * The mapper returns ['ok'=>bool,'errors'=>list<string>,'payload'=>array].
 */
final class GlsMapper
{
    public const DELIVERY_MODES = ['HOME', 'PSD'];

    /** 04_GLS §5 p.11: PackageType 1..7. */
    public const PACKAGE_TYPES = [1, 2, 3, 4, 5, 6, 7];

    /** 04_GLS §3.4 pp.19,23: TypeOfPrinter enum. */
    public const TYPES_OF_PRINTER = [
        'A4_2x2', 'A4_4x1', 'Connect', 'Thermo', 'ThermoZPL',
        'ShipItThermoPdf', 'ThermoZPL_300DPI', 'ShipItThermoZpl',
    ];

    /** 04_GLS §3.6 Appendix B pp.36-37 service set (+XXL USM). */
    public const SERVICES = [
        '24H', 'ADR', 'AOS', 'COD', 'CS1', 'DDS', 'DPV', 'FDS', 'FSS', 'INS', 'PRS', 'PSD', 'PSS',
        'SAT', 'SDS', 'SM1', 'SM2', 'SRS', 'SZL', 'T09', 'T10', 'T12', 'TGS', 'XS', 'LRS', 'USM',
    ];

    /** 04_GLS §5: Count default 1, maximum 99 (error 29). */
    public const MAX_COUNT = 99;

    /** XXL p.4: maximum 80 kg/parcel; only enforced when the parcel is flagged XXL. */
    public const XXL_MAX_WEIGHT_G = 80000;

    /** Country-limited services (§3.6). */
    private const SRS_COUNTRIES = ['HU', 'SI'];

    /**
     * @param array<string,mixed> $snapshot normalized shipment snapshot (see class docblock)
     * @return array{ok:bool,errors:list<string>,payload:array<string,mixed>}
     */
    public static function build(array $snapshot): array
    {
        $errors = self::validate($snapshot);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'payload' => []];
        }

        return ['ok' => true, 'errors' => [], 'payload' => self::compose($snapshot)];
    }

    /**
     * Validate the snapshot against the documented limits. Returns stable machine codes (never throws) so
     * the caller can record a LOCAL_ERROR before any dispatch.
     *
     * @param array<string,mixed> $snapshot
     * @return list<string>
     */
    public static function validate(array $snapshot): array
    {
        $errors = [];

        if (trim((string) ($snapshot['idempotency_key'] ?? '')) === '') {
            $errors[] = 'missing_idempotency_key';
        }
        if (trim((string) ($snapshot['webshop_engine'] ?? '')) === '') {
            // §2.1: WebshopEngine REQUIRED on PrepareLabels/PrintLabels.
            $errors[] = 'missing_webshop_engine';
        }

        $recipient = (array) ($snapshot['recipient'] ?? []);
        $name = trim((string) ($recipient['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'missing_recipient_name';
        }
        $country = strtoupper(trim((string) ($recipient['country'] ?? 'HU')));
        if (strlen($country) !== 2) {
            $errors[] = 'invalid_country_iso';
        }
        $isDomestic = $country === 'HU';

        $address = (array) ($recipient['address'] ?? []);
        if (trim((string) ($address['street'] ?? '')) === '') {
            $errors[] = 'missing_street';
        }
        if (trim((string) ($address['city'] ?? '')) === '') {
            $errors[] = 'missing_city';
        }
        if (trim((string) ($address['zip'] ?? '')) === '') {
            $errors[] = 'missing_zip';
        }
        // Error 23: house number cannot be 0 (§6.2). Empty is left to the provider.
        $houseNumber = trim((string) ($address['house_number'] ?? ''));
        if ($houseNumber !== '' && (int) $houseNumber === 0) {
            $errors[] = 'error_23_house_number_zero';
        }

        $deliveryMode = strtoupper(trim((string) ($snapshot['delivery_mode'] ?? 'HOME')));
        if (!in_array($deliveryMode, self::DELIVERY_MODES, true)) {
            $errors[] = 'invalid_delivery_mode';
        }

        // §3.3 p.36: PRS/PSS import/export directions require DeliveryAddress.ContactPhone.
        $phone = trim((string) ($recipient['phone'] ?? ''));
        if (!$isDomestic && $phone === '') {
            $errors[] = 'missing_phone_for_international';
        }

        // §3.2: PSD additionally requires ContactName + ContactPhone + ContactEmail and the point id.
        $pickup = (array) ($snapshot['pickup_point'] ?? []);
        if ($deliveryMode === 'PSD') {
            if (trim((string) ($pickup['point_id'] ?? '')) === '') {
                $errors[] = 'missing_psd_point_id';
            }
            if (trim((string) ($pickup['contact_name'] ?? $recipient['name'] ?? '')) === '') {
                $errors[] = 'missing_contact_name_for_psd';
            }
            if (trim((string) ($pickup['contact_phone'] ?? $phone)) === '') {
                $errors[] = 'missing_contact_phone_for_psd';
            }
            if (trim((string) ($pickup['contact_email'] ?? $recipient['email'] ?? '')) === '') {
                $errors[] = 'missing_contact_email_for_psd';
            }
        }

        if (!in_array((string) ($snapshot['type_of_printer'] ?? 'A4_2x2'), self::TYPES_OF_PRINTER, true)) {
            $errors[] = 'invalid_type_of_printer';
        }
        $printPosition = (int) ($snapshot['print_position'] ?? 1);
        if ($printPosition < 1 || $printPosition > 4) {
            $errors[] = 'invalid_print_position';
        }

        $services = self::normalizeServices((array) ($snapshot['services'] ?? []));
        // §3.6/§6.2 error 30: FSS (SMS) not available without FDS (email).
        if (in_array('FSS', $services, true) && !in_array('FDS', $services, true)) {
            $errors[] = 'error_30_fss_without_fds';
        }
        if (in_array('SRS', $services, true) && !in_array($country, self::SRS_COUNTRIES, true)) {
            $errors[] = 'srs_country_not_allowed';
        }
        if (in_array('LRS', $services, true) && $country !== 'HU') {
            $errors[] = 'lrs_country_not_allowed';
        }

        $parcels = (array) ($snapshot['parcels'] ?? []);
        if ($parcels === []) {
            $errors[] = 'missing_parcels';
        }

        foreach ($parcels as $parcel) {
            $parcel = (array) $parcel;
            $count = (int) ($parcel['count'] ?? 1);
            if ($count < 1 || $count > self::MAX_COUNT) {
                // §5 error 29: count 1..99.
                $errors[] = 'error_29_count_out_of_range';
            }
            $weight = (int) ($parcel['weight_g'] ?? 0);
            if ($weight <= 0) {
                $errors[] = 'parcel_weight_missing';
            } elseif (!empty($parcel['xxl']) && $weight > self::XXL_MAX_WEIGHT_G) {
                // XXL p.4: maximum 80 kg/parcel.
                $errors[] = 'weight_over_limit_xxl';
            }

            $packageType = (int) ($parcel['package_type'] ?? 1);
            if (!in_array($packageType, self::PACKAGE_TYPES, true)) {
                $errors[] = 'invalid_package_type';
            }

            $cod = (int) ($parcel['cod_minor'] ?? 0);
            if ($cod < 0) {
                // §3.6/§6.2 error 8: CODAmount >= 0.
                $errors[] = 'cod_negative';
            }
            if ($cod > 0) {
                $currency = strtoupper(trim((string) ($parcel['cod_currency'] ?? ($snapshot['currency'] ?? ''))));
                if (strlen($currency) !== 3) {
                    // §6.2 error 48: invalid COD currency.
                    $errors[] = 'error_48_invalid_cod_currency';
                }
            }

            if (in_array('INS', $services, true)) {
                // §5 error 28: Count must be 1 because of the INS service.
                if ($count > 1) {
                    $errors[] = 'error_28_ins_single_count';
                }
                if ((int) ($parcel['value_minor'] ?? 0) <= 0) {
                    $errors[] = 'ins_requires_declared_value';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Build the exact non-auth request payload for GLS-013/GLS-017: `ParcelList[]` + `WebshopEngine`
     * + optional `PrintOptions` for PrintLabels (PrintPosition/ShowPrintDialog/TypeOfPrinter/
     * HidePhoneNumberOnLabels). Assumes validate() passed.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function compose(array $snapshot): array
    {
        $recipient = (array) ($snapshot['recipient'] ?? []);
        $address = (array) ($recipient['address'] ?? []);
        $country = strtoupper((string) ($recipient['country'] ?? 'HU'));
        $deliveryMode = strtoupper((string) ($snapshot['delivery_mode'] ?? 'HOME'));
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'HUF'));
        $clientReference = (string) ($snapshot['client_reference'] ?? $snapshot['idempotency_key'] ?? '');
        $services = self::normalizeServices((array) ($snapshot['services'] ?? []));
        $pickup = (array) ($snapshot['pickup_point'] ?? []);

        $parcels = [];
        foreach ((array) ($snapshot['parcels'] ?? []) as $parcel) {
            $parcel = (array) $parcel;
            $count = max(1, (int) ($parcel['count'] ?? 1));
            $weightG = max(0, (int) ($parcel['weight_g'] ?? 0));
            $cod = max(0, (int) ($parcel['cod_minor'] ?? 0));

            $properties = [
                'PackageType' => (int) ($parcel['package_type'] ?? 1),
                // §5 p.11/XXL: Weight is a decimal (kg); grams are never sent.
                'Weight' => round($weightG / 1000, 3),
            ];
            $content = trim((string) ($parcel['content'] ?? ''));
            if ($content !== '') {
                // §2.3: Content is optionally REQUIRED (user right) and MANDATORY in Serbia.
                $properties['Content'] = $content;
            }
            $dims = (array) ($parcel['dims_mm'] ?? []);
            foreach (['Height', 'Length', 'Width'] as $dim) {
                $key = strtolower($dim);
                if (isset($dims[$key]) && (int) $dims[$key] > 0) {
                    $properties[$dim] = (int) $dims[$key];
                }
            }

            $addressPayload = [
                'Name' => (string) ($recipient['name'] ?? ''),
                'Street' => (string) ($address['street'] ?? ''),
                'City' => (string) ($address['city'] ?? ''),
                'ZipCode' => (string) ($address['zip'] ?? ''),
                'CountryIsoCode' => $country,
            ];
            foreach (['house_number' => 'HouseNumber', 'house_number_info' => 'HouseNumberInfo'] as $from => $to) {
                $value = trim((string) ($address[$from] ?? ''));
                if ($value !== '') {
                    $addressPayload[$to] = $value;
                }
            }

            if ($deliveryMode === 'PSD') {
                // §3.2 p.36: PSD requires ContactName/ContactPhone/ContactEmail.
                $addressPayload['ContactName'] = (string) ($pickup['contact_name'] ?? $recipient['name'] ?? '');
                $addressPayload['ContactPhone'] = (string) ($pickup['contact_phone'] ?? $recipient['phone'] ?? '');
                $addressPayload['ContactEmail'] = (string) ($pickup['contact_email'] ?? $recipient['email'] ?? '');
            }

            $parcelPayload = [
                'ClientReference' => $clientReference,
                'Count' => $count,
                'DeliveryAddress' => $addressPayload,
                'ParcelPropertyList' => [$properties],
            ];

            if ($cod > 0) {
                $parcelPayload['CODAmount'] = $cod;
                $parcelPayload['CODCurrency'] = strtoupper(trim((string) ($parcel['cod_currency'] ?? $currency)));
                $ref = trim((string) ($parcel['cod_reference'] ?? ''));
                if ($ref !== '') {
                    $parcelPayload['CODReference'] = $ref;
                }
            }

            if ($deliveryMode === 'PSD') {
                $pointId = trim((string) ($pickup['point_id'] ?? ''));
                $zip = (string) ($address['zip'] ?? ($pickup['zip'] ?? ''));
                // §3.2 p.36: PSDParameter(StringValue = "<zip>-<shop>", IntegerValue = DropOffPoint ID).
                $parcelPayload['PSDParameter'] = [
                    'StringValue' => $zip !== '' ? $zip . '-' . $pointId : $pointId,
                    'IntegerValue' => (int) $pointId,
                ];
                if (!in_array('PSD', $services, true)) {
                    $services[] = 'PSD';
                }
            }

            if ($services !== []) {
                $parcelPayload['ServiceList'] = array_values(array_unique($services));
            }

            if (isset($snapshot['pickup_date']) && trim((string) $snapshot['pickup_date']) !== '') {
                $parcelPayload['PickupDate'] = (string) $snapshot['pickup_date'];
            }
            if (isset($snapshot['pickup_address']) && is_array($snapshot['pickup_address'])) {
                $parcelPayload['PickupAddress'] = $snapshot['pickup_address'];
            }

            $parcels[] = $parcelPayload;
        }

        return [
            'ParcelList' => $parcels,
            'WebshopEngine' => (string) ($snapshot['webshop_engine'] ?? ''),
            'PrintOptions' => [
                'PrintPosition' => (int) ($snapshot['print_position'] ?? 1),
                'ShowPrintDialog' => (bool) ($snapshot['show_print_dialog'] ?? false),
                'TypeOfPrinter' => (string) ($snapshot['type_of_printer'] ?? 'A4_2x2'),
                'HidePhoneNumberOnLabels' => (bool) ($snapshot['hide_phone_number'] ?? false),
            ],
        ];
    }

    // ---- response interpretation --------------------------------------------------------------------

    /**
     * Interpret a GLS-017 `PrintLabels` (or GLS-015 `GetPrintedLabels`) JSON response.
     *
     * @param array<string,mixed> $data decoded response
     * @return array{ok:bool,result:string,label_base64:?string,errors:list<array{code:string,message:string}>,parcels:list<array<string,mixed>>}
     */
    public static function interpretPrintResponse(array $data): array
    {
        $errors = self::collectErrors($data);
        $label = self::firstLabelBase64($data);
        $parcels = self::collectParcelInfos($data);

        if ($errors !== []) {
            return ['ok' => false, 'result' => 'rejected', 'label_base64' => $label, 'errors' => $errors, 'parcels' => $parcels];
        }
        if ($label === null && $parcels === []) {
            return ['ok' => false, 'result' => 'empty', 'label_base64' => null, 'errors' => [], 'parcels' => []];
        }

        return ['ok' => true, 'result' => 'success', 'label_base64' => $label, 'errors' => [], 'parcels' => $parcels];
    }

    /**
     * Interpret a GLS-013/GLS-014 `PrepareLabels` response: `ParcelInfoList[]` (ClientReference, ParcelId)
     * plus `PrepareLabelsError[]`.
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,errors:list<array{code:string,message:string}>,parcels:list<array<string,mixed>>}
     */
    public static function interpretPrepareResponse(array $data): array
    {
        $errors = self::collectErrors($data);
        $parcels = self::collectParcelInfos($data);
        return ['ok' => $errors === [] && $parcels !== [], 'errors' => $errors, 'parcels' => $parcels];
    }

    /**
     * Collect `ErrorInfo[]` chunks (ErrorCode, ErrorDescription, ClientReferenceList[], ParcelIdList[]).
     * Scans the documented per-operation error container names (§4; §6.2).
     *
     * @param array<string,mixed> $data
     * @return list<array{code:string,message:string}>
     */
    public static function collectErrors(array $data): array
    {
        $containers = [
            'PrepareLabelsError', 'PrepareLabelsErrorList',
            'GetPrintedLabelsErrorList', 'PrintLabelsErrorList',
            'ErrorInfoList', 'ErrorInfo', 'Errors', 'ErrorList',
        ];
        $errors = [];
        foreach ($containers as $key) {
            $raw = $data[$key] ?? null;
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    if (is_scalar($entry) && (string) $entry !== '') {
                        $errors[] = ['code' => (string) $entry, 'message' => ''];
                    }
                    continue;
                }
                $code = (string) ($entry['ErrorCode'] ?? $entry['errorCode'] ?? '');
                $message = (string) ($entry['ErrorDescription'] ?? $entry['errorDescription'] ?? '');
                if ($code !== '' || $message !== '') {
                    $errors[] = ['code' => $code !== '' ? $code : 'GLS_ERROR', 'message' => $message];
                }
            }
        }
        return $errors;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    public static function collectParcelInfos(array $data): array
    {
        $containers = ['PrintLabelsInfoList', 'ParcelInfoList', 'PrintDataInfoList', 'ParcelList', 'ParcelStatuses'];
        $sources = [];
        foreach ($containers as $key) {
            $raw = $data[$key] ?? null;
            if (is_array($raw)) {
                $sources[] = array_is_list($raw) ? $raw : [$raw];
            }
        }
        // A bare JSON array response (GetParcelList) is itself the list.
        if ($sources === [] && array_is_list($data)) {
            $sources[] = $data;
        }

        $parcels = [];
        foreach ($sources as $list) {
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $parcelId = (int) ($entry['ParcelId'] ?? 0);
                $parcelNumber = trim((string) ($entry['ParcelNumber'] ?? ''));
                if ($parcelId <= 0 && $parcelNumber === '') {
                    continue;
                }
                $barcode = trim((string) ($entry['ParcelNumberWithCheckdigit'] ?? ''));
                $parcels[] = [
                    'parcel_id' => $parcelId,
                    'parcel_number' => $parcelNumber,
                    'barcode' => $barcode !== '' ? $barcode : $parcelNumber,
                    'client_reference' => trim((string) ($entry['ClientReference'] ?? '')),
                    'pin' => trim((string) ($entry['PIN'] ?? '')),
                ];
            }
        }
        return array_values($parcels);
    }

    /**
     * `Labels` (GLS-015/017) / `Pdfdocument` (GLS-016) may be a base64 string or a list of strings.
     *
     * @param array<string,mixed> $data
     */
    public static function firstLabelBase64(array $data): ?string
    {
        foreach (['Labels', 'Pdfdocument', 'Label'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::stripDataUri($value);
            }
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        return self::stripDataUri($entry);
                    }
                }
            }
        }
        return null;
    }

    /** Decode a base64 label/document to raw bytes (never logs the payload). */
    public static function extractLabelBinary(?string $base64): ?string
    {
        $base64 = self::stripDataUri((string) $base64);
        if ($base64 === '') {
            return null;
        }
        $binary = base64_decode($base64, true);
        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    // ---- helpers ------------------------------------------------------------------------------------

    /** @param array<mixed> $services @return list<string> */
    private static function normalizeServices(array $services): array
    {
        $out = [];
        foreach ($services as $service) {
            $service = strtoupper(trim((string) $service));
            if ($service !== '' && !in_array($service, $out, true)) {
                $out[] = $service;
            }
        }
        return $out;
    }

    private static function stripDataUri(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma !== false) {
                $value = substr($value, $comma + 1);
            }
        }
        return $value;
    }

    /** True when a service code is part of the documented set (informational validation helper). */
    public static function isKnownService(string $service): bool
    {
        return in_array(strtoupper(trim($service)), self::SERVICES, true);
    }
}
