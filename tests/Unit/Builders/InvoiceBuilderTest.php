<?php

declare(strict_types=1);

use Beecoded\EFactura\Builders\InvoiceBuilder;
use Beecoded\EFactura\Data\Invoice\AddressData;
use Beecoded\EFactura\Data\Invoice\InvoiceData;
use Beecoded\EFactura\Data\Invoice\InvoiceLineData;
use Beecoded\EFactura\Data\Invoice\PartyData;
use Beecoded\EFactura\Enums\InvoiceTypeCode;
use Beecoded\EFactura\Exceptions\ValidationException;
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
        $invoice = createTestInvoiceForBuilder([], ['invoiceTypeCode' => InvoiceTypeCode::CreditNote]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:InvoiceTypeCode>381</cbc:InvoiceTypeCode>');
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
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101');
        $supplier = new PartyData(registrationName: '', companyId: 'RO12345678', address: $address);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier registration name is required');

    it('throws exception for missing supplier company ID', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101');
        $supplier = new PartyData(registrationName: 'Test', companyId: '', address: $address);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier company ID (CIF/CUI) is required');

    it('throws exception for missing street address', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: '', city: 'Test', postalZone: '010101');
        $supplier = new PartyData(registrationName: 'Test', companyId: 'RO12345678', address: $address);
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $builder->buildInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Supplier street address is required');

    it('throws exception for empty lines', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder([]);

        // Empty array is passed but the helper function provides default lines
        // Need to create invoice directly
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101');
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
    })->throws(ValidationException::class, 'Line 1: Quantity must be greater than zero');

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
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101');
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
});

describe('VAT number normalization', function () {
    it('adds RO prefix to company ID', function () {
        $builder = new InvoiceBuilder;
        $address = new AddressData(street: 'Test', city: 'Test', postalZone: '010101');
        $supplier = new PartyData(
            registrationName: 'Test',
            companyId: '12345678', // Without RO prefix
            address: $address,
            isVatPayer: true,
        );
        $invoice = createTestInvoiceForBuilder([], ['supplier' => $supplier]);

        $xml = $builder->buildInvoiceXml($invoice);

        expect($xml)->toContain('<cbc:CompanyID>RO12345678</cbc:CompanyID>');
    });

    it('keeps existing RO prefix', function () {
        $builder = new InvoiceBuilder;
        $invoice = createTestInvoiceForBuilder();

        $xml = $builder->buildInvoiceXml($invoice);

        // Should not duplicate RO prefix
        expect($xml)->not->toContain('RORO');
    });
});
