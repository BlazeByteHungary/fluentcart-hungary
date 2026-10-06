<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Foxpost;

use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\ShipmentRepository;
use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Modules\Shipping\Carrier\CarrierLabelStore;

/**
 * Foxpost label generation / read-back (04_FOXPOST_CONTRACT.md §4 op 9/11/12).
 *
 * - PDF: `generatePdf` POST /api/label/{pageSize}, pageSize ∈ A6/A7/_85X85 (guide §6.2) plus A5 in the
 *   machine spec; the OpenAPI enum is honoured here (see FoxpostClient::PAGE_SIZES). `startPos` (1..7)
 *   is valid on A7 only. The label call is by barcode and therefore safely re-runnable (§7).
 * - `isPortrait` is declared in the **test spec only** and not in production (§1, FX-U8/X5): it is added
 *   to the query **only** when the operator enables the test-spec flag setting (`label_is_portrait`).
 * - ZPL `GET /api/label/zpl/{barcode}` is production-spec only and ACCOUNT_DEPENDENT (FX-U8): the
 *   operation is gated behind the `zpl_enabled` setting and is never assumed available.
 * - Generated artifacts are stored privately via CarrierLabelStore (`foxpost/{year}/...`); only the
 *   storage-relative `label_ref` is persisted on the shipment row (never a URL).
 */
final class FoxpostLabelService
{
    private const DEFAULT_PAGE_SIZE = 'A6';

    private ShipmentRepository $shipments;

    private ?FoxpostClient $client;

    public function __construct(?FoxpostClient $client = null, ?ShipmentRepository $shipments = null)
    {
        $this->client = $client;
        $this->shipments = $shipments ?? new ShipmentRepository();
    }

    /**
     * Generate the PDF label for a confirmed shipment and store it privately.
     *
     * @return array{ok:bool,error?:string,label_ref?:string,sha256?:string,bytes?:int,page_size?:string}
     */
    public function generateLabel(int $shipmentId, ?string $pageSize = null, ?int $startPos = null, ?bool $isPortrait = null): array
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null || (string) ($row->carrier ?? '') !== FoxpostModule::CARRIER) {
            return ['ok' => false, 'error' => 'shipment_not_found'];
        }
        if ((string) ($row->state ?? '') !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return ['ok' => false, 'error' => 'shipment_not_confirmed'];
        }

        $barcode = trim((string) ($row->barcode ?? ''));
        if ($barcode === '') {
            $barcode = trim((string) ($row->external_shipment_id ?? ''));
        }
        if ($barcode === '') {
            return ['ok' => false, 'error' => 'missing_barcode'];
        }

        $pageSize = self::normalizePageSize((string) ($pageSize ?? FoxpostModule::setting('label_page_size', self::DEFAULT_PAGE_SIZE)));
        if ($startPos === null) {
            $configured = FoxpostModule::setting('label_start_pos', null);
            $startPos = is_numeric($configured) ? (int) $configured : null;
        }
        if ($isPortrait === null) {
            // Test-spec flag (FX-U8): never sent unless the operator enables it for this account.
            $isPortrait = (bool) FoxpostModule::setting('label_is_portrait', false);
        }

        $client = $this->client();
        if (!$client->hasCredentials()) {
            return ['ok' => false, 'error' => 'missing_credentials'];
        }

        $query = self::buildLabelQuery($pageSize, $startPos, $isPortrait);
        $response = $client->generatePdf([$barcode], $pageSize, $query);
        if ($response->error !== null || !$response->isSuccess()) {
            return ['ok' => false, 'error' => 'foxpost_http_' . ($response->status > 0 ? $response->status : 'timeout')];
        }
        if (trim($response->body) === '') {
            return ['ok' => false, 'error' => 'empty_label'];
        }

        try {
            $stored = (new CarrierLabelStore(FoxpostModule::CARRIER))->store('foxpost-label-' . $barcode, $response->body, 'pdf');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'label_store_failed'];
        }

        // Persist the artifact reference on the shipment row: without this the stored label would be
        // orphaned (download/reprint read `label_ref`), which was a real defect found during W2 integration.
        // The shipment is already EXTERNAL_CONFIRMED here, so a plain state transition would be a no-op —
        // setLabel() writes the artifact columns directly (ShipmentRepository::setLabel).
        $this->shipments->setLabel($shipmentId, (string) $stored['ref'], (string) $stored['sha256'], 'PREPARED');

        return [
            'ok' => true,
            'label_ref' => $stored['ref'],
            'sha256' => $stored['sha256'],
            'bytes' => $stored['bytes'],
            'page_size' => $pageSize,
        ];
    }

    /**
     * Read the stored label artifact by ref. Never generates a new label (reprint identity, invariant §1.6).
     */
    public function readLabel(int $shipmentId): ?string
    {
        $row = $this->shipments->getById($shipmentId);
        if ($row === null || (string) ($row->carrier ?? '') !== FoxpostModule::CARRIER) {
            return null;
        }
        $ref = trim((string) ($row->label_ref ?? ''));
        if ($ref === '') {
            return null;
        }
        return (new CarrierLabelStore(FoxpostModule::CARRIER))->read($ref);
    }

    /** Account-dependent ZPL gate (FX-U8); default off until the account/environment is verified. */
    public function zplEnabled(): bool
    {
        return (bool) FoxpostModule::setting('zpl_enabled', false);
    }

    /**
     * Fetch a ZPL label. Returns an explicit account-dependent refusal unless `zpl_enabled` is set —
     * ZPL is absent from the test spec and must never be assumed (04_FOXPOST §1, §4 op 11).
     *
     * @return array{ok:bool,error?:string,label?:string}
     */
    public function generateZpl(string $barcode, ?int $dpi = null): array
    {
        if (!$this->zplEnabled()) {
            return ['ok' => false, 'error' => 'zpl_account_dependent'];
        }
        $client = $this->client();
        if (!$client->hasCredentials()) {
            return ['ok' => false, 'error' => 'missing_credentials'];
        }
        $response = $client->getZplLabel($barcode, $dpi);
        if ($response->error !== null || !$response->isSuccess()) {
            return ['ok' => false, 'error' => 'foxpost_http_' . ($response->status > 0 ? $response->status : 'timeout')];
        }
        if (trim($response->body) === '') {
            return ['ok' => false, 'error' => 'empty_label'];
        }
        return ['ok' => true, 'label' => $response->body];
    }

    // ---- pure label-geometry helpers (unit-testable, no WordPress) -----------------------------------

    public static function pageSizeSupported(string $pageSize): bool
    {
        return in_array(strtoupper(trim($pageSize)), FoxpostClient::PAGE_SIZES, true);
    }

    public static function normalizePageSize(string $pageSize): string
    {
        $pageSize = strtoupper(trim($pageSize));
        return in_array($pageSize, FoxpostClient::PAGE_SIZES, true) ? $pageSize : self::DEFAULT_PAGE_SIZE;
    }

    /**
     * Build the label query: `startPos` only for A7 and within 1..7; `isPortrait` only when the test-spec
     * flag is enabled (04_FOXPOST §4 op 9, §1).
     *
     * @return array<string,scalar>
     */
    public static function buildLabelQuery(string $pageSize, ?int $startPos = null, bool $isPortrait = false): array
    {
        $pageSize = self::normalizePageSize($pageSize);
        $query = [];
        if ($pageSize === 'A7' && $startPos !== null && $startPos >= 1 && $startPos <= 7) {
            $query['startPos'] = $startPos;
        }
        if ($isPortrait) {
            $query['isPortrait'] = 'true';
        }
        return $query;
    }

    private function client(): FoxpostClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $this->client = new FoxpostClient(FoxpostModule::mode());
        return $this->client;
    }
}
