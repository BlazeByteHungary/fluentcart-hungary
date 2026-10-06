<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Billingo;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;

/**
 * Billingo API v3 REST/JSON client (04_BILLINGO_CONTRACT.md §2/§5; SDK_VERIFIED against
 * PLUGINS/BILLINGO_WOOCOMMERCE/billingo/src/{Service/BillingoConnector.php,Api/*.php}).
 *
 * - Single base URL for test and live: `https://api.billingo.hu/v3`; the API key (X-API-KEY) decides the
 *   environment/account. Explicit timeouts on every call (the legacy client had none).
 * - Every call goes through BaseClient, so the `fch/http/pre_request` mock seam works without network.
 * - The API key is never logged, never returned, never placed into a ProviderCallResult payload.
 * - 429 (300 req/min) is surfaced via BillingoStatus::classifyHttp; write calls are not blind-retried.
 */
final class BillingoClient extends BaseClient
{
    public const PROVIDER = 'billingo';

    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 20;

    private string $apiKey;

    public function __construct(string $apiKey, ?string $baseUrl = null)
    {
        parent::__construct($baseUrl ?? BillingoStatus::BASE);
        $this->apiKey = trim($apiKey);
        $this->setTimeouts(self::CONNECT_TIMEOUT, self::TOTAL_TIMEOUT);
        $this->setHeader('Accept', 'application/json');
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '';
    }

    // ---------------------------------------------------------------- documents

    /** POST /documents — create any document type (invoice/proforma/advance/draft/receipt…). */
    public function createDocument(array $payload): Response
    {
        return $this->json('POST', '/documents', $payload);
    }

    /** POST /documents/receipt — dedicated receipt path proven by the vendor client (04 §10 MISSING_DOC). */
    public function createReceipt(array $payload): Response
    {
        return $this->json('POST', '/documents/receipt', $payload);
    }

    public function listDocuments(array $query = []): Response
    {
        return $this->json('GET', '/documents' . $this->query($query));
    }

    public function getDocument(int $id): Response
    {
        return $this->json('GET', '/documents/' . $id);
    }

    /** Primary timeout-recovery lookup: GET /documents/vendor/{vendor_id} (04 §6). */
    public function getDocumentByVendorId(string $vendorId): Response
    {
        return $this->json('GET', '/documents/vendor/' . rawurlencode($vendorId));
    }

    /** GET /documents/{id}/download — binary PDF (never creates anything). */
    public function downloadPdf(int $id): Response
    {
        return $this->request('GET', $this->baseUrl . '/documents/' . $id . '/download', [
            'headers' => $this->authHeadersRaw(),
        ]);
    }

    public function getPublicUrl(int $id): Response
    {
        return $this->json('GET', '/documents/' . $id . '/public-url');
    }

    public function archiveDocument(int $id): Response
    {
        return $this->json('PUT', '/documents/' . $id . '/archive', null);
    }

    /** PUT /documents/{id}/cancel — storno; the cancel FLOW is W4, the operation is exposed here. */
    public function cancelDocument(int $id, array $payload): Response
    {
        return $this->json('PUT', '/documents/' . $id . '/cancel', $payload);
    }

    public function finalizeDraft(int $id, array $payload): Response
    {
        return $this->json('PUT', '/documents/' . $id, $payload);
    }

    public function createFromProforma(int $id, array $payload): Response
    {
        return $this->json('POST', '/documents/' . $id . '/create-from-proforma', $payload);
    }

    /**
     * POST /documents/{id}/create-modification-document — modification (helyesbítő) document of an
     * existing invoice (04 §3.2). No documented idempotency key: the read-back goes through the
     * original document's `related_documents`.
     */
    public function createModificationDocument(int $id, array $payload): Response
    {
        return $this->json('POST', '/documents/' . $id . '/create-modification-document', $payload);
    }

    public function deleteDraft(int $id): Response
    {
        return $this->json('DELETE', '/documents/' . $id, null);
    }

    public function sendDocumentEmail(int $id, array $emails): Response
    {
        return $this->json('POST', '/documents/' . $id . '/send', ['emails' => array_values(array_filter($emails))]);
    }

    public function onlineSzamlaStatus(int $id): Response
    {
        return $this->json('GET', '/documents/' . $id . '/online-szamla');
    }

    public function paymentHistory(int $id): Response
    {
        return $this->json('GET', '/documents/' . $id . '/payments');
    }

    public function addPayment(int $id, array $payment): Response
    {
        return $this->json('POST', '/documents/' . $id . '/payments', $payment);
    }

    public function deletePayments(int $id): Response
    {
        return $this->json('DELETE', '/documents/' . $id . '/payments', null);
    }

    // ---------------------------------------------------------------- partners & products

    public function listPartners(array $query = []): Response
    {
        return $this->json('GET', '/partners' . $this->query($query));
    }

    public function getPartner(int $id): Response
    {
        return $this->json('GET', '/partners/' . $id);
    }

    public function createPartner(array $payload): Response
    {
        return $this->json('POST', '/partners', $payload);
    }

    public function updatePartner(int $id, array $payload): Response
    {
        return $this->json('PUT', '/partners/' . $id, $payload);
    }

    public function listProducts(array $query = []): Response
    {
        return $this->json('GET', '/products' . $this->query($query));
    }

    public function createProduct(array $payload): Response
    {
        return $this->json('POST', '/products', $payload);
    }

    public function updateProduct(int $id, array $payload): Response
    {
        return $this->json('PUT', '/products/' . $id, $payload);
    }

    // ---------------------------------------------------------------- account data

    public function listBankAccounts(): Response
    {
        return $this->json('GET', '/bank-accounts');
    }

    public function listDocumentBlocks(): Response
    {
        return $this->json('GET', '/document-blocks');
    }

    public function organization(): Response
    {
        return $this->json('GET', '/organization');
    }

    public function currencyRates(): Response
    {
        return $this->json('GET', '/currencies');
    }

    /** GET /utils/check-tax-number/{taxNumber} — read-only NAV taxpayer check (04 §5). */
    public function checkTaxNumber(string $taxNumber, array $query = []): Response
    {
        return $this->json('GET', '/utils/check-tax-number/' . rawurlencode($taxNumber) . $this->query($query));
    }

    public function serverTime(): Response
    {
        return $this->json('GET', '/utils/time');
    }

    // ---------------------------------------------------------------- plumbing

    /** @param array<string,mixed>|null $body */
    private function json(string $method, string $path, ?array $body = null): Response
    {
        $args = ['headers' => $this->authHeaders()];
        if ($body !== null) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body);
            $args['body'] = is_string($encoded) ? $encoded : '{}';
        }
        return $this->request($method, $this->baseUrl . $path, $args);
    }

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        return array_merge($this->authHeadersRaw(), ['Content-Type' => 'application/json']);
    }

    /** @return array<string,string> */
    private function authHeadersRaw(): array
    {
        $version = defined('FCH_VERSION') ? (string) FCH_VERSION : '0';
        return [
            'X-API-KEY' => $this->apiKey,
            'X-Plugin-Name' => 'FluentCartHungary/' . $version,
        ];
    }

    /** @param array<string,mixed> $query */
    private function query(array $query): string
    {
        $query = array_filter($query, static fn($value): bool => $value !== null && $value !== '');
        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
