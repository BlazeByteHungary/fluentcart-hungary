<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzXmlBuilder;

/**
 * W1 billing/szamlazz — pure XML builder (04_SZAMLAZZ §2.2/§4.5/§4.6; 05_DATA_SCHEMA §3.6).
 */

function fch_sz_snapshot(array $overrides = []): array
{
    $base = [
        'order_id' => 1001,
        'currency' => 'HUF',
        'amount_basis' => 'net',
        'financials' => [
            'subtotal' => 1500,
            'discount_tax' => 0,
            'manual_discount_total' => 0,
            'coupon_discount_total' => 0,
            'shipping_tax' => 0,
            'shipping_total' => 0,
            'fee_total' => 0,
            'tax_total' => 405,
            'total_amount' => 1905,
            'tax_behavior' => 1,
        ],
        'seller' => [
            'agent_key' => 'TEST-AGENT-KEY',
            'eszamla' => false,
            'szamla_letoltes' => false,
            'valasz_verzio' => 2,
            'szamla_kulso_azon' => 'fch:1001:create_invoice:1',
            'szamlaszam_elotag' => '',
        ],
        'header' => [
            'kelt_datum' => '2026-01-20',
            'teljesites_datum' => '2026-01-20',
            'fizetesi_hatarido_datum' => '2026-01-28',
            'fizmod' => 'Átutalás',
            'penznem' => 'HUF',
            'szamla_nyelve' => 'hu',
            'rendeles_szam' => 'FCH-1001',
        ],
        'buyer' => [
            'nev' => 'Teszt Bt.',
            'orszag' => 'HU',
            'irsz' => '2030',
            'telepules' => 'Érd',
            'cim' => 'Tárnoki út 23.',
            'email' => 'teszt@example.invalid',
            'send_email' => false,
            'adoszam' => '12345678-1-42',
        ],
        'items' => [
            ['name' => 'Könyv', 'quantity' => 3, 'unit' => 'db', 'unit_net_minor' => 500, 'vat_code' => '27'],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

fch_test('SZH-XML-01 build produces well-formed xmlszamla in documented XSD order', function (): void {
    $xml = SzamlazzXmlBuilder::build(fch_sz_snapshot());
    $dom = new \DOMDocument();
    assert_true($dom->loadXML($xml), 'builder output must be well-formed XML');
    assert_same('xmlszamla', $dom->documentElement->localName);
    assert_true(str_contains($xml, '<szamlaagentkulcs>TEST-AGENT-KEY</szamlaagentkulcs>'), 'agent key in beallitasok');

    $tagOrder = ['<beallitasok>', '<fejlec>', '<elado>', '<vevo>', '<fuvarlevel>', '<tetelek>'];
    $last = -1;
    foreach ($tagOrder as $tag) {
        $pos = strpos($xml, $tag);
        assert_true($pos !== false && $pos > $last, 'tag order broken at ' . $tag);
        $last = $pos;
    }
    assert_true(str_contains($xml, '<nettoErtek>1500</nettoErtek>'), 'net item');
    assert_true(str_contains($xml, '<afaErtek>405</afaErtek>'), 'vat item');
    assert_true(str_contains($xml, '<bruttoErtek>1905</bruttoErtek>'), 'gross item');
});

fch_test('SZH-XML-02 net-based HUF rounding matches 04 SZAMLAZZ §4.5 example', function (): void {
    $amounts = SzamlazzXmlBuilder::computeItemAmounts(
        ['quantity' => 3, 'unit_net_minor' => 500, 'vat_code' => '27'],
        'net',
        'HUF'
    );
    assert_same(1500, $amounts['net']);
    assert_same(405, $amounts['vat']);
    assert_same(1905, $amounts['gross']);
    assert_same('500', $amounts['unit_net']);
});

fch_test('SZH-XML-03 gross-based HUF rounding matches 04 SZAMLAZZ §4.5 example', function (): void {
    $amounts = SzamlazzXmlBuilder::computeItemAmounts(
        ['quantity' => 3, 'unit_gross_minor' => 500, 'vat_code' => '27'],
        'gross',
        'HUF'
    );
    assert_same(1500, $amounts['gross']);
    assert_same(319, $amounts['vat']);
    assert_same(1181, $amounts['net']);
    assert_same('393.66', $amounts['unit_net']);
    assert_same($amounts['gross'], $amounts['net'] + $amounts['vat']);
});

fch_test('SZH-XML-04 HUF item amounts are always integers (net+vat=gross)', function (): void {
    $x = SzamlazzXmlBuilder::computeItemAmounts(['quantity' => 2, 'unit_net_minor' => 999, 'vat_percent' => 25.5], 'net', 'HUF');
    foreach (['net', 'vat', 'gross'] as $key) {
        assert_true(is_int($x[$key]), $key . ' must be int');
    }
    assert_same($x['gross'], $x['net'] + $x['vat']);
});

fch_test('SZH-XML-05 VAT codes are emitted verbatim (AAM zero-rated)', function (): void {
    $xml = SzamlazzXmlBuilder::build(fch_sz_snapshot([
        'items' => [['name' => 'Adómentes', 'quantity' => 1, 'unit_net_minor' => 10000, 'vat_code' => 'AAM']],
    ]));
    assert_true(str_contains($xml, '<afakulcs>AAM</afakulcs>'), 'AAM code verbatim');
    assert_true(str_contains($xml, '<afaErtek>0</afaErtek>'), 'AAM has zero VAT');
    assert_true(str_contains($xml, '<bruttoErtek>10000</bruttoErtek>'), 'gross = net for AAM');
});

fch_test('SZH-XML-06 B2B buyer carries adoszam, B2C omits it', function (): void {
    $b2b = SzamlazzXmlBuilder::build(fch_sz_snapshot());
    assert_true(str_contains($b2b, '<adoszam>12345678-1-42</adoszam>'), 'B2B tax number present');

    $b2c = SzamlazzXmlBuilder::build(fch_sz_snapshot([
        'buyer' => ['nev' => 'Magán Személy', 'adoszam' => ''],
    ]));
    assert_true(!str_contains($b2c, '<adoszam>'), 'B2C must not carry adoszam');
});

fch_test('SZH-XML-07 EU buyer (adoszamEU + adoalany) emitted when supplied', function (): void {
    $xml = SzamlazzXmlBuilder::build(fch_sz_snapshot([
        'buyer' => ['adoszam' => '', 'adoszamEU' => 'DE123456789', 'adoalany' => 6],
    ]));
    assert_true(str_contains($xml, '<adoszamEU>DE123456789</adoszamEU>'), 'EU tax number');
    assert_true(str_contains($xml, '<adoalany>6</adoalany>'), 'EU taxpayer enum');
});

fch_test('SZH-XML-08 snapshot hash is deterministic and sensitive to financial change', function (): void {
    $a = SzamlazzXmlBuilder::hashSnapshot(fch_sz_snapshot());
    $b = SzamlazzXmlBuilder::hashSnapshot(fch_sz_snapshot());
    assert_same($a, $b);
    assert_same(64, strlen($a));

    $changed = fch_sz_snapshot();
    $changed['financials']['total_amount'] = 1906;
    assert_true($a !== SzamlazzXmlBuilder::hashSnapshot($changed), 'hash must change with totals');
});

fch_test('SZH-XML-09 missing unit price and unknown VAT code are rejected', function (): void {
    assert_throws(
        static fn() => SzamlazzXmlBuilder::computeItemAmounts(['quantity' => 1, 'vat_code' => '27'], 'net', 'HUF'),
        \InvalidArgumentException::class,
        'net item without unit_net_minor'
    );
    assert_throws(
        static fn() => SzamlazzXmlBuilder::computeItemAmounts(['quantity' => 1, 'unit_net_minor' => 100, 'vat_code' => 'NOPE'], 'net', 'HUF'),
        \InvalidArgumentException::class,
        'unknown VAT code'
    );
    $emptyItems = fch_sz_snapshot();
    $emptyItems['items'] = [];
    assert_throws(
        static fn() => SzamlazzXmlBuilder::build($emptyItems),
        \InvalidArgumentException::class,
        'empty items'
    );
});

fch_test('SZH-XML-12 explicit order-exact amounts are preserved verbatim', function (): void {
    $amounts = SzamlazzXmlBuilder::computeItemAmounts([
        'quantity' => 3,
        'net_minor' => 1181,
        'vat_minor' => 319,
        'gross_minor' => 1500,
        'vat_code' => '27',
    ], 'gross', 'HUF');
    assert_same(1181, $amounts['net']);
    assert_same(319, $amounts['vat']);
    assert_same(1500, $amounts['gross']);
    assert_same('27', $amounts['vat_code']);

    $bad = [
        'quantity' => 1,
        'net_minor' => 100,
        'vat_minor' => 27,
        'gross_minor' => 128,
        'vat_code' => '27',
    ];
    assert_throws(
        static fn() => SzamlazzXmlBuilder::computeItemAmounts($bad, 'gross', 'HUF'),
        \LogicException::class,
        'net+vat must equal gross'
    );
});

fch_test('SZH-XML-10 order reference is stable and namespaced', function (): void {
    assert_same('FCH-1001', SzamlazzXmlBuilder::orderReference(1001));
    assert_same('FCH-abc', SzamlazzXmlBuilder::orderReference('abc'));
    assert_same('FCH-42', SzamlazzXmlBuilder::orderReference('4:2'));
});

fch_test('SZH-XML-11 canonical snapshot keeps the §3.6 tuple fields', function (): void {
    $canonical = SzamlazzXmlBuilder::canonicalSnapshot(fch_sz_snapshot());
    foreach (['order_id', 'currency', 'subtotal', 'discount_tax', 'manual_discount_total', 'coupon_discount_total', 'shipping_tax', 'shipping_total', 'fee_total', 'tax_total', 'total_amount', 'tax_behavior', 'items'] as $key) {
        assert_true(array_key_exists($key, $canonical), 'missing canonical field ' . $key);
    }
    assert_same('HUF', $canonical['currency']);
    assert_same(1905, $canonical['total_amount']);
});
