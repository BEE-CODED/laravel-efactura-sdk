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
            taxPercent: 19,
        ),
    ];

    return new InvoiceData(
        invoiceNumber: $overrides['invoiceNumber'] ?? 'INV-2024-001',
        issueDate: $overrides['issueDate'] ?? Carbon::create(2024, 3, 15),
        supplier: $supplier,
        customer: $customer,
        lines: $defaultLines,
        dueDate: $overrides['dueDate'] ?? Carbon::create(2024, 4, 15),
        currency: $overrides['currency'] ?? 'RON',
        paymentIban: array_key_exists('paymentIban', $overrides) ? $overrides['paymentIban'] : 'RO49AAAA1B31007593840000',
        invoiceTypeCode: $overrides['invoiceTypeCode'] ?? null,
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
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:TaxAmount currencyID="RON">19.00</cbc:TaxAmount>');
    });

    it('groups lines by tax rate', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 3', quantity: 1, unitPrice: 100.00, taxPercent: 9),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should have 2 TaxSubtotal elements (19% and 9%)
        expect(substr_count($xml, 'TaxSubtotal'))->toBe(4); // 2 open + 2 close tags
    });

    it('includes monetary totals', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 2, unitPrice: 100.00, taxPercent: 19),
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
        $supplier = new PartyData(registrationName: '', companyId: 'RO12345678', address: $address);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier registration name is required');

    it('throws exception for missing supplier company ID', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(registrationName: 'Test', companyId: '', address: $address);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier company ID (CIF/CUI) is required');

    it('throws exception for missing street address', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: '', city: 'Test', postalZone: '010101', county: 'Cluj');
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier street address is required');

    it('throws exception for empty lines', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([]);

        // Empty array is passed but the helper function provides default lines
        // Need to create invoice directly
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101', county: 'Cluj');
        $party = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address);

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
            new InvoiceLineData(name: '', quantity: 1, unitPrice: 100),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Item name is required');

    it('throws exception for line with zero quantity', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 0, unitPrice: 100),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Quantity cannot be zero');

    it('allows negative quantity for credit notes', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Returned Product', quantity: -2, unitPrice: 100, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoicedQuantity unitCode="EA">-2.00</cbc:InvoicedQuantity>');
        expect($xml)->toContain('<cbc:LineExtensionAmount currencyID="RON">-200.00</cbc:LineExtensionAmount>');
    });

    it('throws exception for line with negative price', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: -100),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Unit price cannot be negative');

    it('throws exception for line with invalid tax percent', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 150),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Tax percent must be between 0 and 100');
});

describe('tax category handling', function () {
    it('uses Standard (S) for VAT payer with non-zero tax', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 19),
        ]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:ID>S</cbc:ID>');
    });

    it('uses ZeroRated (Z) for VAT payer with zero tax', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 0),
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
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 0),
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
    })->throws(ValidationException::class, 'County "UnknownCounty" could not be mapped to a valid ISO 3166-2:RO code');

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
    })->throws(ValidationException::class, 'County "InvalidCounty" could not be mapped to a valid ISO 3166-2:RO code');

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
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxPercent: 19.0),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxPercent: 19.00),
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
                new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxPercent: 19.001),
                new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxPercent: 19.004),
            ],
        );

        $xml = $builder->buildInvoiceXml($invoice);

        // Both should be grouped into single 19% tax subtotal (2 tags = 1 element)
        expect(substr_count($xml, 'TaxSubtotal'))->toBe(2);
    });

    it('correctly separates genuinely different tax rates', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxPercent: 19.0),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxPercent: 9.0),
            new InvoiceLineData(name: 'Product 3', quantity: 1, unitPrice: 100.00, taxPercent: 5.0),
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
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 0),
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
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 0),
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
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100, taxPercent: 0),
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
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address);
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
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address);
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
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address);
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
            new InvoiceLineData(name: str_repeat('A', 101), quantity: 1, unitPrice: 100),
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
                description: str_repeat('A', 201),
            ),
        ]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Line 1: Item description must not exceed 200 characters (BR-RO-L200)');
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
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'EUR']);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>');
        expect($xml)->toContain('<cbc:TaxCurrencyCode>RON</cbc:TaxCurrencyCode>');
    });

    it('adds second TaxTotal in RON for non-RON invoices', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([], ['currency' => 'USD']);

        $xml = $builder->buildInvoiceXml($invoice);

        // Should have two TaxTotal elements - one in USD and one in RON
        expect(substr_count($xml, '<cac:TaxTotal>'))->toBe(2);
        expect($xml)->toContain('currencyID="USD"');
        expect($xml)->toContain('currencyID="RON"');
    });
});
