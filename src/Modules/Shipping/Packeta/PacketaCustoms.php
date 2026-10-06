<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Packeta;

use FluentCartHungary\Core\Money\Money;

/**
 * Packeta customs declarations for home delivery outside the EU (04_PACKETA_CONTRACT.md §Customs,
 * §PacketAttributes; matrix PKT-038/PKT-044).
 *
 * The current documented structures are packet-level `attributes` and per-item `items` (the legacy
 * `customsDeclaration` is deprecated — 04 §5.1). Shapes from `PACKETA_DOKS.md` §Customs:
 *   packet <attribute>:  ead=create · deliveryCost · invoiceNumber · invoiceIssueDate · invoiceFile(storage id)
 *   item   <attribute>:  customsCode · countryOfOrigin · value · productNameEn · unitsCount · weight
 * EAD/invoice documents are uploaded with `createStorageFile(content,name)` and referenced by id; its
 * formal prototype is not in the docs prototype list (04 §10 item 9) → {@see uploadDocument()} degrades
 * gracefully to a "not supported" result instead of guessing.
 */
final class PacketaCustoms
{
    /**
     * Build the packet-level `attributes` list from a customs snapshot.
     *
     * @param array<string,mixed> $snapshot keys: ead(bool|string), delivery_cost_minor, invoice_number,
     *                                       invoice_issue_date, invoice_file_id, currency
     * @return list<array{key:string,value:string}>
     */
    public static function buildPacketAttributes(array $snapshot): array
    {
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'HUF'));
        $attributes = [];

        $ead = $snapshot['ead'] ?? null;
        if ($ead === true || (is_string($ead) && $ead !== '')) {
            $attributes[] = ['key' => 'ead', 'value' => is_bool($ead) ? 'create' : (string) $ead];
        }

        $deliveryCost = (int) ($snapshot['delivery_cost_minor'] ?? 0);
        if ($deliveryCost > 0) {
            $attributes[] = ['key' => 'deliveryCost', 'value' => self::amount($deliveryCost, $currency)];
        }

        $invoiceNumber = trim((string) ($snapshot['invoice_number'] ?? ''));
        if ($invoiceNumber !== '') {
            $attributes[] = ['key' => 'invoiceNumber', 'value' => $invoiceNumber];
        }

        $invoiceIssueDate = trim((string) ($snapshot['invoice_issue_date'] ?? ''));
        if ($invoiceIssueDate !== '') {
            $attributes[] = ['key' => 'invoiceIssueDate', 'value' => $invoiceIssueDate];
        }

        $invoiceFileId = trim((string) ($snapshot['invoice_file_id'] ?? ''));
        if ($invoiceFileId !== '') {
            $attributes[] = ['key' => 'invoiceFile', 'value' => $invoiceFileId];
        }

        return $attributes;
    }

    /**
     * Build one customs `item` from a line. Required attributes: customsCode, countryOfOrigin, value,
     * productNameEn, unitsCount, weight.
     *
     * @param array<string,mixed> $line keys: customs_code, country_of_origin, value_minor, product_name_en,
     *                                   units_count, weight_kg, currency
     * @return array{attributes:list<array{key:string,value:string}>}
     */
    public static function buildItem(array $line): array
    {
        $currency = strtoupper((string) ($line['currency'] ?? 'HUF'));
        $attributes = [
            ['key' => 'customsCode', 'value' => (string) ($line['customs_code'] ?? '')],
            ['key' => 'countryOfOrigin', 'value' => strtoupper((string) ($line['country_of_origin'] ?? ''))],
            ['key' => 'value', 'value' => self::amount((int) ($line['value_minor'] ?? 0), $currency)],
            ['key' => 'productNameEn', 'value' => (string) ($line['product_name_en'] ?? '')],
            ['key' => 'unitsCount', 'value' => (string) max(0, (int) ($line['units_count'] ?? 0))],
            ['key' => 'weight', 'value' => (string) ($line['weight_kg'] ?? '0')],
        ];
        return ['attributes' => $attributes];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array{attributes:list<array{key:string,value:string}>}>
     */
    public static function buildItems(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                $out[] = self::buildItem($line);
            }
        }
        return $out;
    }

    /**
     * Local validation of a customs line (mirrors the documented item attribute set). Returns '' when
     * valid or a stable error code.
     *
     * @param array<string,mixed> $line
     */
    public static function validateLine(array $line): string
    {
        $code = trim((string) ($line['customs_code'] ?? ''));
        if ($code === '' || preg_match('/^\d{6,10}$/', $code) !== 1) {
            return 'invalid_customs_code';
        }
        $origin = strtoupper(trim((string) ($line['country_of_origin'] ?? '')));
        if (strlen($origin) !== 2) {
            return 'invalid_country_of_origin';
        }
        if ((int) ($line['value_minor'] ?? 0) <= 0) {
            return 'invalid_customs_value';
        }
        if (trim((string) ($line['product_name_en'] ?? '')) === '') {
            return 'missing_product_name_en';
        }
        if ((int) ($line['units_count'] ?? 0) <= 0) {
            return 'invalid_units_count';
        }
        if ((float) ($line['weight_kg'] ?? 0) <= 0.0) {
            return 'invalid_customs_weight';
        }
        return '';
    }

    /**
     * Upload an EAD/invoice document via `createStorageFile`. The prototype is not formally documented
     * (04 §10 item 9) so a response without a recognisable storage-file id is reported as unsupported
     * rather than retried blindly.
     *
     * @return array{ok:bool,storage_file_id:?string,error:?string}
     */
    public static function uploadDocument(PacketaClient $client, string $contents, string $name): array
    {
        if (!$client->hasCredentials()) {
            return ['ok' => false, 'storage_file_id' => null, 'error' => 'missing_credentials'];
        }
        if ($contents === '' || $name === '') {
            return ['ok' => false, 'storage_file_id' => null, 'error' => 'empty_document'];
        }

        $response = $client->createStorageFile(base64_encode($contents), $name);
        if ($response->error !== null || $response->status === 0) {
            return ['ok' => false, 'storage_file_id' => null, 'error' => 'timeout'];
        }
        if (!$response->isSuccess()) {
            return ['ok' => false, 'storage_file_id' => null, 'error' => 'http_' . $response->status];
        }

        $decoded = PacketaClient::decode($response->body);
        if (!$decoded['ok']) {
            return ['ok' => false, 'storage_file_id' => null, 'error' => (string) ($decoded['fault'] ?? 'storage_file_fault')];
        }
        $id = PacketaClient::firstString($decoded['result'], 'id', 'storageFileId');
        if ($id === '') {
            // Signature not documented (PK-U9): do not invent an id.
            return ['ok' => false, 'storage_file_id' => null, 'error' => 'create_storage_file_unsupported'];
        }
        return ['ok' => true, 'storage_file_id' => $id, 'error' => null];
    }

    private static function amount(int $minor, string $currency): string
    {
        try {
            return Money::fromMinor($minor, $currency);
        } catch (\Throwable $e) {
            return (string) $minor;
        }
    }
}
