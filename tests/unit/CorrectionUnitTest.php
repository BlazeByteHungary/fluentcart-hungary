<?php
declare(strict_types=1);

use FluentCartHungary\Core\Billing\CorrectionPolicy;
use FluentCartHungary\Core\Billing\InvoiceCorrectionService;
use FluentCartHungary\Modules\Billing\Szamlazz\SzamlazzResponseParser;

/**
 * W4 invoicing-correction unit vectors: the pure policy decision, the documented-capability fence and
 * the Számlázz proforma-delete response shape. No WordPress/DB dependency.
 */

fch_test('COR-POLICY only a full refund with an explicit policy maps to a correction', function (): void {
    assert_same(null, CorrectionPolicy::autoKindForRefund(CorrectionPolicy::OFF, true));
    assert_same(null, CorrectionPolicy::autoKindForRefund(CorrectionPolicy::OFF, false));
    assert_same(null, CorrectionPolicy::autoKindForRefund(CorrectionPolicy::STORNO_ON_FULL_REFUND, false));
    assert_same('storno_invoice', CorrectionPolicy::autoKindForRefund(CorrectionPolicy::STORNO_ON_FULL_REFUND, true));
    assert_same('off', CorrectionPolicy::normalize('nonsense'));
    assert_same('off', CorrectionPolicy::normalize(''));
    assert_same('storno_on_full_refund', CorrectionPolicy::normalize('STORNO_ON_FULL_REFUND'));
});

fch_test('COR-SUPPORT capability fence follows the documented provider operations only', function (): void {
    $storno = InvoiceCorrectionService::STORNO;
    $corrected = InvoiceCorrectionService::CORRECTED;

    // Számlázz: invoice storno + proforma delete are documented; receipt/advance are not routed here.
    assert_same(true, InvoiceCorrectionService::supportsDocumentType('szamlazz', $storno, 'invoice'));
    assert_same(true, InvoiceCorrectionService::supportsDocumentType('szamlazz', $storno, 'proforma'));
    assert_same(false, InvoiceCorrectionService::supportsDocumentType('szamlazz', $storno, 'receipt'));
    assert_same(false, InvoiceCorrectionService::supportsDocumentType('szamlazz', $storno, 'advance'));

    // Billingo: cancel for invoices, DELETE for drafts; proforma has no documented cancel route here.
    assert_same(true, InvoiceCorrectionService::supportsDocumentType('billingo', $storno, 'invoice'));
    assert_same(true, InvoiceCorrectionService::supportsDocumentType('billingo', $storno, 'draft'));
    assert_same(false, InvoiceCorrectionService::supportsDocumentType('billingo', $storno, 'proforma'));

    // Corrective/modification documents are invoice-only for both providers.
    assert_same(true, InvoiceCorrectionService::supportsDocumentType('szamlazz', $corrected, 'invoice'));
    assert_same(true, InvoiceCorrectionService::supportsDocumentType('billingo', $corrected, 'invoice'));
    assert_same(false, InvoiceCorrectionService::supportsDocumentType('szamlazz', $corrected, 'proforma'));
    assert_same(false, InvoiceCorrectionService::supportsDocumentType('billingo', $corrected, 'draft'));

    // Unknown provider can never be corrected.
    assert_same(false, InvoiceCorrectionService::supportsDocumentType('unknown', $storno, 'invoice'));
});

fch_test('COR-SZDBKDEL proforma delete response parses as success / explicit rejection', function (): void {
    $ok = SzamlazzResponseParser::parse(
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<xmlszamladbkdelvalasz xmlns="http://www.szamlazz.hu/xmlszamladbkdelvalasz">'
        . '<sikeres>true</sikeres></xmlszamladbkdelvalasz>'
    );
    assert_same(true, $ok['ok']);
    assert_same('xml_dbkdel', $ok['structure']);

    $fail = SzamlazzResponseParser::parse(
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<xmlszamladbkdelvalasz xmlns="http://www.szamlazz.hu/xmlszamladbkdelvalasz">'
        . '<sikeres>false</sikeres><hibakod>335</hibakod><hibauzenet>nincs ilyen díjbekérő</hibauzenet></xmlszamladbkdelvalasz>'
    );
    assert_same(false, $fail['ok']);
    assert_same('335', $fail['error_code']);
    assert_same(true, $fail['definitive']);
});

fch_test('COR-SZSTORNO storno response carries the storno document number', function (): void {
    $parsed = SzamlazzResponseParser::parse(
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz">'
        . '<sikeres>true</sikeres><szamlaszam>ST-2026-1</szamlaszam><szamlabrutto>3790</szamlabrutto>'
        . '</xmlszamlavalasz>'
    );
    assert_same(true, $parsed['ok']);
    assert_same('ST-2026-1', $parsed['invoice_number']);
    assert_same('3790', $parsed['gross']);
});
