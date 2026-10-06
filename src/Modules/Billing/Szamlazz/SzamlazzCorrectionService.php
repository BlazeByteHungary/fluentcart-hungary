<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Billing\CorrectionDispatcherRegistry;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Settings\CredentialStore;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Számlázz.hu correction dispatcher + read-back (W4 invoicing scope).
 *
 * Documented capabilities only (04_SZAMLAZZ_HU_CONTRACT.md §3.3/§5, local mirror):
 *  - storno_invoice of a document whose provider type is `invoice` → `action-szamla_agent_st`
 *    (`xmlszamlast`; szamlaszam + optional szamlaKulsoAzon for OUR read-back key);
 *  - storno_invoice of a `proforma` → `action-szamla_agent_dijbekero_torlese` (`xmlszamladbkdel`);
 *  - corrected_invoice of an `invoice` → the documented corrective-invoice create with
 *    `<helyesbitoszamla>true</helyesbitoszamla>` + `<helyesbitettSzamlaszam>` — built from the ORIGINAL
 *    document's stored item/buyer snapshot with unchanged amounts. Amount-level ("item delta") corrections
 *    are NOT simulated: the admin UI blocks them with guidance.
 *
 * The provider binding (mode/account/credentials) is always the ORIGINAL document's binding, never the
 * current selection. Timeout => UNCERTAIN (read-back only); a negative read-back is never a rejection.
 */
final class SzamlazzCorrectionService
{
    public const PROVIDER = 'szamlazz';
    public const DOC_ACTION_STORNO = 'storno_invoice';
    public const DOC_ACTION_CORRECTED = 'corrected_invoice';

    private InvoiceDocumentRepository $documents;

    public function __construct(?InvoiceDocumentRepository $documents = null)
    {
        $this->documents = $documents ?? new InvoiceDocumentRepository();
    }

    /** @param object $ledgerRow correction ledger row (kind storno_invoice|corrected_invoice) */
    public function dispatch(object $ledgerRow): ProviderCallResult
    {
        $kind = (string) $ledgerRow->kind;
        $key = (string) $ledgerRow->idempotency_key;

        $doc = $this->documents->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($doc === null) {
            return ProviderCallResult::localError('MISSING_DOCUMENT_ROW', 'A korrekciós bizonylatsor nem található.');
        }
        $original = $this->documents->getById((int) ($doc->storno_of_document_id ?? 0));
        if ($original === null) {
            return ProviderCallResult::localError('MISSING_ORIGINAL_DOCUMENT', 'Az eredeti bizonylat sora nem található.');
        }
        $originalNumber = trim((string) ($original->document_number ?? ''));
        if ($originalNumber === '') {
            return ProviderCallResult::localError('ORIGINAL_MISSING_DOCUMENT_NUMBER', 'Az eredeti bizonylatnak nincs szolgáltatói száma.');
        }

        $agentKey = (string) $this->credential($ledgerRow, 'agent_key');
        if (trim($agentKey) === '') {
            // Local error: the operation stays NOT_STARTED so the operator can fix the account
            // credentials and resume the SAME intent (never a new identity).
            return ProviderCallResult::localError('MISSING_AGENT_KEY', 'A korrekcióhoz az eredeti számlázó fiók (mód/fiók) kulcsa nem érhető el.');
        }

        $client = new SzamlazzClient($agentKey);
        $reason = $this->correctionReason($doc);
        $request = [
            'operation' => $kind === self::DOC_ACTION_STORNO ? 'storno_invoice' : 'corrected_invoice',
            'original_number' => $originalNumber,
            'external_key' => $key,
        ];

        if ($kind === self::DOC_ACTION_STORNO) {
            $response = strtolower((string) ($doc->provider_doc_type ?? '')) === 'proforma'
                ? $client->deleteProforma($originalNumber)
                : $client->stornoInvoice($originalNumber, [
                    'szamla_kulso_azon' => $key,
                    'kelt_datum' => $this->today(),
                    'megjegyzes' => $reason,
                ]);
        } else {
            $snapshot = $this->buildCorrectedSnapshot($doc, $original, $ledgerRow, $agentKey, $reason);
            if ($snapshot === null) {
                return ProviderCallResult::localError(
                    'ORIGINAL_ITEMS_UNAVAILABLE',
                    'Az eredeti bizonylat tétel-/vevősnapshotja hiányzik, ezért a helyesbítő bizonylat nem építhető fel biztonságosan. Használja a szolgáltatói felületet, vagy állítson ki új bizonylatot (az eredeti érintetlen marad).'
                );
            }
            $response = $client->createInvoice(SzamlazzXmlBuilder::build($snapshot));
        }

        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }

        $parsed = SzamlazzResponseParser::parse($response->body, $response->headers);
        $responseSummary = [
            'structure' => (string) ($parsed['structure'] ?? 'unknown'),
            'sikeres' => (bool) ($parsed['ok'] ?? false),
            'szamlaszam' => $parsed['invoice_number'] ?? null,
            'hibakod' => $parsed['error_code'] ?? null,
        ];

        if (!empty($parsed['ok'])) {
            $number = trim((string) ($parsed['invoice_number'] ?? ''));
            $isDelete = $kind === self::DOC_ACTION_STORNO && strtolower((string) ($doc->provider_doc_type ?? '')) === 'proforma';
            // A proforma delete response carries no document number: the deleted proforma keeps its
            // original identifier for the audit trail.
            if ($number === '' && !$isDelete) {
                return ProviderCallResult::localError('MISSING_DOCUMENT_NUMBER', 'A szolgáltató sikert jelzett, de nem adott bizonylatszámot.', $request, $responseSummary);
            }
            $fields = [
                'document_number' => $number !== '' ? $number : $originalNumber,
                'external_id' => $number !== '' ? $number : $originalNumber,
                'provider_doc_type' => $isDelete ? 'proforma_deleted' : $kind,
                'gross_minor' => (int) ($doc->gross_minor ?? 0),
                'net_minor' => (int) ($doc->net_minor ?? 0),
                'vat_minor' => (int) ($doc->vat_minor ?? 0),
                'currency' => (string) ($doc->currency ?? ''),
                'order_fin_snapshot_hash' => (string) ($original->order_fin_snapshot_hash ?? ''),
            ];
            if (!$isDelete) {
                $pdf = $this->fetchAndStorePdf($client, $fields['document_number'], $key, $kind);
                if ($pdf !== null) {
                    $fields['pdf_ref'] = $pdf['ref'];
                    $fields['pdf_sha256'] = $pdf['sha256'];
                }
            }
            return ProviderCallResult::success($fields, $request, $responseSummary);
        }

        if (!empty($parsed['local'])) {
            return ProviderCallResult::localError((string) ($parsed['error_code'] ?? 'UNKNOWN_RESPONSE'), (string) ($parsed['error_message'] ?? ''), $request, $responseSummary);
        }

        $code = (string) ($parsed['error_code'] ?? 'REJECTED');
        if (empty($parsed['definitive'])) {
            return ProviderCallResult::uncertain('TRANSIENT_PROVIDER_ERROR:' . $code, $request, $responseSummary);
        }
        return ProviderCallResult::rejected($code, (string) ($parsed['error_message'] ?? ''), $request, $responseSummary);
    }

    /**
     * Read-back an UNCERTAIN correction by OUR external key (`szamlaKulsoAzon` = ledger idempotency key).
     * Never re-dispatches; a negative/unparseable lookup keeps the row UNCERTAIN (W4_EXECUTION_PLAN §5).
     *
     * @param object $ledgerRow correction ledger row
     */
    public function reconcile(object $ledgerRow): ProviderCallResult
    {
        $key = (string) $ledgerRow->idempotency_key;
        $doc = $this->documents->getById((int) ($ledgerRow->owner_id ?? 0));
        if ($doc === null) {
            return ProviderCallResult::uncertain('MISSING_DOCUMENT_ROW');
        }
        $agentKey = (string) $this->credential($ledgerRow, 'agent_key');
        if (trim($agentKey) === '') {
            return ProviderCallResult::uncertain('MISSING_AGENT_KEY');
        }

        $request = ['operation' => 'query_xml', 'szamlaKulsoAzon' => $key];
        $client = new SzamlazzClient($agentKey);
        $response = $client->queryInvoice(['szamla_kulso_azon' => $key]);
        if ($response->error !== null || $response->status === 0) {
            return ProviderCallResult::uncertain('QUERY_TIMEOUT', $request, ['error' => (string) $response->error]);
        }

        $parsed = SzamlazzResponseParser::parse($response->body, $response->headers);
        $responseSummary = [
            'structure' => (string) ($parsed['structure'] ?? 'unknown'),
            'sikeres' => (bool) ($parsed['ok'] ?? false),
            'szamlaszam' => $parsed['invoice_number'] ?? null,
            'hibakod' => $parsed['error_code'] ?? null,
        ];

        if (!empty($parsed['ok'])) {
            $number = trim((string) ($parsed['invoice_number'] ?? ''));
            if ($number === '') {
                return ProviderCallResult::uncertain('QUERY_MISSING_DOCUMENT_NUMBER', $request, $responseSummary);
            }
            $fields = [
                'document_number' => $number,
                'external_id' => $number,
                'provider_doc_type' => (string) $ledgerRow->kind,
                'gross_minor' => (int) ($doc->gross_minor ?? 0),
                'net_minor' => (int) ($doc->net_minor ?? 0),
                'vat_minor' => (int) ($doc->vat_minor ?? 0),
                'currency' => (string) ($doc->currency ?? ''),
            ];
            $pdf = $this->fetchAndStorePdf($client, $number, $key, (string) $ledgerRow->kind);
            if ($pdf !== null) {
                $fields['pdf_ref'] = $pdf['ref'];
                $fields['pdf_sha256'] = $pdf['sha256'];
            }
            return ProviderCallResult::success($fields, $request, $responseSummary);
        }

        // Negative or unparseable lookup: the key may simply not be visible yet (or the provider query
        // cannot find corrected documents by external key). Never a rejection — stays UNCERTAIN for a human.
        return ProviderCallResult::uncertain('QUERY_NOT_FOUND_NOT_FINAL', $request, $responseSummary);
    }

    /**
     * Retry ONLY the PDF read-back for a confirmed correction (a failed PDF must never create a document).
     *
     * @return array{status:string,reason?:string,pdf_ref?:string}
     */
    public function retryPdf(int $documentId): array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->provider !== self::PROVIDER || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return ['status' => 'error', 'reason' => 'document_not_confirmed'];
        }
        if (!empty($row->pdf_ref) && (new SzamlazzDocumentStore())->read((string) $row->pdf_ref) !== null) {
            return ['status' => 'already_stored', 'pdf_ref' => (string) $row->pdf_ref];
        }
        $number = trim((string) ($row->document_number ?? ''));
        if ($number === '') {
            return ['status' => 'error', 'reason' => 'missing_document_number'];
        }
        $agentKey = (string) (new CredentialStore())->get(self::PROVIDER, (string) $row->mode, (string) $row->account_id, 'agent_key');
        if (trim($agentKey) === '') {
            return ['status' => 'error', 'reason' => 'missing_agent_key'];
        }
        $pdf = $this->fetchAndStorePdf(new SzamlazzClient($agentKey), $number, (string) ($row->vendor_id ?? ''), (string) $row->doc_role);
        if ($pdf === null) {
            return ['status' => 'error', 'reason' => 'pdf_unavailable'];
        }
        $this->documents->updateIssuedFields($documentId, [
            'pdf_ref' => $pdf['ref'],
            'pdf_sha256' => $pdf['sha256'],
        ]);
        return ['status' => 'stored', 'pdf_ref' => $pdf['ref']];
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed>|null */
    private function buildCorrectedSnapshot(object $doc, object $original, object $ledgerRow, string $agentKey, string $reason): ?array
    {
        $meta = json_decode((string) ($original->vat_snapshot ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $lines = $meta['lines'] ?? null;
        if (!is_array($lines) || $lines === []) {
            return null;
        }
        $buyer = is_array($meta['buyer'] ?? null) ? $meta['buyer'] : $this->buyerFromOrder((int) $doc->order_id);
        if ($buyer === null) {
            return null;
        }

        $settings = new ModuleSettings();
        $currency = strtoupper((string) ($doc->currency ?? ''));
        $today = $this->today();
        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $items[] = [
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => $line['quantity'] ?? 1,
                'unit' => (string) ($line['unit'] ?? 'db'),
                'net_minor' => (int) ($line['net_minor'] ?? 0),
                'vat_minor' => (int) ($line['vat_minor'] ?? 0),
                'gross_minor' => (int) ($line['gross_minor'] ?? 0),
                'vat_code' => (string) ($line['vat_code'] ?? '0'),
            ];
        }
        if ($items === []) {
            return null;
        }

        return [
            'order_id' => (string) $doc->order_id,
            'currency' => $currency,
            'amount_basis' => (string) ($meta['amount_basis'] ?? 'gross'),
            'financials' => [
                'subtotal' => (int) ($original->net_minor ?? 0),
                'shipping_total' => 0,
                'fee_total' => 0,
                'tax_total' => (int) ($original->vat_minor ?? 0),
                'total_amount' => (int) ($original->gross_minor ?? 0),
                'tax_behavior' => 0,
            ],
            'seller' => [
                'agent_key' => $agentKey,
                'eszamla' => (bool) $settings->get('szamlazz', 'eszamla', false),
                'szamla_letoltes' => false,
                'valasz_verzio' => 2,
                'szamla_kulso_azon' => (string) $ledgerRow->idempotency_key,
                'bank' => (string) $settings->get('szamlazz', 'bank_name', ''),
                'bankszamlaszam' => (string) $settings->get('szamlazz', 'bank_account', ''),
                'email_replyto' => (string) $settings->get('szamlazz', 'email_replyto', ''),
                'email_targy' => (string) $settings->get('szamlazz', 'email_targy', ''),
                'email_szoveg' => (string) $settings->get('szamlazz', 'email_szoveg', ''),
                'szamlaszam_elotag' => '',
            ],
            'header' => [
                'kelt_datum' => $today,
                'teljesites_datum' => (string) ($original->fulfillment_date ?? $today),
                'fizetesi_hatarido_datum' => (string) ($original->due_date ?? $today),
                'fizmod' => (string) $settings->get('szamlazz', 'fizmod', 'Átutalás'),
                'penznem' => $currency,
                'szamla_nyelve' => (string) $settings->get('szamlazz', 'invoice_language', 'hu'),
                'megjegyzes' => $reason,
                'rendeles_szam' => SzamlazzXmlBuilder::orderReference((string) $doc->order_id),
                'doc_flags' => [
                    'helyesbitoszamla' => true,
                    'helyesbitettSzamlaszam' => (string) $original->document_number,
                ],
            ],
            'buyer' => $buyer,
            'items' => $items,
        ];
    }

    /** Best-effort buyer fallback from the FluentCart order when the original snapshot has none. */
    private function buyerFromOrder(int $orderId): ?array
    {
        $class = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($class)) {
            return null;
        }
        $order = $class::find($orderId);
        if (!is_object($order)) {
            return null;
        }
        $address = method_exists($order, 'billing_address') ? $order->billing_address : null;
        $name = trim((string) ($address->name ?? ''));
        if ($name === '') {
            $name = 'Vevő';
        }
        return [
            'nev' => $name,
            'orszag' => strtoupper((string) ($address->country ?? 'HU')),
            'irsz' => (string) ($address->postcode ?? ''),
            'telepules' => (string) ($address->city ?? ''),
            'cim' => trim((string) ($address->address_1 ?? '') . ' ' . (string) ($address->address_2 ?? '')),
            'email' => (string) ($address->email ?? ''),
            'send_email' => false,
        ];
    }

    /** @return array{ref:string,sha256:string}|null */
    private function fetchAndStorePdf(SzamlazzClient $client, string $documentNumber, string $externalKey, string $kind): ?array
    {
        if ($documentNumber === '') {
            return null;
        }
        try {
            $opts = $externalKey !== '' ? ['szamla_kulso_azon' => $externalKey] : [];
            $pdf = SzamlazzClient::extractPdf($client->downloadInvoicePdf($documentNumber, $opts));
            if ($pdf === null || $pdf === '') {
                return null;
            }
            $stored = (new SzamlazzDocumentStore())->store('szamlazz-' . $kind . '-' . $documentNumber, $pdf, 'pdf');
            return ['ref' => $stored['ref'], 'sha256' => $stored['sha256']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function correctionReason(object $doc): string
    {
        $meta = json_decode((string) ($doc->vat_snapshot ?? ''), true);
        $reason = is_array($meta) ? (string) ($meta['correction']['reason'] ?? '') : '';
        return trim($reason) === '' ? 'Bizonylat korrekció' : trim($reason);
    }

    /** Credentials of the ORIGINAL document's binding (mode/account from the ledger row). */
    private function credential(object $ledgerRow, string $field): ?string
    {
        return (new CredentialStore())->get(self::PROVIDER, (string) $ledgerRow->mode, (string) $ledgerRow->account_id, $field);
    }

    private function today(): string
    {
        return function_exists('current_time') ? (string) current_time('Y-m-d') : gmdate('Y-m-d');
    }

    /** Registered by SzamlazzModule so corrections never need a direct module reference from Core. */
    public static function register(): void
    {
        $service = new self();
        CorrectionDispatcherRegistry::register(self::PROVIDER, static function (object $ledgerRow) use ($service): ProviderCallResult {
            return $service->dispatch($ledgerRow);
        });
    }
}
