<?php
declare(strict_types=1);

use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzResponseParser;
use FluentCartHungary\Tests\Mock\MockTransport;

/**
 * W1 billing/szamlazz — pure response parser (04_SZAMLAZZ §3.4/§5).
 */

function fch_sz_fixture(string $name): string
{
    return MockTransport::fixtureRaw('szamlazz/' . $name);
}

fch_test('SZH-RSP-01 success xmlszamlavalasz exposes invoice number and amounts', function (): void {
    $r = SzamlazzResponseParser::parse(fch_sz_fixture('success.xml'));
    assert_true($r['ok']);
    assert_same('xml', $r['structure']);
    assert_same('FCH-2026-000123', $r['invoice_number']);
    assert_same('30000', $r['net']);
    assert_same('38100', $r['gross']);
    assert_same('0', $r['outstanding']);
    assert_true(str_contains((string) $r['buyer_account_url'], 'vevoifiokpay'));
    assert_same(false, $r['definitive']);
});

fch_test('SZH-RSP-02 duplicate order number (71/152) is a definitive rejection', function (): void {
    $r = SzamlazzResponseParser::parse(fch_sz_fixture('error_duplicate_152.xml'));
    assert_same(false, $r['ok']);
    assert_same('152', $r['error_code']);
    assert_true($r['definitive']);
    assert_true(str_contains((string) $r['error_message'], 'Már létező rendelésszám'));
    assert_same(false, $r['local']);
});

fch_test('SZH-RSP-03 account prefix (202) and kata protection (491) are definitive', function (): void {
    foreach (['error_prefix_202.xml' => '202', 'error_kata_491.xml' => '491'] as $fixture => $code) {
        $r = SzamlazzResponseParser::parse(fch_sz_fixture($fixture));
        assert_same($code, $r['error_code'], $fixture);
        assert_true($r['definitive'], $fixture);
    }
});

fch_test('SZH-RSP-04 transient agent error (1) is not definitive', function (): void {
    $r = SzamlazzResponseParser::parse(fch_sz_fixture('error_transient_1.xml'));
    assert_same(false, $r['ok']);
    assert_same('1', $r['error_code']);
    assert_same(false, $r['definitive']);
});

fch_test('SZH-RSP-05 inline base64 pdf is detected', function (): void {
    $r = SzamlazzResponseParser::parse(fch_sz_fixture('pdf_response.xml'));
    assert_true($r['ok']);
    assert_true($r['is_pdf']);
    assert_same('FCH-2026-000123', $r['invoice_number']);
    assert_true(str_starts_with((string) $r['pdf_base64'], 'JVBERi0'));
});

fch_test('SZH-RSP-06 raw PDF body is detected', function (): void {
    $r = SzamlazzResponseParser::parse("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n");
    assert_true($r['ok']);
    assert_true($r['is_pdf']);
    assert_same('pdf', $r['structure']);
    assert_same(base64_encode("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n"), $r['pdf_base64']);
});

fch_test('SZH-RSP-07 unknown structure is a local error, not a rejection', function (): void {
    $r = SzamlazzResponseParser::parse(fch_sz_fixture('unknown.xml'));
    assert_same(false, $r['ok']);
    assert_same('UNKNOWN_RESPONSE', $r['error_code']);
    assert_true($r['local']);
    assert_same(false, $r['definitive']);
});

fch_test('SZH-RSP-08 query returns full szamla doc => found', function (): void {
    $r = SzamlazzResponseParser::parse(fch_sz_fixture('query_szamla.xml'));
    assert_true($r['ok']);
    assert_same('xml_szamla', $r['structure']);
    assert_same('FCH-2026-000123', $r['invoice_number']);
    assert_same('30000', $r['net']);
    assert_same('38100', $r['gross']);
});

fch_test('SZH-RSP-09 query of an unknown id (code 7) proves absence', function (): void {
    $body = '<xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>false</sikeres><hibakod>7</hibakod><hibauzenet>Hiányzó adat</hibauzenet></xmlszamlavalasz>';
    $r = SzamlazzResponseParser::parse($body);
    assert_same(false, $r['ok']);
    assert_same('7', $r['error_code']);
    assert_true($r['definitive']);
});

fch_test('SZH-RSP-10 v1 text DONE and [ERR] forms', function (): void {
    $done = SzamlazzResponseParser::parse('xmlagentresponse=DONE;FCH-2026-9');
    assert_true($done['ok']);
    assert_same('FCH-2026-9', $done['invoice_number']);

    $err = SzamlazzResponseParser::parse('[ERR] Számla mentés sikertelen. ---------- t.getMessage(): x [CEG:1]');
    assert_same(false, $err['ok']);
    assert_true($err['definitive']);
    assert_true(str_contains((string) $err['error_message'], 'Számla mentés sikertelen'));
});

fch_test('SZH-RSP-11 empty/malformed bodies are local errors', function (): void {
    $empty = SzamlazzResponseParser::parse('');
    assert_same('EMPTY_RESPONSE', $empty['error_code']);
    assert_true($empty['local']);

    $malformed = SzamlazzResponseParser::parse('<xmlszamlavalasz><sikeres>true');
    assert_same('UNKNOWN_RESPONSE', $malformed['error_code']);
    assert_true($malformed['local']);
});

fch_test('SZH-RSP-12 header-only legacy response yields the invoice number', function (): void {
    $r = SzamlazzResponseParser::parse('', ['szlahu_szamlaszam' => 'E-2020-123']);
    assert_true($r['ok']);
    assert_same('E-2020-123', $r['invoice_number']);
    assert_same('headers', $r['structure']);
});
