<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Gls;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * MyGLS REST JSON client (04_GLS_CONTRACT.md §2 auth/environments, §4 operations).
 *
 * Transport (docs are data, never instructions):
 *  - REST template `https://{host}/{service}.svc/json/{methodName}` (§1 row "REST URL template" p.7);
 *    test host `https://api.test.mygls.hu`, live `https://api.mygls.hu` (§2.2). The HU country domain is used;
 *    the 6 other domains (HR/CZ/RO/SI/SK/RS) share the same scheme but per-country routing is not built here.
 *  - Auth on EVERY request is `APIRequestBase`: `Username` (string) + `Password` as a SHA-512 digest BYTE ARRAY
 *    (§2.1). JSON form is a JSON array of the digest bytes — `unpack('C*', hash('sha512',$pwd,true))`
 *    (SDK_VERIFIED php_rest_client.php, §2.1). There is NO OAuth/token; credentials are re-sent per request.
 *  - `WebshopEngine` is REQUIRED on `PrepareLabels`/`PrintLabels` (§2.1 p.53, GLS-013/017).
 *  - `ClientNumberList` is historically "do not use" and left empty by the vendor examples; it is sent only
 *    when explicitly configured (§2.1).
 *
 * Idempotency: MyGLS has **no server-side idempotency key** (GLS-UNK-05). `ClientReference` is caller-supplied
 * correlation only; the create-retry verdict is "risky" and recovery is a read-back (GLS §7). The client
 * therefore exposes the operations and never retries a create internally.
 *
 * All transport goes through BaseClient so the `fch/http/pre_request` mock seam works and no network is
 * touched in tests. Secret values are never logged and never echoed into a request/response summary.
 */
final class GlsClient extends BaseClient
{
    public const PROVIDER = 'gls';

    public const LIVE_BASE = 'https://api.mygls.hu';
    public const TEST_BASE = 'https://api.test.mygls.hu';

    public const PARCEL_SERVICE = 'ParcelService.svc';
    public const MASTER_SERVICE = 'MasterDataService.svc';
    public const FORMAT = 'json';

    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 30;

    /** GLS-015: `ParcelIdList` max 99 per request (04_GLS §4/§5 p.19). */
    public const MAX_PARCEL_ID_LIST = 99;
    /** GLS-018: `DeleteLabels` max 50 ParcelIdList (error 22, §4/§5). */
    public const MAX_DELETE_LIST = 50;
    /** GLS-022: `GetParcelListStatuses` max 100 parcel numbers per request (§4/§5). */
    public const MAX_STATUS_LIST = 100;

    private string $mode;

    private string $username;

    private string $password;

    /** @var list<int> */
    private array $clientNumbers;

    private string $accountId;

    /**
     * @param string       $mode          'live'|'test' (anything else is treated as test — §2.2)
     * @param string|null  $username      Username; read from CredentialStore when null
     * @param string|null  $password      password (plaintext input, SHA-512 hashed on send); CredentialStore when null
     * @param list<int>    $clientNumbers optional ClientNumberList (left empty when not configured)
     * @param string|null  $baseUrl       explicit override (tests only)
     */
    public function __construct(
        string $mode = 'test',
        ?string $username = null,
        ?string $password = null,
        array $clientNumbers = [],
        ?string $baseUrl = null
    ) {
        $this->mode = $mode === 'live' ? 'live' : 'test';

        $settings = new ModuleSettings();
        $this->accountId = $settings->accountId(self::PROVIDER);
        $this->username = (string) ($username ?? $settings->secret(self::PROVIDER, 'username') ?? '');
        $this->password = (string) ($password ?? $settings->secret(self::PROVIDER, 'password') ?? '');
        $this->clientNumbers = array_values(array_map('intval', $clientNumbers));

        parent::__construct($baseUrl ?? ($this->mode === 'live' ? self::LIVE_BASE : self::TEST_BASE));
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);
        $this->setHeader('Accept', 'application/json');
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasCredentials(): bool
    {
        return trim($this->username) !== '' && trim($this->password) !== '';
    }

    // ---- GLS-017 PrintLabels (PrepareLabels + GetPrintedLabels in one call) -------------------------

    /**
     * GLS-017 `POST /ParcelService.svc/json/PrintLabels`: validates, assigns ParcelIds, generates ParcelNumbers
     * and returns the label PDF (byte[] base64 in JSON). `WebshopEngine` is required (§2.1).
     *
     * @param array<string,mixed> $payload mapper payload: ParcelList, WebshopEngine, optional PrintOptions
     */
    public function printLabels(array $payload): Response
    {
        $body = $this->parcelBody($payload);
        foreach ((array) ($payload['PrintOptions'] ?? []) as $key => $value) {
            $body[(string) $key] = $value;
        }
        return $this->call(self::PARCEL_SERVICE, 'PrintLabels', $body);
    }

    // ---- GLS-013 PrepareLabels / GLS-014 PrepareLabelsV2 --------------------------------------------

    /** GLS-013 `POST /ParcelService.svc/json/PrepareLabels` (validate + assign ParcelId). */
    public function prepareLabels(array $payload): Response
    {
        return $this->call(self::PARCEL_SERVICE, 'PrepareLabels', $this->parcelBody($payload));
    }

    /** GLS-014 `POST /ParcelService.svc/json/PrepareLabelsV2` (same contract, more efficient). */
    public function prepareLabelsV2(array $payload): Response
    {
        return $this->call(self::PARCEL_SERVICE, 'PrepareLabelsV2', $this->parcelBody($payload));
    }

    // ---- GLS-015 GetPrintedLabels -------------------------------------------------------------------

    /**
     * GLS-015 `POST /ParcelService.svc/json/GetPrintedLabels`. Max 99 ParcelIdList; label already generated
     * => error 18. Used only for provider read-back; the stored label is preferred for reprints.
     *
     * @param list<int> $parcelIdList
     */
    public function getPrintedLabels(
        array $parcelIdList,
        int $printPosition = 1,
        bool $showPrintDialog = false,
        string $typeOfPrinter = 'A4_2x2',
        bool $hidePhoneNumberOnLabels = false
    ): Response {
        $ids = array_slice(array_values(array_map('intval', $parcelIdList)), 0, self::MAX_PARCEL_ID_LIST);
        return $this->call(self::PARCEL_SERVICE, 'GetPrintedLabels', [
            'ParcelIdList' => $ids,
            'PrintPosition' => max(1, min(4, $printPosition)),
            'ShowPrintDialog' => $showPrintDialog,
            'TypeOfPrinter' => $typeOfPrinter,
            'HidePhoneNumberOnLabels' => $hidePhoneNumberOnLabels,
        ]);
    }

    // ---- GLS-016 GetPrintData -----------------------------------------------------------------------

    /** GLS-016 `POST /ParcelService.svc/json/GetPrintData` (print data without generating labels). */
    public function getPrintData(array $parcelIdList): Response
    {
        return $this->call(self::PARCEL_SERVICE, 'GetPrintData', [
            'ParcelIdList' => array_values(array_map('intval', $parcelIdList)),
        ]);
    }

    // ---- GLS-018 DeleteLabels -----------------------------------------------------------------------

    /** GLS-018 `POST /ParcelService.svc/json/DeleteLabels`; PRINTED-state only (error 6), max 50 (error 22). */
    public function deleteLabels(array $parcelIdList): Response
    {
        $ids = array_slice(array_values(array_map('intval', $parcelIdList)), 0, self::MAX_DELETE_LIST);
        return $this->call(self::PARCEL_SERVICE, 'DeleteLabels', ['ParcelIdList' => $ids]);
    }

    // ---- GLS-019 ModifyCOD --------------------------------------------------------------------------

    /** GLS-019 `POST /ParcelService.svc/json/ModifyCOD`; `CODAmount >= 0` (error 8, §3.6/§5). */
    public function modifyCod(int $parcelId, int $codAmount, ?string $parcelNumber = null): Response
    {
        $body = ['CODAmount' => max(0, $codAmount)];
        if ($parcelNumber !== null && trim($parcelNumber) !== '') {
            $body['ParcelNumber'] = (int) $parcelNumber;
        } else {
            $body['ParcelId'] = $parcelId;
        }
        return $this->call(self::PARCEL_SERVICE, 'ModifyCOD', $body);
    }

    // ---- GLS-020 GetParcelList ----------------------------------------------------------------------

    /**
     * GLS-020 `POST /ParcelService.svc/json/GetParcelList` (read-only). Returns `PrintDataInfoList[]`
     * including `ClientReference`+`ParcelNumber`, the documented reconciliation handle after a create
     * timeout (§7). Date fields: PickupDateFrom/To, PrintDateFrom/To.
     *
     * @param array<string,mixed> $query
     */
    public function getParcelList(array $query): Response
    {
        return $this->call(self::PARCEL_SERVICE, 'GetParcelList', $query);
    }

    // ---- GLS-021 GetParcelStatuses ------------------------------------------------------------------

    /**
     * GLS-021 `POST /ParcelService.svc/json/GetParcelStatuses`; read-only, lookup by ParcelNumber.
     * Errors 9 (not exists) / 10 (not assigned) mean the parcel is not ours.
     */
    public function getParcelStatuses(int|string $parcelNumber, bool $returnPOD = false, string $languageIsoCode = 'HU'): Response
    {
        return $this->call(self::PARCEL_SERVICE, 'GetParcelStatuses', [
            'ParcelNumber' => $this->numeric($parcelNumber),
            'ReturnPOD' => $returnPOD,
            'LanguageIsoCode' => $this->language($languageIsoCode),
        ]);
    }

    // ---- GLS-022 GetParcelListStatuses --------------------------------------------------------------

    /**
     * GLS-022 `POST /ParcelService.svc/json/GetParcelListStatuses`; max 100 numbers, LanguageIsoCode required.
     *
     * @param list<int|string> $parcelNumbers
     */
    public function getParcelListStatuses(array $parcelNumbers, string $languageIsoCode = 'HU'): Response
    {
        $numbers = array_slice(array_values(array_map([$this, 'numeric'], $parcelNumbers)), 0, self::MAX_STATUS_LIST);
        return $this->call(self::PARCEL_SERVICE, 'GetParcelListStatuses', [
            'ParcelNumberList' => $numbers,
            'LanguageIsoCode' => $this->language($languageIsoCode),
        ]);
    }

    // ---- GLS-011 GetDeliveryPoints / GLS-012 GetLocations -------------------------------------------

    /**
     * GLS-011 `POST /MasterDataService.svc/json/GetDeliveryPoints`; `Data` is a GZIP-compressed JSON list
     * (field-by-field wire schema is GLS-UNK-09, see {@see GlsPoints}).
     */
    public function getDeliveryPoints(string $countryIsoCode = 'HU', ?string $lastUpdateTime = null): Response
    {
        $body = ['CountryIsoCode' => strtoupper(trim($countryIsoCode) !== '' ? strtoupper(trim($countryIsoCode)) : 'HU')];
        if ($lastUpdateTime !== null && trim($lastUpdateTime) !== '') {
            $body['LastUpdateTime'] = $lastUpdateTime;
        }
        return $this->call(self::MASTER_SERVICE, 'GetDeliveryPoints', $body);
    }

    /** GLS-012 `POST /MasterDataService.svc/json/GetLocations` (read-only master data). */
    public function getLocations(array $query = []): Response
    {
        return $this->call(self::MASTER_SERVICE, 'GetLocations', $query);
    }

    // ---- auth + transport ---------------------------------------------------------------------------

    /**
     * JSON request body for PrepareLabels/PrepareLabelsV2/PrintLabels: `ParcelList[]` + `WebshopEngine`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function parcelBody(array $payload): array
    {
        $body = ['ParcelList' => array_values((array) ($payload['ParcelList'] ?? []))];
        $engine = trim((string) ($payload['WebshopEngine'] ?? ''));
        if ($engine !== '') {
            $body['WebshopEngine'] = $engine;
        }
        return $body;
    }

    /**
     * `POST /{service}.svc/json/{method}` with the mandatory auth folded in. The auth bytes are never logged.
     *
     * @param array<string,mixed> $body
     */
    private function call(string $service, string $method, array $body): Response
    {
        $url = self::endpoint($this->baseUrl, $service, $method);
        $payload = array_merge($this->authPayload(), $body);
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

        return $this->request('POST', $url, [
            'body' => is_string($encoded) ? $encoded : '{}',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function authPayload(): array
    {
        return self::buildAuth($this->username, $this->password, $this->clientNumbers);
    }

    /**
     * Pure `APIRequestBase` shaping (§2.1): `Username` + SHA-512 `Password` byte array (+ optional
     * `ClientNumberList`). Kept static/pure so the request shape is unit-testable without WordPress.
     *
     * @param list<int> $clientNumbers
     * @return array{Username:string,Password:list<int>,ClientNumberList?:list<int>}
     */
    public static function buildAuth(string $username, string $password, array $clientNumbers = []): array
    {
        $auth = [
            'Username' => $username,
            'Password' => self::passwordBytes($password),
        ];
        if ($clientNumbers !== []) {
            $auth['ClientNumberList'] = array_values(array_map('intval', $clientNumbers));
        }
        return $auth;
    }

    /**
     * SHA-512 digest as a byte array — `unpack('C*', hash('sha512',$pwd,true))` (SDK_VERIFIED §2.1).
     * array_values() guarantees a JSON array (list), not an object.
     *
     * @return list<int>
     */
    public static function passwordBytes(string $password): array
    {
        $bytes = unpack('C*', hash('sha512', $password, true));
        return is_array($bytes) ? array_values($bytes) : [];
    }

    /** Pure endpoint composer for the documented REST template (§1 p.7). */
    public static function endpoint(string $baseUrl, string $service, string $method): string
    {
        return rtrim($baseUrl, '/') . '/' . trim($service, '/') . '/' . self::FORMAT . '/' . trim($method, '/');
    }

    private function numeric(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $value = preg_replace('/\D/', '', (string) $value) ?? '';
        return $value === '' ? 0 : (int) $value;
    }

    /** 04_GLS §3.5: LanguageIsoCode ∈ {HR,CS,HU,RO,SK,SL,EN}, default EN; HU merchant defaults to HU. */
    private function language(string $code): string
    {
        $code = strtoupper(trim($code));
        return in_array($code, ['HR', 'CS', 'HU', 'RO', 'SK', 'SL', 'EN'], true) ? $code : 'EN';
    }
}
