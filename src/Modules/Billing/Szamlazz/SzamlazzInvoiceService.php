<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Compat\FluentCartMoney;
use FluentCartHungary\Core\Ledger\ExternalOperation;
use FluentCartHungary\Core\Ledger\InvoiceDocumentRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Http\Response;
use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Orchestrates one automatic Számlázz invoice for a FluentCart order.
 *
 * Reads the order ONLY through verified FluentCart APIs (no raw SQL, no core patching):
 *  - `FluentCart\App\Models\Order::find($id)`            (vendor/fluentcart-core/fluent-cart/app/Models/Order.php:35)
 *  - `$order->order_items` / `order_items()`             (Order.php:210)
 *  - `$order->billing_address`                           (Order.php:349)
 *  - `$order->getBusinessInfo()`                         (Order.php:531; keys set by TaxModule.php:1089-1108)
 *  - `$order->updateMeta()/getMeta()` (reprint/mirror)   (Order.php:489-522)
 *
 * Claim + exactly-once local side effects:
 *  - `InvoiceDocumentRepository::getOrCreate()` with `is_selected_invoicer = 1` and the generated
 *    `auto_doc_guard` enforces one automatic document per (order, doc_role) (single-invoicer invariant §1.5);
 *  - `OperationRunner::run()` claims the ledger `fch:{order}:create_invoice:{n}` and applies the
 *    success/rejected/UNCERTAIN state machine (never auto-retries a timeout);
 *  - `vendor_id` = `idempotency_key` is also sent as `szamlaKulsoAzon`, the reconciliation handle.
 *
 * The agent key and the raw request XML never reach a ProviderCallResult payload, a log, or an exception.
 */
final class SzamlazzInvoiceService
{
    public const DOC_ROLE_INVOICE = 'invoice';
    public const PROVIDER = 'szamlazz';
    public const MODULE = 'billing';
    public const SETTINGS_SCOPE = 'szamlazz';
    public const MAX_ATTEMPTS = 3;

    /** FluentCart Core COD payment-method slug (app/Modules/PaymentMethods/Cod/Cod.php:39-40, :91). */
    private const COD_METHOD = 'offline_payment';

    /** EU member states (ISO-2) used for the B2B `adoalany` hint (04 SZAMLAZZ §4.1). */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    private InvoiceDocumentRepository $documents;

    private OperationRunner $runner;

    private ModuleSettings $settings;

    public function __construct(
        ?InvoiceDocumentRepository $documents = null,
        ?OperationRunner $runner = null,
        ?ModuleSettings $settings = null
    ) {
        $this->documents = $documents ?? new InvoiceDocumentRepository();
        $this->runner = $runner ?? new OperationRunner();
        $this->settings = $settings ?? new ModuleSettings();
    }

    /**
     * Issue (or confirm an already-issued) invoice for one order. Idempotent through the ledger+document
     * unique keys: a duplicate hook performs no external call.
     *
     * @return array{status:string,state:string,order_id:int,document_id:?int,document_number:?string,pdf_ref:?string,reason?:string}
     */
    public function issue(int $orderId, int $sequence = 1): array
    {
        if (!SzamlazzModule::isSelectedInvoicer()) {
            return $this->summary('skipped', 'NOT_STARTED', $orderId, null, null, null, 'not_selected_invoicer');
        }

        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'order_not_found');
        }
        // Merchant policy: prepaid orders are invoiced only once the payment condition is verified.
        // Under the explicit COD `before_dispatch` policy an unpaid COD order may be invoiced when the
        // merchant requests the shipping label; issuing an invoice NEVER mutates the payment status, so
        // the order stays unpaid until the courier collects the cash.
        if ((string) ($order->payment_status ?? '') !== 'paid' && !$this->codBeforeDispatch($order)) {
            return $this->summary('skipped', 'NOT_STARTED', $orderId, null, null, null, 'order_not_paid');
        }

        $agentKey = (string) SzamlazzModule::agentKey();
        if (trim($agentKey) === '') {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'missing_agent_key');
        }

        $snapshot = $this->buildSnapshot($order, $sequence);
        if ($snapshot === null) {
            return $this->summary('error', 'NOT_STARTED', $orderId, null, null, null, 'snapshot_failed');
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(self::SETTINGS_SCOPE);
        $idempotencyKey = ExternalOperation::idempotencyKey($orderId, 'create_invoice', $sequence);
        $snapshotHash = SzamlazzXmlBuilder::hashSnapshot($snapshot);
        $totals = $this->totals($snapshot);

        $document = $this->documents->getOrCreate([
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'provider' => self::PROVIDER,
            'mode' => $mode,
            'account_id' => $account,
            'doc_role' => self::DOC_ROLE_INVOICE,
            'sequence_no' => $sequence,
            'idempotency_key' => $idempotencyKey,
            'vendor_id' => $idempotencyKey,
            'is_selected_invoicer' => 1,
            'selected_invoicer_provider' => self::PROVIDER,
            'currency' => (string) $snapshot['currency'],
            'order_fin_snapshot_hash' => $snapshotHash,
            'fulfillment_date' => (string) ($snapshot['header']['teljesites_datum'] ?? ''),
            'due_date' => (string) ($snapshot['header']['fizetesi_hatarido_datum'] ?? ''),
        ]);
        $docRow = $document['row'];
        $docId = (int) $docRow->id;

        // Single-invoicer race guard: the generated auto_doc_guard may return another provider's active
        // automatic document for this (order, doc_role). Never issue a second automatic invoice (§1.5).
        if ((string) $docRow->provider !== self::PROVIDER) {
            return $this->summary('skipped', (string) $docRow->state, $orderId, $docId, null, null, 'single_invoicer_active:' . (string) $docRow->provider);
        }

        // Already proved: reprint/re-run returns the same document, never a new one (invariant §1.6).
        if ((string) $docRow->state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return $this->summary('already_issued', (string) $docRow->state, $orderId, $docId, (string) $docRow->document_number, $this->nullableString($docRow->pdf_ref ?? null));
        }
        if (in_array((string) $docRow->state, [ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED], true)) {
            return $this->summary('not_retryable', (string) $docRow->state, $orderId, $docId, null, null, 'document_' . strtolower((string) $docRow->state));
        }

        // W4: a stornoed/corrected automatic invoice is never replaced silently by a late paid/label
        // event — re-issuing a superseded document is a deliberate manual decision.
        if ($this->documents->latestSupersededAuto($orderId, self::DOC_ROLE_INVOICE) !== null) {
            return $this->summary('skipped', (string) $docRow->state, $orderId, $docId, null, null, 'prior_document_superseded');
        }

        $client = new SzamlazzClient($agentKey);
        $run = $this->runner->run([
            'idempotency_key' => $idempotencyKey,
            'module' => self::MODULE,
            'provider' => self::PROVIDER,
            'kind' => 'create_invoice',
            'mode' => $mode,
            'account_id' => $account,
            'order_id' => $orderId,
            'order_uuid' => (string) ($order->uuid ?? ''),
            'owner_type' => 'invoice_document',
            'owner_id' => $docId,
            'business_reference' => (string) ($snapshot['header']['rendeles_szam'] ?? ''),
            'currency' => (string) $snapshot['currency'],
            'amount_minor' => $totals['gross'],
            'order_fin_snapshot_hash' => $snapshotHash,
            'max_attempts' => self::MAX_ATTEMPTS,
        ], function (object $ledgerRow) use ($snapshot, $client): ProviderCallResult {
            $xml = SzamlazzXmlBuilder::build($snapshot);
            $request = $this->requestSummary($snapshot);
            $response = $client->createInvoice($xml);
            return $this->interpretCreateResponse($response, $snapshot, $request);
        });

        $state = (string) $run['state'];
        $fields = $run['result']?->fields ?? [];

        if ($state === ExternalOperation::EXTERNAL_CONFIRMED) {
            $this->finalizeIssued($docId, $run['row'], $fields, $client, $snapshot, $totals);
            $fresh = $this->documents->getById($docId);
            return $this->summary('issued', $state, $orderId, $docId, $this->nullableString($fresh->document_number ?? ($fields['document_number'] ?? null)), $this->nullableString($fresh->pdf_ref ?? null));
        }

        if ($state === ExternalOperation::PROVEN_REJECTED) {
            $this->markDocument($docId, ExternalOperation::PROVEN_REJECTED);
            return $this->summary('rejected', $state, $orderId, $docId, null, null, (string) ($run['result']?->code ?? 'REJECTED'));
        }

        if ($state === ExternalOperation::UNCERTAIN) {
            $this->markDocument($docId, ExternalOperation::UNCERTAIN);
            return $this->summary('uncertain', $state, $orderId, $docId, null, null, (string) ($run['result']?->code ?? 'TIMEOUT_AFTER_DISPATCH'));
        }

        // LOCAL_ERROR (definitive local failure before dispatch): the ledger row stays NOT_STARTED and the
        // document row also stays NOT_STARTED, so the job can retry through the same idempotency key.
        return $this->summary('local_error', $state, $orderId, $docId, null, null, (string) ($run['result']?->code ?? 'LOCAL_ERROR'));
    }

    /**
     * Read-only reprint: returns the stored artifact for an already-issued document. Never inserts a row
     * and never changes document state (invariant §1.6).
     *
     * @return array{document_id:int,document_number:?string,pdf_ref:?string,pdf:?string}|null
     */
    public function reprint(int $documentId): ?array
    {
        $row = $this->documents->getById($documentId);
        if ($row === null || (string) $row->state !== ExternalOperation::EXTERNAL_CONFIRMED) {
            return null;
        }
        $pdf = null;
        if (!empty($row->pdf_ref)) {
            try {
                $pdf = (new SzamlazzDocumentStore())->read((string) $row->pdf_ref);
            } catch (\Throwable $e) {
                $pdf = null;
            }
        }
        return [
            'document_id' => $documentId,
            'document_number' => $this->nullableString($row->document_number ?? null),
            'pdf_ref' => $this->nullableString($row->pdf_ref ?? null),
            'pdf' => $pdf,
        ];
    }

    /** @return object|null FluentCart Order, or null when FluentCart is absent/unknown id. */
    public function loadOrder(int $orderId): ?object
    {
        $class = 'FluentCart\\App\\Models\\Order';
        if (!class_exists($class)) {
            return null;
        }
        $order = $class::find($orderId);
        return is_object($order) ? $order : null;
    }

    /**
     * Build the immutable snapshot the builder consumes. Amounts are integer minor units taken from the
     * order columns; no float arithmetic is performed on money.
     *
     * @return array<string,mixed>|null
     */
    public function buildSnapshot(object $order, int $sequence): ?array
    {
        try {
            $currency = strtoupper((string) ($order->currency ?? 'HUF'));
            $taxBehavior = (int) ($order->tax_behavior ?? 0);
            $basis = $this->amountBasis($taxBehavior);
            $businessInfo = method_exists($order, 'getBusinessInfo') ? (array) $order->getBusinessInfo() : [];

            // Optional NAV taxpayer check (SZ-008/FC-003): only when enabled; a lookup failure blocks
            // invoicing ONLY when the account requires it (04 SZAMLAZZ §9 "our integration responsibility").
            if (!$this->taxpayerAcceptable($businessInfo)) {
                return null;
            }

            $components = $this->collectComponents($order, $taxBehavior);
            if ($components['items'] === []) {
                return null;
            }

            $today = function_exists('current_time') ? (string) current_time('Y-m-d') : gmdate('Y-m-d');
            $fulfillment = $this->dateOnly($order->completed_at ?? null) ?? $today;
            $deadlineDays = (int) $this->setting('payment_deadline_days', 8);
            $due = gmdate('Y-m-d', strtotime($today . ' +' . max(0, $deadlineDays) . ' days'));

            $snapshot = [
                'order_id' => (string) ($order->id ?? ''),
                'currency' => $currency,
                'amount_basis' => $basis,
                // FluentCart stores amount×100 for every currency (Helper::toCent(), even HUF); the invoice
                // snapshot carries our ISO minor units (HUF = forint) — 04 SZAMLAZZ expects HUF integers.
                'financials' => [
                    'subtotal' => FluentCartMoney::fromCartCents((int) ($order->subtotal ?? 0), $currency),
                    'discount_tax' => FluentCartMoney::fromCartCents((int) ($order->discount_tax ?? 0), $currency),
                    'manual_discount_total' => FluentCartMoney::fromCartCents((int) ($order->manual_discount_total ?? 0), $currency),
                    'coupon_discount_total' => FluentCartMoney::fromCartCents((int) ($order->coupon_discount_total ?? 0), $currency),
                    'shipping_tax' => FluentCartMoney::fromCartCents((int) ($order->shipping_tax ?? 0), $currency),
                    'shipping_total' => FluentCartMoney::fromCartCents((int) ($order->shipping_total ?? 0), $currency),
                    'fee_total' => FluentCartMoney::fromCartCents((int) ($order->fee_total ?? 0), $currency),
                    'tax_total' => FluentCartMoney::fromCartCents((int) ($order->tax_total ?? 0), $currency),
                    'total_amount' => FluentCartMoney::fromCartCents((int) ($order->total_amount ?? 0), $currency),
                    'tax_behavior' => $taxBehavior,
                ],
                'seller' => [
                    'agent_key' => (string) SzamlazzModule::agentKey(),
                    'eszamla' => (bool) $this->setting('eszamla', false),
                    'szamla_letoltes' => false,
                    'valasz_verzio' => 2,
                    'szamla_kulso_azon' => ExternalOperation::idempotencyKey((int) $order->id, 'create_invoice', $sequence),
                    'bank' => (string) $this->setting('bank_name', ''),
                    'bankszamlaszam' => (string) $this->setting('bank_account', ''),
                    'email_replyto' => (string) $this->setting('email_replyto', ''),
                    'email_targy' => (string) $this->setting('email_targy', ''),
                    'email_szoveg' => (string) $this->setting('email_szoveg', ''),
                    'szamlaszam_elotag' => (string) $this->setting('szamlaszam_elotag', ''),
                ],
                'header' => [
                    'kelt_datum' => $today,
                    'teljesites_datum' => $fulfillment,
                    'fizetesi_hatarido_datum' => $due,
                    'fizmod' => (string) $this->setting('fizmod', 'Átutalás'),
                    'penznem' => $currency,
                    'szamla_nyelve' => (string) $this->setting('invoice_language', 'hu'),
                    'megjegyzes' => (string) $this->setting('note', ''),
                    'rendeles_szam' => SzamlazzXmlBuilder::orderReference((int) $order->id),
                ],
                'buyer' => $this->buyer($order, $businessInfo),
                'items' => $components['items'],
            ];
            return $snapshot;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Product/shipping/fee lines with order-exact integer net/VAT/gross.
     *  - exclusive (tax_behavior=1): line_total is net; gross = net + tax_amount
     *  - inclusive (2):             line_total is gross; net = gross - tax_amount
     *  - no_tax (0):                net = gross = line_total; vat = 0
     * Discounts are already reflected in `line_total` (subtotal - discount_total), so no synthetic
     * negative coupon line is added (04 SZAMLAZZ §4.2 + ReportTools.php:1149).
     *
     * @return array{items:list<array<string,mixed>>}
     */
    private function collectComponents(object $order, int $taxBehavior): array
    {
        $currency = strtoupper((string) ($order->currency ?? 'HUF'));
        $items = [];
        $orderItems = method_exists($order, 'order_items') ? $order->order_items()->get() : [];
        foreach ($this->toList($orderItems) as $line) {
            // FluentCart collections expose rows as associative arrays through toArray(); normalize to an
            // object so the property reads below cannot silently return null/0 (invoice items must carry
            // the real line amounts — caught by the money-boundary integration check).
            $line = is_array($line) ? (object) $line : $line;
            if (!is_object($line)) {
                continue;
            }
            $quantity = (int) round((float) ($line->quantity ?? 1));
            if ($quantity <= 0) {
                continue;
            }
            // FC cents → our ISO minor units at every amount read (HUF: ÷100; EUR: identity).
            $lineTotal = FluentCartMoney::fromCartCents((int) ($line->line_total ?? $line->subtotal ?? 0), $currency);
            $taxAmount = FluentCartMoney::fromCartCents((int) ($line->tax_amount ?? 0), $currency);
            [$net, $vat, $gross] = $this->splitAmounts($lineTotal, $taxAmount, $taxBehavior);
            $items[] = [
                'name' => (string) ($line->title ?? $line->post_title ?? ''),
                'quantity' => $quantity,
                'unit' => 'db',
                'net_minor' => $net,
                'vat_minor' => $vat,
                'gross_minor' => $gross,
                // Per-line rate from line_meta.tax_config.rates, else the order's primary rate as a fallback.
                'vat_code' => $this->vatCode($line->line_meta ?? null) ?? $this->primaryVatCode($order),
            ];
        }

        // Shipping and fees: FluentCart models them EITHER as their own order items (e.g. the COD fee
        // appears as an "Utánvét díja" item with fee_total set as well) OR only as order-level totals.
        // Emitting both would double-count them, so anything not yet covered by the collected items is
        // added as ONE balancing line up to the order's real gross/VAT totals. The invoice therefore
        // always sums to the order total and each charge appears exactly once.
        $collectedNet = 0;
        $collectedVat = 0;
        $collectedGross = 0;
        foreach ($items as $item) {
            $collectedNet += (int) $item['net_minor'];
            $collectedVat += (int) $item['vat_minor'];
            $collectedGross += (int) $item['gross_minor'];
        }

        $targetGross = FluentCartMoney::fromCartCents((int) ($order->total_amount ?? 0), $currency);
        $targetVat = FluentCartMoney::fromCartCents((int) ($order->tax_total ?? 0), $currency);
        $remainingGross = $targetGross - $collectedGross;

        if ($remainingGross > 0) {
            $remainingVat = $targetVat - $collectedVat;
            if ($remainingVat < 0 || $remainingVat > $remainingGross) {
                $remainingVat = 0;
            }
            $remainingNet = $remainingGross - $remainingVat;
            $shippingTotal = FluentCartMoney::fromCartCents((int) ($order->shipping_total ?? 0), $currency);
            $items[] = [
                'name' => (string) $this->setting(
                    $shippingTotal !== 0 ? 'shipping_label' : 'fee_label',
                    $shippingTotal !== 0 ? 'Szállítási költség' : 'Kezelési költség'
                ),
                'quantity' => 1,
                'unit' => 'db',
                'net_minor' => $remainingNet,
                'vat_minor' => $remainingVat,
                'gross_minor' => $remainingGross,
                'vat_code' => $this->primaryVatCode($order),
            ];
        } elseif ($remainingGross < 0) {
            // The order items already exceed the order total (unexpected shape): never emit a negative
            // line silently — surface it for diagnostics and keep the invoice items as collected.
            do_action('fch/szamlazz/invoice_amount_mismatch', (int) ($order->id ?? 0), $collectedGross, $targetGross);
        }

        return ['items' => $items];
    }

    /** Unpaid COD order under the configured `before_dispatch` policy (pre-dispatch invoicing). */
    private function codBeforeDispatch(object $order): bool
    {
        return SzamlazzModule::invoiceOn() === SzamlazzModule::INVOICE_ON_BEFORE_DISPATCH
            && (string) ($order->payment_method ?? '') === self::COD_METHOD;
    }

    /** @param array<string,mixed> $businessInfo */
    private function taxpayerAcceptable(array $businessInfo): bool
    {
        $taxNumber = trim((string) ($businessInfo['tax_number'] ?? ''));
        if ($taxNumber === '' || !(bool) SzamlazzModule::setting('taxpayer_lookup_enabled', false)) {
            return true;
        }
        $taxpayer = new SzamlazzTaxpayer();
        $result = $taxpayer->lookup($taxNumber);
        if (!empty($result['ok']) && $result['validity'] !== false) {
            return true;
        }
        // Unknown/invalid taxpayer: only a hard block when the merchant's account requires the check.
        return !$taxpayer->required();
    }

    /** @return array{0:int,1:int,2:int} [net, vat, gross] integers */
    private function splitAmounts(int $lineTotal, int $taxAmount, int $taxBehavior): array
    {
        if ($taxBehavior === 2) {
            $gross = $lineTotal;
            $vat = max(0, $taxAmount);
            $net = $gross - $vat;
            return [$net, $vat, $gross];
        }
        if ($taxBehavior === 1) {
            $net = $lineTotal;
            $vat = max(0, $taxAmount);
            return [$net, $vat, $net + $vat];
        }
        return [$lineTotal, 0, $lineTotal];
    }

    /** @param mixed $lineMeta @return string|null numeric VAT code, or null when no rate is recorded */
    private function vatCode($lineMeta): ?string
    {
        $meta = is_array($lineMeta) ? $lineMeta : (array) $lineMeta;
        $rates = $meta['tax_config']['rates'] ?? [];
        foreach ((array) $rates as $rate) {
            $percent = $rate['rate_percent'] ?? $rate['rate'] ?? null;
            if ($percent !== null && is_numeric($percent)) {
                $formatted = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
                return $formatted === '' ? '0' : $formatted;
            }
        }
        return null;
    }

    private function primaryVatCode(object $order): string
    {
        if (method_exists($order, 'getDisplayTaxLines')) {
            foreach ($this->toList($order->getDisplayTaxLines()) as $line) {
                $rate = $line['rate_percent'] ?? null;
                if ($rate !== null && is_numeric($rate) && (float) $rate > 0) {
                    $formatted = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
                    return $formatted === '' ? '0' : $formatted;
                }
            }
        }
        return '0';
    }

    /** @param array<string,mixed> $businessInfo @return array<string,mixed> */
    private function buyer(object $order, array $businessInfo): array
    {
        $address = method_exists($order, 'billing_address') ? $order->billing_address : null;
        $company = trim((string) ($businessInfo['company_name'] ?? ''));
        $name = $company !== '' ? $company : trim((string) ($address->name ?? ''));
        if ($name === '') {
            $name = 'Vevő';
        }

        $taxNumber = trim((string) ($businessInfo['tax_number'] ?? ''));
        $taxCountry = strtoupper(trim((string) ($businessInfo['tax_number_country'] ?? '')));
        $country = strtoupper((string) ($address->country ?? 'HU'));

        $buyer = [
            'nev' => $name,
            'orszag' => $country,
            'irsz' => (string) ($address->postcode ?? ''),
            'telepules' => (string) ($address->city ?? ''),
            'cim' => trim((string) ($address->address_1 ?? '') . ' ' . (string) ($address->address_2 ?? '')),
            'email' => (string) ($address->email ?? ''),
            'send_email' => (bool) $this->setting('send_email', false),
        ];

        if ($taxNumber !== '') {
            if ($taxCountry === 'HU' || $taxCountry === '') {
                $buyer['adoszam'] = $taxNumber;
            } else {
                $buyer['adoszamEU'] = $taxNumber;
                $buyer['adoalany'] = in_array($taxCountry, self::EU_COUNTRIES, true) ? 6 : 7;
            }
        }

        return $buyer;
    }

    /** @return list<mixed> */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return array_values((array) $value->toArray());
        }
        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value));
        }
        return [];
    }

    private function amountBasis(int $taxBehavior): string
    {
        $configured = strtolower((string) $this->setting('amount_basis', 'auto'));
        if (in_array($configured, ['net', 'gross'], true)) {
            return $configured;
        }
        // auto: inclusive pricing -> gross-based (B2C), otherwise net-based.
        return $taxBehavior === 2 ? 'gross' : 'net';
    }

    /** @param array<string,mixed> $snapshot @return array{net:int,vat:int,gross:int} */
    private function totals(array $snapshot): array
    {
        $net = 0;
        $vat = 0;
        $gross = 0;
        foreach ((array) $snapshot['items'] as $item) {
            $net += (int) ($item['net_minor'] ?? 0);
            $vat += (int) ($item['vat_minor'] ?? 0);
            $gross += (int) ($item['gross_minor'] ?? 0);
        }
        return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function vatSnapshot(array $snapshot): array
    {
        $groups = [];
        $lines = [];
        foreach ((array) $snapshot['items'] as $item) {
            $code = (string) ($item['vat_code'] ?? '0');
            if (!isset($groups[$code])) {
                $groups[$code] = ['vat_code' => $code, 'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0, 'lines' => 0];
            }
            $groups[$code]['net_minor'] += (int) ($item['net_minor'] ?? 0);
            $groups[$code]['vat_minor'] += (int) ($item['vat_minor'] ?? 0);
            $groups[$code]['gross_minor'] += (int) ($item['gross_minor'] ?? 0);
            $groups[$code]['lines']++;
            // W4: the stored snapshot is the auditable source for a later corrected document — keep the
            // original item lines and the debtor (buyer) snapshot with the document, not just the groups.
            $lines[] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => $item['quantity'] ?? 1,
                'unit' => (string) ($item['unit'] ?? 'db'),
                'vat_code' => $code,
                'net_minor' => (int) ($item['net_minor'] ?? 0),
                'vat_minor' => (int) ($item['vat_minor'] ?? 0),
                'gross_minor' => (int) ($item['gross_minor'] ?? 0),
            ];
        }
        return [
            'groups' => array_values($groups),
            'lines' => $lines,
            'buyer' => is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : [],
            'amount_basis' => (string) ($snapshot['amount_basis'] ?? 'gross'),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> secret-free request summary */
    private function requestSummary(array $snapshot): array
    {
        $totals = $this->totals($snapshot);
        return [
            'action-xmlagentxmlfile' => true,
            'rendelesSzam' => (string) ($snapshot['header']['rendeles_szam'] ?? ''),
            'penznem' => (string) ($snapshot['currency'] ?? ''),
            'item_count' => count((array) $snapshot['items']),
            'net_minor' => $totals['net'],
            'vat_minor' => $totals['vat'],
            'gross_minor' => $totals['gross'],
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> secret-free response summary */
    private function responseSummary(string $body, array $parsed): array
    {
        return [
            'body_bytes' => strlen($body),
            'structure' => (string) ($parsed['structure'] ?? 'unknown'),
            'sikeres' => (bool) ($parsed['ok'] ?? false),
            'szamlaszam' => $parsed['invoice_number'] ?? null,
            'hibakod' => $parsed['error_code'] ?? null,
            'is_pdf' => (bool) ($parsed['is_pdf'] ?? false),
        ];
    }

    private function interpretCreateResponse(Response $response, array $snapshot, array $request): ProviderCallResult
    {
        if ($response->error !== null || $response->status === 0) {
            // Lost response after dispatch: outcome unknown, never auto-retried (invariant §1.4 / 04 UB §2).
            return ProviderCallResult::uncertain('TIMEOUT_AFTER_DISPATCH', $request, ['error' => (string) $response->error]);
        }

        $parsed = SzamlazzResponseParser::parse($response->body, $response->headers);
        $responseSummary = $this->responseSummary($response->body, $parsed);

        if (!empty($parsed['ok'])) {
            $totals = $this->totals($snapshot);
            $documentNumber = (string) ($parsed['invoice_number'] ?? '');
            if ($documentNumber === '') {
                return ProviderCallResult::localError('MISSING_DOCUMENT_NUMBER', 'Provider reported success without szamlaszam.', $request);
            }
            return ProviderCallResult::success([
                'external_id' => $documentNumber,
                'external_ref2' => (string) ($snapshot['seller']['szamla_kulso_azon'] ?? ''),
                'document_number' => $documentNumber,
                'provider_doc_type' => 'invoice',
                'gross_minor' => $totals['gross'],
                'net_minor' => $totals['net'],
                'vat_minor' => $totals['vat'],
                'currency' => (string) ($snapshot['currency'] ?? ''),
                'vat_snapshot' => wp_json_encode($this->vatSnapshot($snapshot)),
                'order_fin_snapshot_hash' => SzamlazzXmlBuilder::hashSnapshot($snapshot),
            ], $request, $responseSummary);
        }

        if (!empty($parsed['local'])) {
            return ProviderCallResult::localError((string) ($parsed['error_code'] ?? 'UNKNOWN_RESPONSE'), (string) ($parsed['error_message'] ?? ''), $request);
        }

        $code = (string) ($parsed['error_code'] ?? 'REJECTED');
        $message = (string) ($parsed['error_message'] ?? '');
        if (empty($parsed['definitive'])) {
            // Transient agent error (e.g. code 1 maintenance): not a business rejection, prove via reconcile.
            return ProviderCallResult::uncertain('TRANSIENT_PROVIDER_ERROR:' . $code, $request, $responseSummary);
        }
        return ProviderCallResult::rejected($code, $message, $request, $responseSummary);
    }

    /**
     * Mark the document issued and store the PDF read-back. The ledger already proved success; a failed
     * PDF fetch must not flip the proved outcome (the invoice exists), so it is recorded as absent.
     *
     * @param array<string,mixed> $fields
     */
    private function finalizeIssued(int $docId, object $ledgerRow, array $fields, SzamlazzClient $client, array $snapshot, array $totals): void
    {
        $row = $this->documents->getById($docId);
        if ($row !== null && (string) $row->state === ExternalOperation::EXTERNAL_CONFIRMED) {
            return;
        }

        $documentNumber = (string) ($fields['document_number'] ?? $ledgerRow->external_id ?? '');
        if ($documentNumber === '') {
            return;
        }

        $pdfRef = null;
        $pdfSha = null;
        if ((bool) $this->setting('pdf_download', true)) {
            try {
                $pdfResponse = $client->downloadInvoicePdf($documentNumber, [
                    'szamla_kulso_azon' => (string) ($snapshot['seller']['szamla_kulso_azon'] ?? ''),
                ]);
                $pdf = SzamlazzClient::extractPdf($pdfResponse);
                if ($pdf !== null && $pdf !== '') {
                    $stored = (new SzamlazzDocumentStore())->store('szamlazz-invoice-' . $documentNumber, $pdf, 'pdf');
                    $pdfRef = $stored['ref'];
                    $pdfSha = $stored['sha256'];
                }
            } catch (\Throwable $e) {
                $pdfRef = null;
                $pdfSha = null;
            }
        }

        $extra = [
            'document_number' => $documentNumber,
            'external_id' => $documentNumber,
            'external_ref2' => (string) ($snapshot['seller']['szamla_kulso_azon'] ?? ''),
            'provider_doc_type' => 'invoice',
            'gross_minor' => (int) ($fields['gross_minor'] ?? $totals['gross']),
            'net_minor' => (int) ($fields['net_minor'] ?? $totals['net']),
            'vat_minor' => (int) ($fields['vat_minor'] ?? $totals['vat']),
            'currency' => (string) ($snapshot['currency'] ?? ''),
            'vat_snapshot' => (string) ($fields['vat_snapshot'] ?? wp_json_encode($this->vatSnapshot($snapshot))),
            'order_fin_snapshot_hash' => (string) ($fields['order_fin_snapshot_hash'] ?? SzamlazzXmlBuilder::hashSnapshot($snapshot)),
            'issued_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($pdfRef !== null) {
            $extra['pdf_ref'] = $pdfRef;
            $extra['pdf_sha256'] = $pdfSha;
        }

        self::ensureDocumentInProgress($this->documents, $docId);
        $this->documents->markIssued($docId, $extra);
    }

    public static function ensureDocumentInProgress(InvoiceDocumentRepository $repo, int $docId): void
    {
        $row = $repo->getById($docId);
        if ($row === null) {
            return;
        }
        $state = (string) $row->state;
        if (in_array($state, [ExternalOperation::IN_PROGRESS, ExternalOperation::EXTERNAL_CONFIRMED, ExternalOperation::PROVEN_REJECTED, ExternalOperation::SUPERSEDED, ExternalOperation::MANUALLY_RESOLVED, ExternalOperation::UNCERTAIN, ExternalOperation::AWAITING_RECONCILIATION], true)) {
            return;
        }
        if (method_exists($repo, 'markStarted')) {
            $repo->markStarted($docId);
            return;
        }
        // Core gap (W0): InvoiceDocumentRepository exposes no public in-progress setter, but the mirror
        // state machine requires NOT_STARTED -> IN_PROGRESS before any terminal transition. Bridge through
        // the repository's own guarded transition so the state machine is still enforced.
        $method = new \ReflectionMethod(InvoiceDocumentRepository::class, 'transitionRow');
        $method->setAccessible(true);
        $method->invoke($repo, $docId, ExternalOperation::IN_PROGRESS, [], ExternalOperation::NOT_STARTED, []);
    }

    private function markDocument(int $docId, string $terminalState): void
    {
        self::ensureDocumentInProgress($this->documents, $docId);
        if ($terminalState === ExternalOperation::UNCERTAIN) {
            $this->documents->markUncertain($docId);
            return;
        }
        if ($terminalState === ExternalOperation::PROVEN_REJECTED) {
            $this->documents->markRejected($docId);
        }
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->get(self::SETTINGS_SCOPE, $key, $default);
    }

    private function dateOnly(mixed $value): ?string
    {
        $value = (string) $value;
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d', $timestamp);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $value === null ? '' : (string) $value;
        return $value === '' ? null : $value;
    }

    private function summary(string $status, string $state, int $orderId, ?int $docId, ?string $number, ?string $pdfRef, ?string $reason = null): array
    {
        $out = [
            'status' => $status,
            'state' => $state,
            'order_id' => $orderId,
            'document_id' => $docId,
            'document_number' => $number,
            'pdf_ref' => $pdfRef,
        ];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        return $out;
    }
}
