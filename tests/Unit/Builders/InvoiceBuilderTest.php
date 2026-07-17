<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use Carbon\Carbon;

/**
 * Query generated UBL XML with XPath.
 *
 * Used to assert on the exact element context the EN 16931 schematron rules bind
 * to — string matching cannot distinguish a cbc:Percent inside a line's
 * cac:ClassifiedTaxCategory from one inside the cac:TaxSubtotal breakdown.
 */
function ublXpath(string $xml, string $query): DOMNodeList
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    $result = $xpath->query($query);
    expect($result)->not->toBeFalse();

    return $result;
}

/** Text content of the first node matching $query. */
function ublText(string $xml, string $query): string
{
    $nodes = ublXpath($xml, $query);
    expect($nodes->length)->toBeGreaterThan(0);

    return $nodes->item(0)->textContent;
}

function createTestInvoiceForBuilder(array $lines = [], array $overrides = []): InvoiceData
{
    $supplierAddress = new AddressData(
        street: 'Str. Furnizor 1',
        city: 'Bucuresti',
        postalZone: '010101',
        county: 'Sector 1',
        countryCode: 'RO',
    );

    $customerAddress = new AddressData(
        street: 'Str. Client 1',
        city: 'Cluj-Napoca',
        postalZone: '400001',
        county: 'Cluj',
        countryCode: 'RO',
    );

    $supplier = $overrides['supplier'] ?? new PartyData(
        registrationName: 'Furnizor Test SRL',
        companyId: 'RO12345678',
        address: $supplierAddress,
        registrationNumber: 'J40/1234/2020',
        isVatPayer: true,
    );

    $customer = $overrides['customer'] ?? new PartyData(
        registrationName: 'Client Test SRL',
        companyId: 'RO87654321',
        address: $customerAddress,
        isVatPayer: true,
    );

    $defaultLines = $lines ?: [
        new InvoiceLineData(
            name: 'Servicii consultanta',
            quantity: 10,
            unitPrice: 100.00,
            taxAmount: 190.00,
            taxPercent: 19,
        ),
    ];

    return new InvoiceData(
        invoiceNumber: $overrides['invoiceNumber'] ?? 'INV-2024-001',
        issueDate: $overrides['issueDate'] ?? Carbon::create(2024, 3, 15),
        supplier: $supplier,
        customer: $customer,
        lines: $defaultLines,
        // array_key_exists, not ??, so a test can assert the no-due-date case by
        // passing an explicit null (as it already can for paymentIban).
        dueDate: array_key_exists('dueDate', $overrides) ? $overrides['dueDate'] : Carbon::create(2024, 4, 15),
        currency: $overrides['currency'] ?? 'RON',
        paymentIban: array_key_exists('paymentIban', $overrides) ? $overrides['paymentIban'] : 'RO49AAAA1B31007593840000',
        invoiceTypeCode: $overrides['invoiceTypeCode'] ?? null,
        precedingInvoiceNumber: $overrides['precedingInvoiceNumber'] ?? null,
        taxAmountRon: $overrides['taxAmountRon'] ?? null,
    );
}

describe('buildInvoiceXml', function () {
    it('generates valid UBL 2.1 XML', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>');
        expect($xml)->toContain('Invoice');
        expect($xml)->toContain('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    });

    it('includes required UBL namespaces', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"');
        expect($xml)->toContain('xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"');
    });

    it('includes CIUS-RO customization ID', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1');
    });

    it('includes invoice number and dates', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], [
            'invoiceNumber' => 'INV-TEST-001',
            'issueDate' => Carbon::create(2024, 6, 15),
            'dueDate' => Carbon::create(2024, 7, 15),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>INV-TEST-001</cbc:ID>');
        expect($xml)->toContain('<cbc:IssueDate>2024-06-15</cbc:IssueDate>');
        expect($xml)->toContain('<cbc:DueDate>2024-07-15</cbc:DueDate>');
    });

    it('includes supplier party information', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('AccountingSupplierParty');
        expect($xml)->toContain('Furnizor Test SRL');
        expect($xml)->toContain('RO12345678');
    });

    it('includes customer party information', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('AccountingCustomerParty');
        expect($xml)->toContain('Client Test SRL');
        expect($xml)->toContain('RO87654321');
    });

    it('includes payment means with IBAN', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['paymentIban' => 'RO49AAAA1B31007593840000']);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('PaymentMeans');
        expect($xml)->toContain('RO49AAAA1B31007593840000');
    });

    it('excludes payment means when no IBAN', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['paymentIban' => null]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->not->toContain('PayeeFinancialAccount');
    });

    it('calculates tax totals correctly', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:TaxAmount currencyID="RON">19.00</cbc:TaxAmount>');
    });

    it('groups lines by tax rate', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 3', quantity: 1, unitPrice: 100.00, taxAmount: 9.00, taxPercent: 9),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should have 2 TaxSubtotal elements (19% and 9%)
        expect(substr_count($xml, 'TaxSubtotal'))->toBe(4); // 2 open + 2 close tags
    });

    it('includes monetary totals', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 2, unitPrice: 100.00, taxAmount: 38.00, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('LegalMonetaryTotal');
        expect($xml)->toContain('<cbc:LineExtensionAmount currencyID="RON">200.00</cbc:LineExtensionAmount>');
        expect($xml)->toContain('<cbc:TaxExclusiveAmount currencyID="RON">200.00</cbc:TaxExclusiveAmount>');
        expect($xml)->toContain('<cbc:TaxInclusiveAmount currencyID="RON">238.00</cbc:TaxInclusiveAmount>');
    });

    it('includes invoice lines', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(
                name: 'Test Product',
                quantity: 5,
                unitPrice: 50.00,
                taxAmount: 47.50,
                description: 'Product description',
                unitCode: 'EA',
                taxPercent: 19,
            ),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('InvoiceLine');
        expect($xml)->toContain('Test Product');
        expect($xml)->toContain('Product description');
        expect($xml)->toContain('<cbc:InvoicedQuantity unitCode="EA">5.00</cbc:InvoicedQuantity>');
    });

    it('uses invoice type code 380 by default', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>');
    });

    it('uses specified invoice type code', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceTypeCode' => InvoiceTypeCode::CorrectedInvoice]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoiceTypeCode>384</cbc:InvoiceTypeCode>');
    });

    it('generates CreditNote document for type 381', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceTypeCode' => InvoiceTypeCode::CreditNote]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should be a CreditNote document, not Invoice
        expect($xml)->toContain('<CreditNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2"');
        expect($xml)->toContain('<cbc:CreditNoteTypeCode>381</cbc:CreditNoteTypeCode>');
        expect($xml)->toContain('<cac:CreditNoteLine>');
        expect($xml)->toContain('<cbc:CreditedQuantity');
        expect($xml)->not->toContain('<Invoice');
        expect($xml)->not->toContain('<cbc:InvoiceTypeCode>');
    });
});

describe('validation', function () {
    it('throws exception for missing invoice number', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceNumber' => '']);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Invoice number is required');

    it('throws exception for missing supplier registration name', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(registrationName: '', companyId: 'RO12345678', address: $address, isVatPayer: true);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier registration name is required');

    it('throws exception for missing supplier company ID', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(registrationName: 'Test', companyId: '', address: $address, isVatPayer: true);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier company ID (CIF/CUI) is required');

    it('throws exception for missing street address', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: '', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address, isVatPayer: true);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier street address is required');

    it('throws exception for empty lines', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([]);

        // Empty array is passed but the helper function provides default lines
        // Need to create invoice directly
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $party = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address, isVatPayer: true);

        $emptyLinesInvoice = new InvoiceData(
            invoiceNumber: 'INV-001',
            issueDate: Carbon::now(),
            supplier: $party,
            customer: $party,
            lines: [],
        );

        $builder->buildInvoiceXml($emptyLinesInvoice);
    })->throws(ValidationException::class, 'At least one invoice line is required');

    it('throws exception for line with empty name', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: '', quantity: 1, unitPrice: 100, taxAmount: 0.00),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Item name is required');

    it('throws exception for line with zero quantity', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 0, unitPrice: 100, taxAmount: 0.00),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Quantity cannot be zero');

    it('allows negative quantity for credit notes', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Returned Product', quantity: -2, unitPrice: 100, taxAmount: -38.00, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoicedQuantity unitCode="EA">-2.00</cbc:InvoicedQuantity>');
        expect($xml)->toContain('<cbc:LineExtensionAmount currencyID="RON">-200.00</cbc:LineExtensionAmount>');
    });

    it('throws exception for line with negative price', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: -100, taxAmount: 0.00),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Unit price cannot be negative');

    it('throws exception for line with invalid tax percent', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 150.00, taxPercent: 150),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Tax percent must be between 0 and 100');
});

describe('tax category handling', function () {
    it('uses Standard (S) for VAT payer with non-zero tax', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 19.00, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>S</cbc:ID>');
    });

    it('uses ZeroRated (Z) for VAT payer with zero tax', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>Z</cbc:ID>');
    });

    it('uses NotSubject (O) for non-VAT payer', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: '12345678',
            address: $address,
            isVatPayer: false,
        );
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>O</cbc:ID>');
    });
});

describe('address sanitization', function () {
    it('converts county names to ISO codes', function () {
        $builder = new InvoiceBuilder;
        $supplierAddress = new AddressData(
            street: 'Str. Test',
            city: 'Cluj-Napoca',
            postalZone: '400001',
            county: 'Cluj',
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: 'RO12345678',
            address: $supplierAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:CountrySubentity>RO-CJ</cbc:CountrySubentity>');
    });

    it('handles Bucharest addresses', function () {
        $builder = new InvoiceBuilder;
        $supplierAddress = new AddressData(
            street: 'Str. Test',
            city: 'Bucuresti',
            postalZone: '010101',
            county: 'Bucuresti',
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: 'RO12345678',
            address: $supplierAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:CountrySubentity>RO-B</cbc:CountrySubentity>');
    });

    it('throws exception for Romanian address with unmappable county', function () {
        $builder = new InvoiceBuilder;
        $supplierAddress = new AddressData(
            street: 'Str. Test',
            city: 'Test City',
            postalZone: '010101',
            county: 'UnknownCounty',  // This county doesn't exist in Romania
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: 'RO12345678',
            address: $supplierAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'County "UnknownCounty" could not be mapped to a valid ISO 3166-2:RO code. Romanian addresses require valid county codes (e.g., "RO-AB" for Alba, "RO-B" for Bucharest).');

    it('throws exception for Romanian customer address with unmappable county', function () {
        $builder = new InvoiceBuilder;
        $customerAddress = new AddressData(
            street: 'Str. Test',
            city: 'Test City',
            postalZone: '010101',
            county: 'InvalidCounty',  // This county doesn't exist in Romania
            countryCode: 'RO',
        );
        $customer = new PartyData(
            registrationName: 'Test Client',
            companyId: 'RO87654321',
            address: $customerAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'County "InvalidCounty" could not be mapped to a valid ISO 3166-2:RO code. Romanian addresses require valid county codes (e.g., "RO-AB" for Alba, "RO-B" for Bucharest).');

    it('passes through county for non-Romanian addresses without validation', function () {
        $builder = new InvoiceBuilder;
        $customerAddress = new AddressData(
            street: '123 Main Street',
            city: 'London',
            postalZone: 'SW1A 1AA',
            county: 'Greater London',  // Foreign county - should pass through
            countryCode: 'GB',
        );
        $customer = new PartyData(
            registrationName: 'UK Client Ltd',
            companyId: 'GB123456789',
            address: $customerAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Foreign addresses should have their county passed through as-is
        expect($xml)->toContain('<cbc:CountrySubentity>Greater London</cbc:CountrySubentity>');
    });

    it('throws exception for Romanian address without county (BR-RO-110)', function () {
        $builder = new InvoiceBuilder;
        $supplierAddress = new AddressData(
            street: 'Str. Test',
            city: 'Bucuresti',
            postalZone: '010101',
            county: null,  // No county provided - invalid for RO
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test Supplier',
            companyId: 'RO12345678',
            address: $supplierAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier county is required for Romanian addresses (BR-RO-110)');

    it('allows null county for non-Romanian addresses', function () {
        $builder = new InvoiceBuilder;
        $customerAddress = new AddressData(
            street: '123 Main Street',
            city: 'London',
            postalZone: 'SW1A 1AA',
            county: null,  // No county - OK for non-RO
            countryCode: 'GB',
        );
        $customer = new PartyData(
            registrationName: 'UK Client Ltd',
            companyId: 'GB123456789',
            address: $customerAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should not contain CountrySubentity for GB address with null county
        expect($xml)->toContain('<cbc:IdentificationCode>GB</cbc:IdentificationCode>');
    });
});

describe('tax grouping floating-point handling', function () {
    it('groups lines with slightly different float representations of same tax rate', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            // These represent the same 19% tax but as different float values
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.0),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.00),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should have only 1 TaxSubtotal element (19%) - 2 tags (open + close)
        expect(substr_count($xml, 'TaxSubtotal'))->toBe(2);
    });

    it('groups tax rates that round to same value', function () {
        $builder = new InvoiceBuilder;

        // Create test data with tax percent that would have floating-point issues
        // 19.001 and 19.004 should both round to 19.00 and be grouped together
        $supplierAddress = new AddressData(
            street: 'Str. Furnizor 1',
            city: 'Bucuresti',
            postalZone: '010101',
            county: 'Sector 1',
            countryCode: 'RO',
        );

        $supplier = new PartyData(
            registrationName: 'Furnizor Test SRL',
            companyId: 'RO12345678',
            address: $supplierAddress,
            registrationNumber: 'J40/1234/2020',
            isVatPayer: true,
        );

        $customerAddress = new AddressData(
            street: 'Str. Client 1',
            city: 'Cluj-Napoca',
            postalZone: '400001',
            county: 'Cluj',
            countryCode: 'RO',
        );

        $customer = new PartyData(
            registrationName: 'Client Test SRL',
            companyId: 'RO87654321',
            address: $customerAddress,
            isVatPayer: true,
        );

        $invoice = new InvoiceData(
            invoiceNumber: 'INV-2024-001',
            issueDate: Carbon::create(2024, 3, 15),
            supplier: $supplier,
            customer: $customer,
            lines: [
                new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.001),
                new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.004),
            ],
        );

        $xml = $builder->buildInvoiceXml($invoice);

        // Both should be grouped into single 19% tax subtotal (2 tags = 1 element)
        expect(substr_count($xml, 'TaxSubtotal'))->toBe(2);
    });

    it('correctly separates genuinely different tax rates', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.0),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 9.00, taxPercent: 9.0),
            new InvoiceLineData(name: 'Product 3', quantity: 1, unitPrice: 100.00, taxAmount: 5.00, taxPercent: 5.0),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should have 3 TaxSubtotal elements (19%, 9%, 5%) - 6 tags total
        expect(substr_count($xml, 'TaxSubtotal'))->toBe(6);
    });
});

describe('VAT number normalization', function () {
    it('adds RO prefix to PartyTaxScheme CompanyID for VAT payers', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: '12345678', // Without RO prefix
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        // PartyTaxScheme should have prefixed CompanyID
        expect($xml)->toContain('<cbc:CompanyID>RO12345678</cbc:CompanyID>');
    });

    it('uses raw CUI in PartyLegalEntity CompanyID regardless of input format', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: 'RO12345678', // With RO prefix
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        // PartyTaxScheme has prefixed CompanyID
        // PartyLegalEntity has raw CUI — extract supplier section to verify
        $supplierSection = substr($xml,
            strpos($xml, '<cac:AccountingSupplierParty>'),
            strpos($xml, '</cac:AccountingSupplierParty>') - strpos($xml, '<cac:AccountingSupplierParty>')
        );
        // Count occurrences: RO12345678 should appear once (in PartyTaxScheme)
        // 12345678 (without RO) should appear in PartyLegalEntity
        expect($supplierSection)->toContain('<cbc:CompanyID>RO12345678</cbc:CompanyID>');
        expect(substr_count($supplierSection, '<cbc:CompanyID>RO12345678</cbc:CompanyID>'))->toBe(1);
        // PartyLegalEntity should have the raw CUI
        $legalEntityPos = strpos($supplierSection, '<cac:PartyLegalEntity>');
        $legalEntitySection = substr($supplierSection, $legalEntityPos);
        expect($legalEntitySection)->toContain('<cbc:CompanyID>12345678</cbc:CompanyID>');
    });

    it('keeps existing RO prefix', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        // Should not duplicate RO prefix
        expect($xml)->not->toContain('RORO');
    });

    it('handles missing countryCode by defaulting to RO', function () {
        $builder = new InvoiceBuilder;
        // Create address without explicit countryCode (uses default 'RO')
        $address = new AddressData(
            street: 'Test Street',
            city: 'Test City',
            postalZone: '010101',
            county: 'Cluj',  // Required for RO addresses
        );
        $supplier = new PartyData(
            registrationName: 'Test Company',
            companyId: '12345678',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        // PartyTaxScheme should use default RO prefix
        expect($xml)->toContain('<cbc:CompanyID>RO12345678</cbc:CompanyID>');
    });

    it('handles non-RO country codes correctly', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Test Street',
            city: 'Vienna',
            postalZone: '1010',
            countryCode: 'AT', // Austrian company
        );
        $supplier = new PartyData(
            registrationName: 'Austrian Company',
            companyId: '12345678',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        // PartyTaxScheme should use AT prefix
        expect($xml)->toContain('<cbc:CompanyID>AT12345678</cbc:CompanyID>');
    });

    // Greece is the canonical trap: its VAT identifier prefix is "EL" while its
    // ISO 3166-1 country code is "GR", so a prefix check against the country code
    // never matches an already-prefixed Greek VAT id and prefixes it a second time.
    it('does not double-prefix a Greek VAT id whose prefix differs from its country code', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Test Street',
            city: 'Athens',
            postalZone: '10431',
            countryCode: 'GR',
        );
        $customer = new PartyData(
            registrationName: 'Greek Company',
            companyId: 'EL123456789',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        $companyId = ublText($xml, '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID');
        expect($companyId)->toBe('EL123456789');
        expect($xml)->not->toContain('GREL');
    });

    // The mirror-image trap: the caller prefixes the id with Greece's ISO 3166-1 country code
    // ("GR") instead of its VAT prefix ("EL"). "GR" is deliberately absent from
    // VAT_COUNTRY_PREFIXES — no VAT id legitimately carries it — so the prefix check does not
    // recognise it and prefixes again, producing "ELGR123456789". v2 returned it unchanged.
    // The intent is unambiguous (a bare national VAT number never opens with two letters), so
    // it is corrected to the prefix Greece actually files under rather than mangled.
    it('corrects a Greek VAT id prefixed with the country code instead of the VAT prefix', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Test Street',
            city: 'Athens',
            postalZone: '10431',
            countryCode: 'GR',
        );
        $customer = new PartyData(
            registrationName: 'Greek Company',
            companyId: 'GR123456789',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        $companyId = ublText($xml, '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID');
        expect($companyId)->toBe('EL123456789');
        expect($xml)->not->toContain('ELGR');
    });

    // The same correction applies when the Greek registration sits on a foreign address: an
    // explicit prefix from the caller is better evidence of the issuing state than the address.
    it('corrects a country-code-prefixed Greek VAT id on a non-Greek address', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Str. Test 1',
            city: 'Cluj-Napoca',
            postalZone: '400001',
            county: 'Cluj',
            countryCode: 'RO',
        );
        $customer = new PartyData(
            registrationName: 'Greek Company',
            companyId: 'GR123456789',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID'))->toBe('EL123456789');
        expect($xml)->not->toContain('ROGR');
    });

    it('does not double-prefix a Northern Ireland VAT id (XI) on a GB address', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Test Street',
            city: 'Belfast',
            postalZone: 'BT1 1AA',
            countryCode: 'GB',
        );
        $customer = new PartyData(
            registrationName: 'NI Company',
            companyId: 'XI123456789',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        $companyId = ublText($xml, '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID');
        expect($companyId)->toBe('XI123456789');
        expect($xml)->not->toContain('GBXI');
    });

    // A Romanian CUI is purely numeric and MUST still receive its RO prefix.
    it('still prefixes a bare Romanian CUI', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID'))
            ->toBe('RO12345678');
    });

    // Recognising an existing prefix must not hinge on the character after it
    // being alphanumeric: a separator is malformed input but the id is plainly
    // already prefixed, and prefixing it again is strictly worse than leaving it.
    it('does not re-prefix an RO id that carries a separator', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Test Company',
            companyId: 'RO 12345678',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->not->toContain('RORO');
    });
});

describe('non-VAT payer CIUS-RO compliance', function () {
    it('omits PartyTaxScheme for non-VAT payer supplier', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Non-VAT Company',
            companyId: '12345678',
            address: $address,
            isVatPayer: false,
        );
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        // PartyTaxScheme should not be present for supplier
        expect($xml)->toContain('<cac:AccountingSupplierParty>');
        // Extract supplier section and check no PartyTaxScheme
        $supplierSection = substr($xml,
            strpos($xml, '<cac:AccountingSupplierParty>'),
            strpos($xml, '</cac:AccountingSupplierParty>') - strpos($xml, '<cac:AccountingSupplierParty>')
        );
        expect($supplierSection)->not->toContain('<cac:PartyTaxScheme>');
    });

    it('omits PartyTaxScheme for non-VAT payer customer', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $customer = new PartyData(
            registrationName: 'Non-VAT Customer',
            companyId: '87654321',
            address: $address,
            isVatPayer: false,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Extract customer section and check no PartyTaxScheme
        $customerSection = substr($xml,
            strpos($xml, '<cac:AccountingCustomerParty>'),
            strpos($xml, '</cac:AccountingCustomerParty>') - strpos($xml, '<cac:AccountingCustomerParty>')
        );
        expect($customerSection)->not->toContain('<cac:PartyTaxScheme>');
    });

    it('includes PartyTaxScheme for VAT payer', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cac:PartyTaxScheme>');
    });

    it('emits VATEX-EU-O exemption reason code for non-VAT payer', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Non-VAT Company',
            companyId: '12345678',
            address: $address,
            isVatPayer: false,
        );
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:TaxExemptionReasonCode>VATEX-EU-O</cbc:TaxExemptionReasonCode>');
    });

    it('uses raw CUI in PartyLegalEntity for non-VAT payer', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: 'Non-VAT Company',
            companyId: '12345678',
            address: $address,
            isVatPayer: false,
        );
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        $supplierSection = substr($xml,
            strpos($xml, '<cac:AccountingSupplierParty>'),
            strpos($xml, '</cac:AccountingSupplierParty>') - strpos($xml, '<cac:AccountingSupplierParty>')
        );
        $legalEntityPos = strpos($supplierSection, '<cac:PartyLegalEntity>');
        $legalEntitySection = substr($supplierSection, $legalEntityPos);
        expect($legalEntitySection)->toContain('<cbc:CompanyID>12345678</cbc:CompanyID>');
        expect($legalEntitySection)->toContain('<cbc:RegistrationName>Non-VAT Company</cbc:RegistrationName>');
    });
});

describe('BR-O non-VAT payer schematron compliance', function () {
    // A micro-enterprise / non-VAT-payer supplier. Every line it issues carries
    // VAT category "O" (Not subject to VAT), which triggers the BR-O-* rule set.
    $nonVatSupplier = fn (): PartyData => new PartyData(
        registrationName: 'Micro SRL',
        companyId: '12345678',
        address: new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj'),
        isVatPayer: false,
    );

    it('omits the invoiced item VAT rate on category O lines (BR-O-05)', function () use ($nonVatSupplier) {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $nonVatSupplier()]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Mirrors the schematron assert: context cac:ClassifiedTaxCategory[cbc:ID='O'], test not(cbc:Percent)
        expect(ublXpath($xml, '//cac:Item/cac:ClassifiedTaxCategory[cbc:ID="O"]/cbc:Percent')->length)->toBe(0);
        // The category itself must still be present.
        expect(ublXpath($xml, '//cac:Item/cac:ClassifiedTaxCategory[cbc:ID="O"]')->length)->toBe(1);
    });

    it('omits the buyer VAT identifier when the supplier is not a VAT payer (BR-O-02)', function () use ($nonVatSupplier) {
        $builder = new InvoiceBuilder;
        // Default customer is VAT-registered (RO87654321) — the common real-world case.
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $nonVatSupplier()]);

        $xml = $builder->buildInvoiceXml($invoice);

        // BT-48 (buyer VAT identifier) is prohibited when any line is category O.
        expect(ublXpath($xml, '//cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme')->length)->toBe(0);
        // BT-31 (seller VAT identifier) likewise.
        expect(ublXpath($xml, '//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme')->length)->toBe(0);
        // BT-47: the buyer's legal registration identifier is NOT prohibited and must remain.
        expect(ublText($xml, '//cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID'))->toBe('87654321');
    });

    it('rejects a non-VAT-payer supplier charging VAT (BR-O-09)', function () use ($nonVatSupplier) {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 19.00, taxPercent: 19),
        ], ['supplier' => $nonVatSupplier()]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: A supplier that is not registered for VAT cannot charge VAT (BR-O-09)');

    it('keeps the invoiced item VAT rate for VAT-payer suppliers', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder(); // VAT-payer supplier, 19%

        $xml = $builder->buildInvoiceXml($invoice);

        // Guard against over-fixing: BR-O-05 only binds to category O.
        expect(ublText($xml, '//cac:Item/cac:ClassifiedTaxCategory[cbc:ID="S"]/cbc:Percent'))->toBe('19.00');
    });

    it('retains the VAT breakdown rate for category O', function () use ($nonVatSupplier) {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ], ['supplier' => $nonVatSupplier()]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Guard against over-fixing: no BR-O rule prohibits cbc:Percent in the
        // cac:TaxSubtotal/cac:TaxCategory breakdown — only on the line (BR-O-05).
        expect(ublText($xml, '//cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory[cbc:ID="O"]/cbc:Percent'))->toBe('0.00');
        // BR-O-09: the VAT category tax amount must be zero.
        expect(ublText($xml, '//cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cbc:ID="O"]/cbc:TaxAmount'))->toBe('0.00');
        // BR-O-10: exemption reason code must be present.
        expect(ublText($xml, '//cac:TaxCategory[cbc:ID="O"]/cbc:TaxExemptionReasonCode'))->toBe('VATEX-EU-O');
    });
});

describe('BR-Z zero-rated schematron compliance', function () {
    // The BR-O-09 guard fires only when the supplier is NOT a VAT payer. A VAT-PAYER supplier
    // declaring a 0% rate lands its lines in VAT category "Z", which carries the very same
    // requirement: BR-Z-09 asserts xs:decimal(../cbc:TaxAmount) = 0 — exact, no tolerance —
    // so a non-zero tax amount on a zero-rated line is fatal. BR-CO-17 fails alongside it
    // (19.00 against a 100.00 x 0% expectation of 0.00, drift far beyond its +/-1 tolerance).
    //
    // Before this guard existed the builder emitted TaxCategory/ID=Z, Percent=0.00 and
    // TaxSubtotal/TaxAmount=19.00 with no local error at all — two fatal ANAF rejections,
    // exactly what the BR-O-09 guard exists to prevent for the mirror-image case.
    it('rejects a VAT-payer supplier charging tax on a zero-rated line (BR-Z-09)', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 19.00, taxPercent: 0),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: A zero-rated line cannot carry a VAT amount (BR-Z-09)');

    // The guard mirrors getTaxCategory()'s epsilon, so it must bind exactly when category Z is
    // emitted — a rate below the 0.01 epsilon is filed as Z and is caught too.
    it('rejects a sub-epsilon rate carrying a VAT amount', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.01, taxPercent: 0.001),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class);

    // Guard against over-fixing: a genuine zero-rated line must still build.
    it('accepts a zero-rated line declaring zero tax', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.00, taxPercent: 0),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:TaxSubtotal[cac:TaxCategory/cbc:ID="Z"]/cbc:TaxAmount'))->toBe('0.00');
        expect(ublText($xml, '//cac:TaxSubtotal/cac:TaxCategory[cbc:ID="Z"]/cbc:Percent'))->toBe('0.00');
    });

    // Guard against over-fixing: the rounding tolerance is the emitted figure's. A sub-cent
    // residue that rounds to 0.00 is what BR-Z-09 actually sees, so it must not be rejected.
    it('accepts a sub-cent tax residue that files as zero', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 0.004, taxPercent: 0),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:TaxSubtotal[cac:TaxCategory/cbc:ID="Z"]/cbc:TaxAmount'))->toBe('0.00');
    });

    // Guard against over-fixing: a standard-rated line is untouched by BR-Z-09.
    it('leaves a standard-rated line alone', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxAmount: 19.00, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:TaxSubtotal[cac:TaxCategory/cbc:ID="S"]/cbc:TaxAmount'))->toBe('19.00');
    });
});

describe('BT-129/BT-146 quantity and unit price precision', function () {
    it('keeps fractional quantities so quantity x price reconciles with the line amount', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Cheese', quantity: 1.375, unitPrice: 10.00, taxAmount: 2.61, unitCode: 'KGM', taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoicedQuantity unitCode="KGM">1.375</cbc:InvoicedQuantity>');
        expect($xml)->toContain('<cbc:PriceAmount currencyID="RON">10.00</cbc:PriceAmount>');
        expect($xml)->toContain('<cbc:LineExtensionAmount currencyID="RON">13.75</cbc:LineExtensionAmount>');

        // The legal document must be internally consistent: BT-129 x BT-146 = BT-131.
        $quantity = (float) ublText($xml, '//cac:InvoiceLine/cbc:InvoicedQuantity');
        $price = (float) ublText($xml, '//cac:InvoiceLine/cac:Price/cbc:PriceAmount');
        $lineAmount = (float) ublText($xml, '//cac:InvoiceLine/cbc:LineExtensionAmount');
        expect(round($quantity * $price, 2))->toBe($lineAmount);
    });

    it('preserves sub-cent unit prices (BT-146)', function () {
        $builder = new InvoiceBuilder;
        // 10 000 SMS at 0.0075 RON each = 75.00 RON. Rounding the price to 0.01 would file 100.00.
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'SMS', quantity: 10000, unitPrice: 0.0075, taxAmount: 14.25, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:PriceAmount currencyID="RON">0.0075</cbc:PriceAmount>');
        expect($xml)->toContain('<cbc:InvoicedQuantity unitCode="EA">10000.00</cbc:InvoicedQuantity>');
        expect($xml)->toContain('<cbc:LineExtensionAmount currencyID="RON">75.00</cbc:LineExtensionAmount>');

        $quantity = (float) ublText($xml, '//cac:InvoiceLine/cbc:InvoicedQuantity');
        $price = (float) ublText($xml, '//cac:InvoiceLine/cac:Price/cbc:PriceAmount');
        $lineAmount = (float) ublText($xml, '//cac:InvoiceLine/cbc:LineExtensionAmount');
        expect(round($quantity * $price, 2))->toBe($lineAmount);
    });

    it('preserves fractional credited quantities on credit notes', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Returned cheese', quantity: 1.375, unitPrice: 10.00, taxAmount: 2.61, unitCode: 'KGM', taxPercent: 19),
        ], ['invoiceTypeCode' => InvoiceTypeCode::CreditNote]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:CreditedQuantity unitCode="KGM">-1.375</cbc:CreditedQuantity>');
        expect($xml)->toContain('<cbc:LineExtensionAmount currencyID="RON">-13.75</cbc:LineExtensionAmount>');
    });

    it('caps precision at 6 decimals and never emits scientific notation', function () {
        $builder = new InvoiceBuilder;
        // (string) 0.0000075 would render as "7.5E-6", which is not a valid xsd:decimal.
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Bulk', quantity: 1 / 3, unitPrice: 0.0000075, taxAmount: 0.00, taxPercent: 0),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoicedQuantity unitCode="EA">0.333333</cbc:InvoicedQuantity>');
        expect($xml)->toContain('<cbc:PriceAmount currencyID="RON">0.000008</cbc:PriceAmount>');
    });

    it('keeps monetary amount fields at 2 decimals (BR-DEC)', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Cheese', quantity: 1.375, unitPrice: 10.00, taxAmount: 2.61, unitCode: 'KGM', taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        // BR-DEC-* caps AMOUNT fields at 2 decimals — the quantity/price fix must not leak into them.
        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:LineExtensionAmount'))->toBe('13.75');
        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'))->toBe('13.75');
        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'))->toBe('16.36');
        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('16.36');
        expect(ublText($xml, '//cac:TaxTotal/cbc:TaxAmount'))->toBe('2.61');
    });
});

describe('BR-RO-010 invoice number validation', function () {
    it('throws exception for invoice number without digits', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceNumber' => 'ABC-DEF']);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Invoice number must contain at least one numeric character (BR-RO-010)');

    it('accepts invoice number with digits', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceNumber' => 'INV-2024-001']);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>INV-2024-001</cbc:ID>');
    });

    it('accepts invoice number that is only digits', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceNumber' => '12345']);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>12345</cbc:ID>');
    });
});

describe('BR-RO-100/101 Bucharest sector handling', function () {
    it('outputs SECTOR code for Bucharest addresses', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Str. Test 1',
            city: 'Sector 3',
            postalZone: '030001',
            county: 'Bucuresti',
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test SRL',
            companyId: 'RO12345678',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:CityName>SECTOR3</cbc:CityName>');
        expect($xml)->toContain('<cbc:CountrySubentity>RO-B</cbc:CountrySubentity>');
    });

    it('extracts sector from county field', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Str. Test 1',
            city: 'Bucuresti',
            postalZone: '020001',
            county: 'Sector 2',
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test SRL',
            companyId: 'RO12345678',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:CityName>SECTOR2</cbc:CityName>');
    });
});

describe('BR-RO-L string length validations', function () {
    it('throws exception for invoice number over 200 chars', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['invoiceNumber' => str_repeat('1', 201)]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Invoice number must not exceed 200 characters (BR-RO-L200)');

    it('throws exception for registration name over 200 chars', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(
            registrationName: str_repeat('A', 201),
            companyId: 'RO12345678',
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier registration name must not exceed 200 characters (BR-RO-L200)');

    it('throws exception for street over 150 chars', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: str_repeat('A', 151),
            city: 'Test',
            postalZone: '010101',
        );
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address, isVatPayer: true);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier street address must not exceed 150 characters (BR-RO-L150)');

    it('throws exception for city over 50 chars', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Test Street',
            city: str_repeat('A', 51),
            postalZone: '010101',
        );
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address, isVatPayer: true);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier city must not exceed 50 characters (BR-RO-L050)');

    it('throws exception for postal code over 20 chars', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(
            street: 'Test Street',
            city: 'Test City',
            postalZone: str_repeat('1', 21),
            county: 'Cluj',
        );
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address, isVatPayer: true);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier postal code must not exceed 20 characters (BR-RO-L020)');

    it('allows omitting postal code for all parties', function () {
        $builder = new InvoiceBuilder;
        $supplierAddress = new AddressData(
            street: 'Str. Test',
            city: 'Cluj-Napoca',
            county: 'Cluj',
            countryCode: 'RO',
        );
        $supplier = new PartyData(
            registrationName: 'Test SRL',
            companyId: 'RO12345678',
            address: $supplierAddress,
            isVatPayer: true,
        );
        $customerAddress = new AddressData(
            street: 'Str. Client',
            city: 'Bucuresti',
            county: 'Sector 1',
            countryCode: 'RO',
        );
        $customer = new PartyData(
            registrationName: 'Client SRL',
            companyId: 'RO87654321',
            address: $customerAddress,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], [
            'supplier' => $supplier,
            'customer' => $customer,
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('Test SRL');
        expect($xml)->toContain('Client SRL');
        expect($xml)->not->toContain('PostalZone');
    });

    it('throws exception for item name over 100 chars', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: str_repeat('A', 101), quantity: 1, unitPrice: 100, taxAmount: 0.00),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Item name must not exceed 100 characters (BR-RO-L100)');

    it('throws exception for item description over 200 chars', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(
                name: 'Test Product',
                quantity: 1,
                unitPrice: 100,
                taxAmount: 0.00,
                description: str_repeat('A', 201),
            ),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Item description must not exceed 200 characters (BR-RO-L200)');
});

describe('BillingReference for credit notes', function () {
    it('includes BillingReference for credit notes with preceding invoice number', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'precedingInvoiceNumber' => 'LD-000123',
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cac:BillingReference>');
        expect($xml)->toContain('<cac:InvoiceDocumentReference>');
        expect($xml)->toContain('<cbc:ID>LD-000123</cbc:ID>');
    });

    it('omits BillingReference when preceding invoice number is null', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->not->toContain('<cac:BillingReference>');
    });

    it('places BillingReference before AccountingSupplierParty', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'precedingInvoiceNumber' => 'LD-000123',
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        $billingPos = strpos($xml, 'BillingReference');
        $supplierPos = strpos($xml, 'AccountingSupplierParty');
        expect($billingPos)->toBeLessThan($supplierPos);
    });

    it('throws exception for preceding invoice number over 200 chars', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], [
            'precedingInvoiceNumber' => str_repeat('A', 201),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'The preceding invoice number must not exceed 200 characters (BR-RO-L200)');
});

describe('BR-RO-030 multi-currency support', function () {
    it('omits TaxCurrencyCode for RON invoices', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'RON']);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>');
        expect($xml)->not->toContain('<cbc:TaxCurrencyCode>');
    });

    it('adds TaxCurrencyCode RON for EUR invoices', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR', 'taxAmountRon' => 944.30]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>');
        expect($xml)->toContain('<cbc:TaxCurrencyCode>RON</cbc:TaxCurrencyCode>');
    });

    it('adds second TaxTotal in RON for non-RON invoices', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'USD', 'taxAmountRon' => 945.00]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should have two TaxTotal elements - one in USD and one in RON
        expect(substr_count($xml, '<cac:TaxTotal>'))->toBe(2);
        expect($xml)->toContain('currencyID="USD"');
        expect($xml)->toContain('currencyID="RON"');
    });
});

describe('BT-111 VAT total in the RON accounting currency', function () {
    // BR-RO-030 forces BT-6 (TaxCurrencyCode) to RON whenever BT-5 is not RON;
    // BR-53 then forces a TaxTotal/TaxAmount at @currencyID='RON' to exist. ANAF
    // cannot check the conversion, so a wrong figure here is ACCEPTED and filed.
    // The document-currency amount must therefore never be reused as the RON one.
    it('files the supplied RON amount, not the document-currency amount', function () {
        // 190.00 EUR of VAT at ~4.97 RON/EUR.
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR', 'taxAmountRon' => 944.30]);

        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, "/*/cac:TaxTotal/cbc:TaxAmount[@currencyID='EUR']"))->toBe('190.00');
        expect(ublText($xml, "/*/cac:TaxTotal/cbc:TaxAmount[@currencyID='RON']"))->toBe('944.30');
    });

    it('throws when a non-RON invoice omits the RON tax amount', function () {
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR']);

        (new InvoiceBuilder)->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'taxAmountRon');

    // BR-CO-15 asserts count(TaxTotal/TaxAmount[@currencyID=BT-5]) eq 1, so a RON
    // invoice may carry only ONE TaxTotal. Supplying BT-111 there cannot be
    // honoured, and silently ignoring a statutory figure is what caused this bug.
    it('throws when a RON invoice supplies a redundant RON tax amount', function () {
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'RON', 'taxAmountRon' => 190.00]);

        (new InvoiceBuilder)->buildInvoiceXml($invoice);
    })->throws(ValidationException::class);

    it('emits exactly one TaxTotal for a RON invoice (BR-CO-15)', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], ['currency' => 'RON']));

        expect(ublXpath($xml, '/*/cac:TaxTotal')->length)->toBe(1);
    });

    // The RON TaxTotal must carry cbc:TaxAmount ONLY. BR-CO-14's `or
    // not(cac:TaxSubtotal)` escape is what permits a bare TaxTotal; adding a
    // breakdown would double the document-wide category counts that BR-Z-01 /
    // BR-O-01 assert as `= 1`, and BR-S-08 would compare a RON TaxableAmount
    // against EUR line sums.
    it('emits the RON TaxTotal without any TaxSubtotal breakdown', function () {
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR', 'taxAmountRon' => 944.30]);

        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        $ronTaxTotal = ublXpath($xml, "/*/cac:TaxTotal[cbc:TaxAmount/@currencyID='RON']");
        expect($ronTaxTotal->length)->toBe(1);

        $children = [];
        foreach ($ronTaxTotal->item(0)->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node->localName;
            }
        }
        expect($children)->toBe(['TaxAmount']);
    });

    // BR-DEC-RO-15 (message tag [BR-RO-Z2]): BT-111 allows at most 2 decimals.
    it('rounds the RON tax amount to 2 decimals (BR-DEC-RO-15)', function () {
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR', 'taxAmountRon' => 944.3049]);

        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, "/*/cac:TaxTotal/cbc:TaxAmount[@currencyID='RON']"))->toBe('944.30');
    });

    // A credit note sign-flips its line tax amounts, so BT-111 is supplied in the
    // same positive sense as the lines and flipped alongside them. Filing +945 RON
    // against -190 EUR would contradict the document.
    it('negates the RON tax amount for a credit note, matching the document currency', function () {
        $invoice = createTestInvoiceForBuilder([], [
            'currency' => 'EUR',
            'taxAmountRon' => 944.30,
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
        ]);

        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, "/*/cac:TaxTotal/cbc:TaxAmount[@currencyID='EUR']"))->toBe('-190.00');
        expect(ublText($xml, "/*/cac:TaxTotal/cbc:TaxAmount[@currencyID='RON']"))->toBe('-944.30');
    });

    it('accepts a zero RON tax amount for a zero-rated non-RON invoice', function () {
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Export', quantity: 1, unitPrice: 100.00, taxAmount: 0.00, taxPercent: 0),
        ], ['currency' => 'EUR', 'taxAmountRon' => 0.0]);

        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, "/*/cac:TaxTotal/cbc:TaxAmount[@currencyID='RON']"))->toBe('0.00');
    });

    it('throws when the RON tax amount contradicts the sign of the document VAT', function () {
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR', 'taxAmountRon' => -944.30]);

        (new InvoiceBuilder)->buildInvoiceXml($invoice);
    })->throws(ValidationException::class);
});

describe('BT-9 payment due date on credit notes', function () {
    // UBL 2.1 CreditNoteType has no cbc:DueDate (that slot holds cbc:TaxPointDate),
    // so EN 16931 binds BT-9 on a credit note to cac:PaymentMeans/cbc:PaymentDueDate.
    // UBL-CR-412 (`not(cac:PaymentMeans/cbc:PaymentDueDate) or ../cn:CreditNote`)
    // exists precisely to carve this out for credit notes only.
    it('emits the due date as PaymentMeans/PaymentDueDate', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'dueDate' => Carbon::create(2024, 4, 15),
        ]));

        expect(ublText($xml, '/*/cac:PaymentMeans/cbc:PaymentDueDate'))->toBe('2024-04-15');
    });

    it('never emits a root cbc:DueDate on a credit note (not in the UBL schema)', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'dueDate' => Carbon::create(2024, 4, 15),
        ]));

        expect(ublXpath($xml, '/*/cbc:DueDate')->length)->toBe(0);
    });

    // BR-61: a PaymentMeansCode of 30/58 (credit transfer) REQUIRES BT-84, the
    // payee account id. With no IBAN to put there, code 30 would fail fatally, so
    // a due-date-only PaymentMeans must use code 1 ("Instrument not defined"),
    // which satisfies BR-61 vacuously.
    it('uses PaymentMeansCode 1 and no account when a credit note has a due date but no IBAN', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'dueDate' => Carbon::create(2024, 4, 15),
            'paymentIban' => null,
        ]));

        expect(ublText($xml, '/*/cac:PaymentMeans/cbc:PaymentMeansCode'))->toBe('1');
        expect(ublText($xml, '/*/cac:PaymentMeans/cbc:PaymentDueDate'))->toBe('2024-04-15');
        expect(ublXpath($xml, '/*/cac:PaymentMeans/cac:PayeeFinancialAccount')->length)->toBe(0);
    });

    it('keeps PaymentMeansCode 30 with the account when a credit note has both', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'dueDate' => Carbon::create(2024, 4, 15),
        ]));

        expect(ublText($xml, '/*/cac:PaymentMeans/cbc:PaymentMeansCode'))->toBe('30');
        expect(ublText($xml, '/*/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'))
            ->toBe('RO49AAAA1B31007593840000');
    });

    // UBL PaymentMeansType is a sequence: PaymentMeansCode, PaymentDueDate, ...,
    // PayeeFinancialAccount. Wrong order is a schema rejection.
    it('orders PaymentMeans children per the UBL 2.1 sequence', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'dueDate' => Carbon::create(2024, 4, 15),
        ]));

        $children = [];
        foreach (ublXpath($xml, '/*/cac:PaymentMeans/*') as $node) {
            $children[] = $node->localName;
        }

        expect($children)->toBe(['PaymentMeansCode', 'PaymentDueDate', 'PayeeFinancialAccount']);
    });

    it('omits PaymentMeans entirely when a credit note has neither due date nor IBAN', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'dueDate' => null,
            'paymentIban' => null,
        ]));

        expect(ublXpath($xml, '/*/cac:PaymentMeans')->length)->toBe(0);
    });

    // UBL-CR-412 warns that a UBL *Invoice* should not carry
    // PaymentMeans/PaymentDueDate — an invoice states BT-9 as root cbc:DueDate.
    it('keeps using root cbc:DueDate on an invoice and never PaymentMeans/PaymentDueDate', function () {
        $xml = (new InvoiceBuilder)->buildInvoiceXml(createTestInvoiceForBuilder([], [
            'dueDate' => Carbon::create(2024, 4, 15),
        ]));

        expect(ublText($xml, '/*/cbc:DueDate'))->toBe('2024-04-15');
        expect(ublXpath($xml, '/*/cac:PaymentMeans/cbc:PaymentDueDate')->length)->toBe(0);
    });
});

describe('InvoiceData monetary helpers agree with the filed XML', function () {
    // A wrapper that records getTotalIncludingVat() as the receivable must not
    // disagree with the legal document by a bani. These lines are built so that
    // BOTH rounding boundaries bite at once: two sub-cent net amounts, and two
    // sub-cent VAT residues sitting in DIFFERENT tax-rate groups.
    $subCent = fn () => [
        new InvoiceLineData(name: 'Product 1', quantity: 0.5, unitPrice: 0.01, taxAmount: 0.005, taxPercent: 19),
        new InvoiceLineData(name: 'Product 2', quantity: 0.5, unitPrice: 0.01, taxAmount: 0.005, taxPercent: 5),
    ];

    it('getTotalExcludingVat matches LegalMonetaryTotal/TaxExclusiveAmount', function () use ($subCent) {
        $invoice = createTestInvoiceForBuilder($subCent());
        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'))
            ->toBe(number_format($invoice->getTotalExcludingVat(), 2, '.', ''));
    });

    it('getTotalExcludingVat matches LegalMonetaryTotal/LineExtensionAmount', function () use ($subCent) {
        $invoice = createTestInvoiceForBuilder($subCent());
        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:LineExtensionAmount'))
            ->toBe(number_format($invoice->getTotalExcludingVat(), 2, '.', ''));
    });

    it('getTotalVat matches the document-level TaxTotal/TaxAmount (BT-110)', function () use ($subCent) {
        $invoice = createTestInvoiceForBuilder($subCent());
        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, '/*/cac:TaxTotal/cbc:TaxAmount'))
            ->toBe(number_format($invoice->getTotalVat(), 2, '.', ''));
    });

    it('getTotalIncludingVat matches LegalMonetaryTotal/PayableAmount', function () use ($subCent) {
        $invoice = createTestInvoiceForBuilder($subCent());
        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:LegalMonetaryTotal/cbc:PayableAmount'))
            ->toBe(number_format($invoice->getTotalIncludingVat(), 2, '.', ''));
    });

    it('getTotalVat matches BT-110 when all lines share one tax rate', function () {
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 1.00, taxAmount: 0.005, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 1.00, taxAmount: 0.005, taxPercent: 19),
        ]);
        $xml = (new InvoiceBuilder)->buildInvoiceXml($invoice);

        expect(ublText($xml, '/*/cac:TaxTotal/cbc:TaxAmount'))
            ->toBe(number_format($invoice->getTotalVat(), 2, '.', ''));
    });
});

/**
 * France is the only member state whose VAT key may be two letters (HMRC:
 * "XX123456789"), which makes a BARE French id shaped exactly like a Greek id
 * carrying its country code. These pin what the builder does about it: the
 * prefixed form is correct and safe, the bare form is knowingly ambiguous.
 */
describe('French VAT ids, whose key may be two letters', function () {
    it('passes a prefixed French VAT id through untouched, whatever its key', function () {
        $builder = new InvoiceBuilder;
        $customer = new PartyData(
            registrationName: 'Societe Test',
            companyId: 'FRGR123456789',
            address: new AddressData(
                street: 'Rue Test 1',
                city: 'Paris',
                postalZone: '75001',
                countryCode: 'FR',
            ),
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        // The "GR" here is the French key, not Greece. A prefixed id is
        // unambiguous, so it must survive intact.
        expect(ublText($xml, '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID'))
            ->toBe('FRGR123456789');
        expect($xml)->not->toContain('EL123456789');
    });

    /**
     * KNOWN LIMITATION, pinned deliberately rather than left as folklore.
     *
     * 'GR123456789' + countryCode 'FR' is byte-for-byte identical to a Greek id
     * written with its country code, which the builder corrects to 'EL...' — a
     * behaviour another test pins on purpose for foreign VAT registrations.
     * Nothing here can tell the two apart, and gating the correction on the
     * country code would not fix it: it would just invert which case breaks,
     * sacrificing the commoner one. The fix is the caller contract in
     * normalizeVatNumber()'s docblock: pass the prefix.
     */
    it('misreads a BARE French VAT id whose key looks like a country code', function () {
        $builder = new InvoiceBuilder;
        $customer = new PartyData(
            registrationName: 'Societe Test',
            companyId: 'GR123456789',
            address: new AddressData(
                street: 'Rue Test 1',
                city: 'Paris',
                postalZone: '75001',
                countryCode: 'FR',
            ),
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['customer' => $customer]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect(ublText($xml, '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID'))
            ->toBe('EL123456789');
    });
});
