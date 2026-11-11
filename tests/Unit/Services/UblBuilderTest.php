<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Contracts\UblBuilderInterface;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Facades\UblBuilder as UblBuilderFacade;
use BeeCoded\EFacturaSdk\Services\UblBuilder;

describe('UblBuilder', function () {
    it('generates invoice XML from invoice data', function () {
        $ubl = new UblBuilder;

        $invoice = new InvoiceData(
            invoiceNumber: 'INV-001',
            issueDate: '2024-06-15',
            dueDate: '2024-07-15',
            supplier: new PartyData(
                registrationName: 'Supplier SRL',
                companyId: 'RO12345678',
                address: new AddressData(
                    street: 'Main Street 1',
                    city: 'Sector 1',
                    postalZone: '010101',
                    county: 'Bucuresti',
                    countryCode: 'RO',
                ),
                // This fixture is why isVatPayer carries no default. Both parties
                // hold RO VAT numbers and the line charges 19%, but the flag used
                // to default to false: the supplier was filed as "not subject to
                // VAT", so every line came out as VAT category O carrying a 19%
                // rate and a 19.00 VAT amount — a fatal BR-O-05/BR-O-09 pair — and
                // the customer silently lost its VAT identifier (BT-48) too.
                isVatPayer: true,
            ),
            customer: new PartyData(
                registrationName: 'Customer SRL',
                companyId: 'RO87654321',
                address: new AddressData(
                    street: 'Secondary Street 2',
                    city: 'Cluj',
                    postalZone: '400001',
                    county: 'Cluj',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Test Product',
                    quantity: 1,
                    unitPrice: 100.00,
                    taxAmount: 19.00,
                    taxPercent: 19,
                ),
            ],
        );

        $xml = $ubl->generateInvoiceXml($invoice);

        expect($xml)->toBeString();
        expect($xml)->toContain('<?xml');
        expect($xml)->toContain('Invoice');
        expect($xml)->toContain('INV-001');
    });

    it('uses injected invoice builder', function () {
        $mockBuilder = Mockery::mock(InvoiceBuilder::class);
        $mockBuilder->shouldReceive('buildInvoiceXml')
            ->once()
            ->andReturn('<Invoice>mocked</Invoice>');

        $ubl = new UblBuilder($mockBuilder);

        $invoice = new InvoiceData(
            invoiceNumber: 'INV-001',
            issueDate: '2024-06-15',
            supplier: new PartyData(
                registrationName: 'Supplier',
                companyId: 'RO123',
                address: new AddressData(
                    street: 'Street',
                    city: 'City',
                    postalZone: '010101',
                    county: 'County',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            customer: new PartyData(
                registrationName: 'Customer',
                companyId: 'RO456',
                address: new AddressData(
                    street: 'Street',
                    city: 'City',
                    postalZone: '020202',
                    county: 'County',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Product',
                    quantity: 1,
                    unitPrice: 100,
                    taxAmount: 19.00,
                    taxPercent: 19,
                ),
            ],
        );

        $xml = $ubl->generateInvoiceXml($invoice);

        expect($xml)->toBe('<Invoice>mocked</Invoice>');
    });

    it('throws ValidationException for invalid invoice data', function () {
        $ubl = new UblBuilder;

        // Invoice without lines should fail validation
        $invoice = new InvoiceData(
            invoiceNumber: 'INV-001',
            issueDate: '2024-06-15',
            supplier: new PartyData(
                registrationName: 'Supplier',
                companyId: 'RO123',
                address: new AddressData(
                    street: 'Street',
                    city: 'City',
                    postalZone: '010101',
                    county: 'County',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            customer: new PartyData(
                registrationName: 'Customer',
                companyId: 'RO456',
                address: new AddressData(
                    street: 'Street',
                    city: 'City',
                    postalZone: '020202',
                    county: 'County',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            lines: [], // Empty lines should fail
        );

        $ubl->generateInvoiceXml($invoice);
    })->throws(ValidationException::class);

    it('wraps non-validation exceptions', function () {
        $mockBuilder = Mockery::mock(InvoiceBuilder::class);
        $mockBuilder->shouldReceive('buildInvoiceXml')
            ->once()
            ->andThrow(new RuntimeException('XML generation failed'));

        $ubl = new UblBuilder($mockBuilder);

        $invoice = new InvoiceData(
            invoiceNumber: 'INV-001',
            issueDate: '2024-06-15',
            supplier: new PartyData(
                registrationName: 'Supplier',
                companyId: 'RO123',
                address: new AddressData(
                    street: 'Street',
                    city: 'City',
                    postalZone: '010101',
                    county: 'County',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            customer: new PartyData(
                registrationName: 'Customer',
                companyId: 'RO456',
                address: new AddressData(
                    street: 'Street',
                    city: 'City',
                    postalZone: '020202',
                    county: 'County',
                    countryCode: 'RO',
                ),
                isVatPayer: true,
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Product',
                    quantity: 1,
                    unitPrice: 100,
                    taxAmount: 19.00,
                    taxPercent: 19,
                ),
            ],
        );

        $ubl->generateInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Failed to generate invoice XML');
});

it('uses native payable rounding through direct container and custom injected builders with legacy config', function () {
    $party = new PartyData(
        registrationName: 'Supplier SRL', companyId: 'RO12345678',
        address: new AddressData(street: 'Street 1', city: 'Cluj', postalZone: '400001', county: 'Cluj'),
        isVatPayer: true,
    );
    $invoice = new InvoiceData(
        invoiceNumber: 'ROUND-001', issueDate: '2024-06-15', supplier: $party, customer: $party,
        lines: [new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 19, taxPercent: 19)],
        dueDate: '2024-07-15', payableRoundingAmount: -0.01,
    );
    config()->set('efactura-sdk', ['sandbox' => true]);
    $custom = new class extends InvoiceBuilder
    {
        public bool $called = false;

        public function buildInvoiceXml(InvoiceData $input): string
        {
            $this->called = true;

            return parent::buildInvoiceXml($input);
        }
    };
    foreach ([new UblBuilder, app(UblBuilderInterface::class), new UblBuilder($custom)] as $service) {
        $xml = $service->generateInvoiceXml($invoice);
        $dom = new DOMDocument;
        expect($dom->loadXML($xml))->toBeTrue();
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        expect($xpath->evaluate('string(/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount)'))->toBe('119.00')
            ->and($xpath->evaluate('string(/*/cac:LegalMonetaryTotal/cbc:PayableRoundingAmount)'))->toBe('-0.01')
            ->and($xpath->evaluate('string(/*/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe('118.99');
        $invoice->payableRoundingAmount = 0.001;
        expect(fn () => $service->generateInvoiceXml($invoice))->toThrow(ValidationException::class);
        $invoice->payableRoundingAmount = -0.01;
    }
    expect($custom->called)->toBeTrue();
});

it('serializes AE through the live container binding with document and accounting currency zeros', function (InvoiceTypeCode $type, int $sign, string $currency) {
    $party = new PartyData(
        registrationName: 'Supplier SRL', companyId: 'RO12345678',
        address: new AddressData(street: 'Street 1', city: 'Cluj', postalZone: '400001', county: 'Cluj'),
        isVatPayer: true,
    );
    $invoice = InvoiceData::from((new InvoiceData(
        invoiceNumber: 'AE-001', issueDate: '2026-10-21T00:00:00+00:00', supplier: $party, customer: $party,
        lines: [new InvoiceLineData('Reverse charge', $sign, 100, 0, taxCategoryId: TaxCategoryId::ReverseCharge)],
        dueDate: '2026-11-21T00:00:00+00:00', currency: $currency, invoiceTypeCode: $type, taxAmountRon: $currency === 'RON' ? null : 0,
    ))->toArray());
    $service = app(UblBuilderInterface::class);
    $xml = $service->generateInvoiceXml($invoice);
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xmlSign = ($type === InvoiceTypeCode::CreditNote ? -1 : 1) * $sign;
    expect($dom->documentElement->localName)->toBe($type === InvoiceTypeCode::CreditNote ? 'CreditNote' : 'Invoice')
        ->and($xpath->evaluate('count(/*/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cbc:ID="AE"])'))->toBe(1.0)
        ->and($xpath->evaluate('string(/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode)'))->toBe('VATEX-EU-AE')
        ->and($xpath->evaluate('string(/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason)'))->toBe('Taxare inversa')
        ->and($xpath->evaluate('string(/*/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe(number_format($xmlSign * 100, 2, '.', ''))
        ->and($xpath->evaluate('count(/*/cac:TaxTotal/cbc:TaxAmount[text()="0.00"])'))->toBe($currency === 'RON' ? 1.0 : 2.0);
    $invoice->lines[0]->taxAmount = 0.000001;
    expect(fn () => $service->generateInvoiceXml($invoice))->toThrow(ValidationException::class, 'exactly zero raw');
})->with([
    '380' => [InvoiceTypeCode::CommercialInvoice, 1],
    'negative 380' => [InvoiceTypeCode::CommercialInvoice, -1],
    '381' => [InvoiceTypeCode::CreditNote, 1],
])->with(['RON', 'EUR']);

it('validates an invoice without returning XML through the live binding and the facade', function () {
    $party = new PartyData(
        registrationName: 'Supplier SRL', companyId: 'RO12345678',
        address: new AddressData(street: 'Street 1', city: 'Cluj', postalZone: '400001', county: 'Cluj'),
        isVatPayer: true,
    );
    $lines = [new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 19, taxPercent: 19)];
    $valid = new InvoiceData(invoiceNumber: 'VAL-001', issueDate: '2026-11-10', supplier: $party, customer: $party, lines: $lines, dueDate: '2026-12-10');
    $withoutDueDate = new InvoiceData(invoiceNumber: 'VAL-002', issueDate: '2026-11-10', supplier: $party, customer: $party, lines: $lines);

    expect(app(UblBuilderInterface::class)->validateInvoice($valid))->toBeNull()
        ->and(UblBuilderFacade::validateInvoice($valid))->toBeNull()
        ->and(fn () => app(UblBuilderInterface::class)->validateInvoice($withoutDueDate))->toThrow(ValidationException::class, 'BR-CO-25')
        ->and(fn () => UblBuilderFacade::validateInvoice($withoutDueDate))->toThrow(ValidationException::class, 'BR-CO-25');
});
