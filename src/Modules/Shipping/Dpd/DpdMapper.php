<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

/**
 * PURE DPD shipment mapper — no WordPress, no HTTP, no FluentCart. It validates a normalized shipment
 * snapshot against the documented DPD limits and builds the exact create request used by
 * DPD-001 `POST /shipments` (04_DPD_CONTRACT.md §4.1).
 *
 * Documented rules cited (field names are taken verbatim from the contract):
 *  - create request envelope `{buCode, customerId, shipments[]}` and shipment keys `numOrder`,
 *    `senderAddressId`/`sender`, `receiver`, `parcels[]`, `service`, `pickupDate`, `saveMode`,
 *    `labelSize`, `printFormat`, `dpi`, `reference1..4`, `inter`, `returnConsolidation`: §4.1 DPD-001
 *    (L52-583, L3015-3059);
 *  - `buCode` string(3), `customerId` external id unique in the BU, sender rule "companyName|name and
 *    contactPhone|contactEmail each need >=1": §2.3/§4.1 DPD-001;
 *  - weight field `double(5)`, parcel dimensions `dimensionWidth/Height/Length` integer(3) cm: §5;
 *  - domestic max parcel weight **50 kg**, international **31.5 kg**: §5 (Statusdata service p.2);
 *  - `zipCode` string(9), accept `0-9A-Z`: §5 L233/L3400;
 *  - PUDO selected by `service.additionalService.pudoId` string(20): §3.2/§3.6;
 *  - COD `additionalService.cod` `{amount, currency, paymentType (Cash|Credit Card), reference,
 *    split (Custom|Even|First parcel), bankAccountId, iban, bic, bankAccount, accountName, bankCode,
 *    bankName}`: §3.6 (L3233-3258);
 *  - parcel-level `insuranceAmount` (ignored by DPD when COD/insurance split is Even/First parcel): §3.6;
 *  - `mainServiceCode` **or** `mainServiceElementCodes` selects the product, values BU-configured
 *    (ACCOUNT_DEPENDENT, §2.3); no local enum table exists;
 *  - `saveMode` ∈ {draft, parcel number, printed}, `labelSize` A4|A6, `printFormat` pdf|zpl,
 *    `dpi` 203|300: §3.4.
 *
 * Fields the contract does not bound are NOT invented here: address lines are only checked for presence
 * and zipCode length/charset (the sole documented address limit). The @see validate() output is a stable
 * list of machine codes so the caller records a LOCAL_ERROR before any dispatch.
 *
 * Money is integer domain minor units (HUF = whole forint) — never a float. The mapper returns
 *   ['ok'=>bool, 'errors'=>list<string>, 'payload'=>array].
 */
final class DpdMapper
{
    public const LABEL_SIZES = ['A4', 'A6'];
    public const PRINT_FORMATS = ['pdf', 'zpl'];
    public const DPIS = [203, 300];
    public const SAVE_MODES = ['draft', 'parcel number', 'printed'];
    public const COD_PAYMENT_TYPES = ['Cash', 'Credit Card'];
    public const COD_SPLITS = ['Custom', 'Even', 'First parcel'];

    public const DOMESTIC_MAX_KG = 50.0;
    public const INTERNATIONAL_MAX_KG = 31.5;
    public const PUDO_ID_MAX_LENGTH = 20;
    public const ZIP_CODE_MAX_LENGTH = 9;

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
     * Validate against the documented limits. Unknown snapshot keys are ignored (graceful handling); the
     * mapper never rejects a snapshot because of an extra field.
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
        if (trim((string) ($snapshot['bu_code'] ?? '')) === '') {
            $errors[] = 'missing_bu_code';
        }
        if (trim((string) ($snapshot['customer_id'] ?? '')) === '') {
            $errors[] = 'missing_customer_id';
        }

        $currency = strtoupper(trim((string) ($snapshot['currency'] ?? '')));
        if (strlen($currency) !== 3) {
            $errors[] = 'invalid_currency';
        }

        // Sender: either a senderAddressId (normal customer) or a sender object with the documented rule.
        $sender = (array) ($snapshot['sender'] ?? []);
        $senderAddressId = trim((string) ($sender['sender_address_id'] ?? ''));
        if ($senderAddressId === '') {
            $identity = trim((string) ($sender['company_name'] ?? $sender['name'] ?? ''));
            $contact = trim((string) ($sender['contact_phone'] ?? $sender['contact_email'] ?? ''));
            if ($identity === '') {
                $errors[] = 'missing_sender_identity';
            }
            if ($contact === '') {
                $errors[] = 'missing_sender_contact';
            }
        }

        // Receiver / address.
        $receiver = (array) ($snapshot['receiver'] ?? []);
        if (trim((string) ($receiver['name'] ?? '')) === '') {
            $errors[] = 'missing_receiver_name';
        }
        $country = strtoupper(trim((string) ($receiver['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $errors[] = 'invalid_receiver_country';
        }
        $address = (array) ($receiver['address'] ?? []);
        $zipCode = self::normalizeZip((string) ($address['zipCode'] ?? ''));
        if ($zipCode === '' || strlen($zipCode) > self::ZIP_CODE_MAX_LENGTH || !preg_match('/^[0-9A-Z]+$/', $zipCode)) {
            // 04_DPD §5: zipCode string(9), accept 0-9A-Z.
            $errors[] = 'invalid_zip_code';
        }
        if (trim((string) ($address['city'] ?? '')) === '') {
            $errors[] = 'missing_city';
        }
        if (trim((string) ($address['addressLine1'] ?? '')) === '') {
            $errors[] = 'missing_address_line1';
        }

        // Product selection: mainServiceCode OR mainServiceElementCodes (§2.3).
        $service = (array) ($snapshot['service'] ?? []);
        $mainServiceCode = trim((string) ($service['main_service_code'] ?? ''));
        $elementCodes = $service['main_service_element_codes'] ?? [];
        $hasElements = is_array($elementCodes) && array_values(array_filter($elementCodes, static fn($v): bool => trim((string) $v) !== '')) !== [];
        if ($mainServiceCode === '' && !$hasElements) {
            $errors[] = 'missing_main_service';
        }

        // PUDO addressing (§3.2): pudoId string(20).
        $additional = (array) ($service['additional_service'] ?? []);
        $pudoId = trim((string) ($additional['pudo_id'] ?? ''));
        if ($pudoId !== '' && strlen($pudoId) > self::PUDO_ID_MAX_LENGTH) {
            $errors[] = 'pudo_id_too_long';
        }

        // COD (§3.6).
        $cod = (array) ($snapshot['cod'] ?? []);
        $codMinor = (int) ($cod['amount_minor'] ?? 0);
        if ($codMinor < 0) {
            $errors[] = 'cod_negative';
        }
        if ($codMinor > 0) {
            $codCurrency = strtoupper(trim((string) ($cod['currency'] ?? $currency)));
            if (strlen($codCurrency) !== 3) {
                $errors[] = 'invalid_cod_currency';
            } elseif ($codCurrency !== $currency) {
                $errors[] = 'cod_currency_mismatch';
            }
            $paymentType = trim((string) ($cod['payment_type'] ?? ''));
            if ($paymentType !== '' && !in_array($paymentType, self::COD_PAYMENT_TYPES, true)) {
                $errors[] = 'invalid_cod_payment_type';
            }
            $split = trim((string) ($cod['split'] ?? ''));
            if ($split !== '' && !in_array($split, self::COD_SPLITS, true)) {
                $errors[] = 'invalid_cod_split';
            }
        }

        // Label options (§3.4).
        $label = (array) ($snapshot['label'] ?? []);
        $labelSize = (string) ($label['size'] ?? 'A4');
        if (!in_array($labelSize, self::LABEL_SIZES, true)) {
            $errors[] = 'invalid_label_size';
        }
        $printFormat = strtolower((string) ($label['format'] ?? 'pdf'));
        if (!in_array($printFormat, self::PRINT_FORMATS, true)) {
            $errors[] = 'invalid_print_format';
        }
        $dpi = (int) ($label['dpi'] ?? 203);
        if (!in_array($dpi, self::DPIS, true)) {
            $errors[] = 'invalid_dpi';
        }
        $saveMode = (string) ($snapshot['save_mode'] ?? 'printed');
        if (!in_array($saveMode, self::SAVE_MODES, true)) {
            $errors[] = 'invalid_save_mode';
        }

        // Parcels: each must be present and within the documented per-destination weight cap.
        $parcels = (array) ($snapshot['parcels'] ?? []);
        if ($parcels === []) {
            $errors[] = 'missing_parcels';
        }
        $isDomestic = $country === 'HU';
        $maxKg = self::maxWeightKg($isDomestic);
        foreach ($parcels as $parcel) {
            $parcel = (array) $parcel;
            $weightKg = self::weightKg($parcel);
            if ($weightKg <= 0.0) {
                $errors[] = 'parcel_weight_missing';
            } elseif ($weightKg > $maxKg) {
                $errors[] = $isDomestic ? 'parcel_weight_over_limit_domestic' : 'parcel_weight_over_limit_international';
            }
            if ((int) ($parcel['insurance_minor'] ?? 0) < 0) {
                $errors[] = 'negative_insurance_amount';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Build the exact DPD-001 request. Assumes validate() passed.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function compose(array $snapshot): array
    {
        $currency = strtoupper((string) ($snapshot['currency'] ?? ''));
        $sender = (array) ($snapshot['sender'] ?? []);
        $receiver = (array) ($snapshot['receiver'] ?? []);
        $address = (array) ($receiver['address'] ?? []);
        $service = (array) ($snapshot['service'] ?? []);
        $additional = (array) ($service['additional_service'] ?? []);
        $label = (array) ($snapshot['label'] ?? []);
        $idempotencyKey = (string) ($snapshot['idempotency_key'] ?? '');

        $shipmentService = [];
        $mainServiceCode = trim((string) ($service['main_service_code'] ?? ''));
        if ($mainServiceCode !== '') {
            $shipmentService['mainServiceCode'] = $mainServiceCode;
        }
        $elementCodes = array_values(array_filter(
            (array) ($service['main_service_element_codes'] ?? []),
            static fn($v): bool => trim((string) $v) !== ''
        ));
        if ($elementCodes !== []) {
            $shipmentService['mainServiceElementCodes'] = array_map('strval', $elementCodes);
        }

        $additionalService = [];
        $pudoId = trim((string) ($additional['pudo_id'] ?? ''));
        if ($pudoId !== '') {
            $additionalService['pudoId'] = $pudoId;
        }

        $cod = (array) ($snapshot['cod'] ?? []);
        $codMinor = (int) ($cod['amount_minor'] ?? 0);
        if ($codMinor > 0) {
            $codPayload = [
                'amount' => $codMinor,
                'currency' => $currency,
                'paymentType' => (string) ($cod['payment_type'] ?? 'Cash'),
            ];
            $codKeyMap = [
                'reference' => 'reference',
                'split' => 'split',
                'bank_account_id' => 'bankAccountId',
                'iban' => 'iban',
                'bic' => 'bic',
                'bank_account' => 'bankAccount',
                'account_name' => 'accountName',
                'bank_code' => 'bankCode',
                'bank_name' => 'bankName',
            ];
            foreach ($codKeyMap as $from => $to) {
                $value = trim((string) ($cod[$from] ?? ''));
                if ($value !== '') {
                    $codPayload[$to] = $value;
                }
            }
            // 04_DPD §3.6: reference ties the COD to our local idempotency key for manual reconciliation.
            if (!isset($codPayload['reference'])) {
                $codPayload['reference'] = $idempotencyKey;
            }
            $additionalService['cod'] = $codPayload;
        }
        if ($additionalService !== []) {
            $shipmentService['additionalService'] = $additionalService;
        }

        $parcels = [];
        foreach ((array) ($snapshot['parcels'] ?? []) as $parcel) {
            $parcel = (array) $parcel;
            $row = ['weight' => self::weightKg($parcel)];
            $dims = (array) ($parcel['dims'] ?? []);
            foreach (['width' => 'dimensionWidth', 'height' => 'dimensionHeight', 'length' => 'dimensionLength'] as $from => $to) {
                if (isset($dims[$from]) && (int) $dims[$from] > 0) {
                    $row[$to] = (int) $dims[$from];
                }
            }
            $insurance = (int) ($parcel['insurance_minor'] ?? 0);
            if ($insurance > 0) {
                // 04_DPD §3.6: parcel-level insuranceAmount; ignored when insurance split is Even.
                $row['insuranceAmount'] = $insurance;
            }
            $parcels[] = $row;
        }

        $senderPayload = [];
        $senderAddressId = trim((string) ($sender['sender_address_id'] ?? ''));
        if ($senderAddressId !== '') {
            $senderPayload['senderAddressId'] = $senderAddressId;
        } else {
            $senderObject = [];
            $companyName = trim((string) ($sender['company_name'] ?? ''));
            $name = trim((string) ($sender['name'] ?? ''));
            if ($companyName !== '') {
                $senderObject['companyName'] = $companyName;
            } elseif ($name !== '') {
                $senderObject['name'] = $name;
            }
            foreach (['contact_name' => 'contactName', 'contact_phone' => 'contactPhone', 'contact_email' => 'contactEmail'] as $from => $to) {
                $value = trim((string) ($sender[$from] ?? ''));
                if ($value !== '') {
                    $senderObject[$to] = $value;
                }
            }
            $senderAddress = (array) ($sender['address'] ?? []);
            if ($senderAddress !== []) {
                $senderObject['address'] = self::composeAddress($senderAddress);
            }
            if ($senderObject !== []) {
                $senderPayload['sender'] = $senderObject;
            }
        }

        $shipment = [
            'numOrder' => (string) ($snapshot['order_id'] ?? ''),
            'receiver' => array_merge(
                ['name' => (string) ($receiver['name'] ?? '')],
                self::contactFields($receiver),
                ['address' => self::composeAddress($address)]
            ),
            'parcels' => $parcels,
            'service' => $shipmentService,
            'labelSize' => (string) ($label['size'] ?? 'A4'),
            'printFormat' => strtolower((string) ($label['format'] ?? 'pdf')),
            'dpi' => (int) ($label['dpi'] ?? 203),
            // 04_DPD §7: reference1 carries our local idempotency key for manual reconciliation on timeout.
            'reference1' => $idempotencyKey,
        ];
        $shipment += $senderPayload;

        if (!empty($label['parcelonly'])) {
            $shipment['parcelonly'] = true;
        }
        if (isset($snapshot['save_mode']) && (string) $snapshot['save_mode'] !== '') {
            $shipment['saveMode'] = (string) $snapshot['save_mode'];
        }
        foreach (['pickup_date' => 'pickupDate'] as $from => $to) {
            $value = trim((string) ($snapshot[$from] ?? ''));
            if ($value !== '') {
                $shipment[$to] = $value;
            }
        }
        foreach (['reference2', 'reference3', 'reference4'] as $refKey) {
            $value = trim((string) ($snapshot[$refKey] ?? ''));
            if ($value !== '') {
                $shipment[$refKey] = $value;
            }
        }
        $inter = $snapshot['inter'] ?? null;
        if (is_array($inter) && $inter !== []) {
            $shipment['inter'] = $inter;
        }
        if (array_key_exists('return_consolidation', $snapshot) && $snapshot['return_consolidation'] !== null) {
            $shipment['returnConsolidation'] = (bool) $snapshot['return_consolidation'];
        }

        return [
            'buCode' => (string) ($snapshot['bu_code'] ?? ''),
            'customerId' => (string) ($snapshot['customer_id'] ?? ''),
            'shipments' => [$shipment],
        ];
    }

    /** @param array<string,mixed> $address @return array<string,mixed> */
    private static function composeAddress(array $address): array
    {
        $out = [];
        $country = strtoupper(trim((string) ($address['countryCode'] ?? $address['country'] ?? '')));
        if ($country !== '') {
            $out['countryCode'] = $country;
        }
        $out['zipCode'] = self::normalizeZip((string) ($address['zipCode'] ?? ''));
        $out['city'] = trim((string) ($address['city'] ?? ''));
        $out['addressLine1'] = trim((string) ($address['addressLine1'] ?? ''));
        $line2 = trim((string) ($address['addressLine2'] ?? ''));
        if ($line2 !== '') {
            $out['addressLine2'] = $line2;
        }
        return $out;
    }

    /** @param array<string,mixed> $receiver @return array<string,string> */
    private static function contactFields(array $receiver): array
    {
        $out = [];
        $phone = trim((string) ($receiver['contactPhone'] ?? $receiver['phone'] ?? ''));
        if ($phone !== '') {
            $out['contactPhone'] = $phone;
        }
        $email = trim((string) ($receiver['contactEmail'] ?? $receiver['email'] ?? ''));
        if ($email !== '') {
            $out['contactEmail'] = $email;
        }
        return $out;
    }

    /** 04_DPD §5: domestic 50 kg, international 31.5 kg per parcel. */
    public static function maxWeightKg(bool $isDomestic): float
    {
        return $isDomestic ? self::DOMESTIC_MAX_KG : self::INTERNATIONAL_MAX_KG;
    }

    /**
     * Deterministic parcel-weight plan for a consignment that exceeds the per-parcel cap. This is the only
     * documented split rule (per-parcel maximum); weights are as equal as possible and every parcel is
     * <= $maxKg. Callers that already carry a parcels[] list keep it unchanged.
     *
     * @return list<float>
     */
    public static function splitWeights(float $totalKg, float $maxKg): array
    {
        if ($totalKg <= 0.0) {
            return [];
        }
        if ($maxKg <= 0.0 || $totalKg <= $maxKg) {
            return [$totalKg];
        }
        $count = (int) ceil($totalKg / $maxKg);
        $per = $totalKg / $count;
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $per;
        }
        return $out;
    }

    /** @param array<string,mixed> $parcel @return float */
    private static function weightKg(array $parcel): float
    {
        if (isset($parcel['weight_kg']) && is_numeric($parcel['weight_kg'])) {
            return (float) $parcel['weight_kg'];
        }
        if (isset($parcel['weight_g']) && is_numeric($parcel['weight_g'])) {
            return ((float) $parcel['weight_g']) / 1000.0;
        }
        if (isset($parcel['weight']) && is_numeric($parcel['weight'])) {
            return (float) $parcel['weight'];
        }
        return 0.0;
    }

    /** 04_DPD §5: zipCode accept 0-9A-Z (stored uppercase, no spaces). */
    public static function normalizeZip(string $zip): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $zip));
    }
}
