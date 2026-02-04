<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\UblBuilder;

describe('UblBuilder', function () {
    it('generates invoice XML from invoice data', function () {
        $ubl = new UblBuilder;

        $invoice = new InvoiceData(
            invoiceNumber: 'INV-001',
            issueDate: '2024-06-15',
            supplier: new PartyData(
                registrationName: 'Supplier SRL',
                companyId: 'RO12345678',
                address: new AddressData(
                    street: 'Main Street 1',
                    city: 'Bucharest',
                    postalZone: '010101',
                    county: 'Bucuresti',
                    countryCode: 'RO',
                ),
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
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Test Product',
                    quantity: 1,
                    unitPrice: 100.00,
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
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Product',
                    quantity: 1,
                    unitPrice: 100,
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
            ),
            lines: [
                new InvoiceLineData(
                    name: 'Product',
                    quantity: 1,
                    unitPrice: 100,
                    taxPercent: 19,
                ),
            ],
        );

        $ubl->generateInvoiceXml($invoice);
    })->throws(ValidationException::class, 'Failed to generate invoice XML');
});
