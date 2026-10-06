<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Billing\Billingo\BillingoMapper;
use FluentCartHungary\Modules\Billing\Billingo\BillingoStatus;

/**
 * Billingo contract vectors and pure mapping logic (04_BILLINGO_CONTRACT.md §3-§6;
 * DOCS/BILLINGO/BILLINGO_DEVELOPERS_API_V3.md item/partner/payment-method tables).
 */

fch_test('BI-VENDOR vendor_id is short, stable and provider-scoped', function (): void {
    $vendor = BillingoStatus::vendorId(990001, 'billingo', 'invoice', 1);
    assert_same('fch-990001-billingo-invoice-1', $vendor);
    assert_true(strlen($vendor) <= 64);
    assert_true((bool) preg_match('/^[a-z0-9-]+$/', $vendor));
    assert_true(BillingoStatus::vendorId(990001, 'billingo', 'invoice', 2) !== $vendor);
    assert_true(BillingoStatus::vendorId(990001, 'billingo', 'proforma', 1) !== $vendor);
    // The canonical key space belongs to Számlázz: Billingo never produces it.
    assert_true(!str_starts_with($vendor, 'fch:'));
});

fch_test('BI-PAYMENT payment-method mapping follows the documented enum', function (): void {
    assert_same('cash_on_delivery', BillingoStatus::paymentMethodFor('offline_payment'));
    assert_same('barion', BillingoStatus::paymentMethodFor('barion'));
    assert_same('online_bankcard', BillingoStatus::paymentMethodFor('simplepay'));
    assert_same('elore_utalas', BillingoStatus::paymentMethodFor('unknown_method'));
    assert_same('cash_on_delivery', BillingoStatus::paymentMethodFor('barion', ['barion' => 'cash_on_delivery']));
    assert_same('other', BillingoStatus::paymentMethodFor('', [], 'other'));
});

fch_test('BI-AMOUNT provider amounts derive from integer minor units', function (): void {
    assert_same(4240, BillingoStatus::amountForProvider(4240, 'HUF'));
    assert_same(12.34, BillingoStatus::amountForProvider(1234, 'EUR'));
    assert_same(200.0, BillingoStatus::amountForProvider(20000, 'EUR'));
    assert_same(1.05, BillingoStatus::amountForProvider(105, 'EUR'));
});

fch_test('BI-ERROR HTTP classes map to the documented outcomes', function (): void {
    foreach ([200, 201, 204] as $http) {
        assert_same(BillingoStatus::OUTCOME_SUCCESS, BillingoStatus::classifyHttp($http)['outcome']);
    }
    assert_same('AUTH_FAILED', BillingoStatus::classifyHttp(401)['code']);
    assert_same('NO_SUBSCRIPTION', BillingoStatus::classifyHttp(402)['code']);
    assert_same('FORBIDDEN', BillingoStatus::classifyHttp(403)['code']);
    assert_same(BillingoStatus::OUTCOME_REJECTED, BillingoStatus::classifyHttp(422)['outcome']);
    assert_same(BillingoStatus::OUTCOME_LOCAL_ERROR, BillingoStatus::classifyHttp(429)['outcome']);
    assert_same(BillingoStatus::OUTCOME_UNCERTAIN, BillingoStatus::classifyHttp(500)['outcome']);
    assert_same(BillingoStatus::OUTCOME_UNCERTAIN, BillingoStatus::classifyHttp(0)['outcome']);
    assert_same(BillingoStatus::OUTCOME_UNCERTAIN, BillingoStatus::classifyHttp(408)['outcome']);
});

fch_test('BI-ERROR error labels are merchant-visible and secret-free', function (): void {
    $label = BillingoStatus::errorLabel(['message' => 'Validation error', 'errors' => ['items' => ['required']]], 422);
    assert_true(str_contains($label, 'Validation error'));
    assert_true(str_contains($label, 'items: required'));
    assert_same('Érvénytelen vagy hiányzó API kulcs.', BillingoStatus::errorLabel(null, 401));
    assert_true(str_contains(BillingoStatus::errorLabel(null, 429), '300'));
    assert_true(strlen(BillingoStatus::errorLabel(null, 500)) < 300);
});

fch_test('BI-PARTNER partner payload carries B2C/B2B/VAT fields', function (): void {
    $snapshot = ['buyer' => [
        'nev' => 'Teszt Vevő', 'orszag' => 'hu', 'irsz' => '1066', 'telepules' => 'Budapest',
        'cim' => 'Fő utca 1', 'cim2' => '2. em.', 'email' => 'buyer@example.test', 'phone' => '+3612345678',
        'adoszam' => '12345678-1-42', 'adoszamEU' => '',
    ]];
    $payload = BillingoMapper::partnerPayload($snapshot);
    assert_same('Teszt Vevő', $payload['name']);
    assert_same('HU', $payload['address']['country_code']);
    assert_same('1066', $payload['address']['post_code']);
    assert_same('Fő utca 1', $payload['address']['address']);
    assert_same('2. em.', $payload['address']['address2']);
    assert_same(['buyer@example.test'], $payload['emails']);
    assert_same('12345678-1-42', $payload['taxcode']);
    assert_same('HAS_TAX_NUMBER', $payload['tax_type']);

    $eu = BillingoMapper::partnerPayload(['buyer' => ['nev' => 'EU Kft', 'adoszamEU' => 'DE123456789']]);
    assert_same('FOREIGN', $eu['tax_type']);
    $none = BillingoMapper::partnerPayload(['buyer' => ['nev' => 'Magánszemély']]);
    assert_same('NO_TAX_NUMBER', $none['tax_type']);
});

fch_test('BI-ITEMS items use the documented unit_price_type and exact unit prices', function (): void {
    $snapshot = [
        'currency' => 'HUF',
        'amount_basis' => 'gross',
        'header' => ['vendor_id' => 'fch-1-billingo-invoice-1', 'teljesites_datum' => '2026-10-06', 'fizetesi_hatarido_datum' => '2026-10-14'],
        'items' => [
            ['name' => 'Termék', 'quantity' => 2, 'net_minor' => 7580, 'vat_minor' => 0, 'gross_minor' => 7580, 'vat_code' => '0', 'unit' => 'db'],
            ['name' => 'Utánvét díja', 'quantity' => 1, 'net_minor' => 450, 'vat_minor' => 0, 'gross_minor' => 450, 'vat_code' => '0', 'unit' => 'db'],
        ],
    ];
    $items = BillingoMapper::itemsPayload($snapshot, []);
    assert_same(2, $items[0]['quantity']);
    assert_same('gross', $items[0]['unit_price_type']);
    assert_same(3790, $items[0]['unit_price']);
    assert_same(450, $items[1]['unit_price']);
    assert_same('0', $items[0]['vat']);

    // Inexact unit price: loud single-line fallback instead of silent rounding.
    $snapshot['items'][0]['quantity'] = 3;
    $items = BillingoMapper::itemsPayload($snapshot, []);
    assert_same(1, $items[0]['quantity']);
    assert_true(str_contains((string) $items[0]['name'], '× 3'));
    assert_same(7580, $items[0]['unit_price']);
});

fch_test('BI-ITEMS VAT override and entitlement apply', function (): void {
    $snapshot = [
        'currency' => 'HUF', 'amount_basis' => 'net',
        'items' => [['name' => 'T', 'quantity' => 1, 'net_minor' => 1000, 'vat_minor' => 270, 'gross_minor' => 1270, 'vat_code' => '27']],
    ];
    $items = BillingoMapper::itemsPayload($snapshot, ['vat_override' => '5', 'entitlement' => 'AAM']);
    assert_same('5', $items[0]['vat']);
    assert_same('AAM', $items[0]['entitlement']);
    $items = BillingoMapper::itemsPayload($snapshot, []);
    assert_same('27', $items[0]['vat']);
    assert_true(!isset($items[0]['entitlement']));
});

fch_test('BI-DOC document payload has the required Billingo fields', function (): void {
    $snapshot = [
        'currency' => 'HUF',
        'amount_basis' => 'gross',
        'header' => ['vendor_id' => 'fch-7-billingo-invoice-1', 'teljesites_datum' => '2026-10-06', 'fizetesi_hatarido_datum' => '2026-10-14'],
        'items' => [['name' => 'T', 'quantity' => 1, 'net_minor' => 1000, 'vat_minor' => 0, 'gross_minor' => 1000, 'vat_code' => '0']],
    ];
    $payload = BillingoMapper::documentPayload($snapshot, [
        'type' => 'invoice', 'language' => 'hu', 'payment_method' => 'cash_on_delivery',
        'conversion_rate' => 1.0, 'block_id' => 12, 'bank_account_id' => 0, 'comment' => 'Megjegyzés', 'electronic' => true,
    ], 555);
    foreach (['block_id', 'currency', 'due_date', 'fulfillment_date', 'language', 'partner_id', 'payment_method', 'type', 'items', 'vendor_id'] as $key) {
        assert_true(array_key_exists($key, $payload), 'missing ' . $key);
    }
    assert_same(555, $payload['partner_id']);
    assert_same(12, $payload['block_id']);
    assert_same('invoice', $payload['type']);
    assert_same('2026-10-14', $payload['due_date']);
    assert_true($payload['electronic'] === true);
    assert_true(!array_key_exists('bank_account_id', $payload)); // 0 = account default, omitted
});

fch_test('BI-HASH snapshot hash is deterministic', function (): void {
    $snapshot = ['a' => 1, 'b' => ['c' => 'x']];
    assert_same(BillingoMapper::hashSnapshot($snapshot), BillingoMapper::hashSnapshot($snapshot));
    assert_true(BillingoMapper::hashSnapshot($snapshot) !== BillingoMapper::hashSnapshot(['a' => 1]));
    assert_same(64, strlen(BillingoMapper::hashSnapshot($snapshot)));
});
