<?php

declare(strict_types=1);

use Beecoded\EFactura\Data\Invoice\AddressData;
use Beecoded\EFactura\Data\Invoice\InvoiceData;
use Beecoded\EFactura\Data\Invoice\InvoiceLineData;
use Beecoded\EFactura\Data\Invoice\PartyData;
use Beecoded\EFactura\Enums\InvoiceTypeCode;
use Carbon\Carbon;

function createTestInvoice(array $lines = [], array $overrides = []): InvoiceData
{
    $defaultAddress = new AddressData(
        street: 'Str. Test 1',
        city: 'Bucuresti',
        postalZone: '010101',
        county: 'Bucuresti',
    );

    $defaultSupplier = new PartyData(
        registrationName: 'Test Supplier SRL',
        companyId: 'RO12345678',
        address: $defaultAddress,
        isVatPayer: true,
    );

    $defaultCustomer = new PartyData(
        registrationName: 'Test Customer SRL',
        companyId: 'RO87654321',
        address: $defaultAddress,
        isVatPayer: true,
    );

    $defaultLines = $lines ?: [
        new InvoiceLineData(
            name: 'Product 1',
            quantity: 2,
            unitPrice: 100.00,
            taxPercent: 19,
        ),
    ];

    return new InvoiceData(
        invoiceNumber: $overrides['invoiceNumber'] ?? 'INV-001',
        issueDate: $overrides['issueDate'] ?? Carbon::create(2024, 3, 15),
        supplier: $overrides['supplier'] ?? $defaultSupplier,
        customer: $overrides['customer'] ?? $defaultCustomer,
        lines: $defaultLines,
        dueDate: $overrides['dueDate'] ?? null,
        currency: $overrides['currency'] ?? 'RON',
        paymentIban: $overrides['paymentIban'] ?? null,
        invoiceTypeCode: $overrides['invoiceTypeCode'] ?? null,
    );
}

describe('InvoiceData construction', function () {
    it('creates invoice with required fields', function () {
        $invoice = createTestInvoice();

        expect($invoice->invoiceNumber)->toBe('INV-001');
        expect($invoice->currency)->toBe('RON');
        expect($invoice->lines)->toHaveCount(1);
    });

    it('accepts Carbon and string dates', function () {
        $invoiceWithCarbon = createTestInvoice([], ['issueDate' => Carbon::create(2024, 3, 15)]);
        $invoiceWithString = createTestInvoice([], ['issueDate' => '2024-03-15']);

        expect($invoiceWithCarbon->issueDate)->toBeInstanceOf(Carbon::class);
        expect($invoiceWithString->issueDate)->toBe('2024-03-15');
    });
});

describe('getIssueDateAsCarbon', function () {
    it('returns Carbon for Carbon input', function () {
        $date = Carbon::create(2024, 3, 15);
        $invoice = createTestInvoice([], ['issueDate' => $date]);

        $result = $invoice->getIssueDateAsCarbon();

        expect($result)->toBeInstanceOf(Carbon::class);
        expect($result->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('returns copy to prevent mutation', function () {
        $date = Carbon::create(2024, 3, 15);
        $invoice = createTestInvoice([], ['issueDate' => $date]);

        $result = $invoice->getIssueDateAsCarbon();
        $result->addDay();

        expect($invoice->getIssueDateAsCarbon()->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('parses string date to Carbon', function () {
        $invoice = createTestInvoice([], ['issueDate' => '2024-03-15']);

        $result = $invoice->getIssueDateAsCarbon();

        expect($result)->toBeInstanceOf(Carbon::class);
        expect($result->format('Y-m-d'))->toBe('2024-03-15');
    });
});

describe('getDueDateAsCarbon', function () {
    it('returns null when dueDate is null', function () {
        $invoice = createTestInvoice();

        expect($invoice->getDueDateAsCarbon())->toBeNull();
    });

    it('returns Carbon for Carbon input', function () {
        $date = Carbon::create(2024, 4, 15);
        $invoice = createTestInvoice([], ['dueDate' => $date]);

        $result = $invoice->getDueDateAsCarbon();

        expect($result)->toBeInstanceOf(Carbon::class);
        expect($result->format('Y-m-d'))->toBe('2024-04-15');
    });

    it('returns copy to prevent mutation', function () {
        $date = Carbon::create(2024, 4, 15);
        $invoice = createTestInvoice([], ['dueDate' => $date]);

        $result = $invoice->getDueDateAsCarbon();
        $result->addDay();

        expect($invoice->getDueDateAsCarbon()->format('Y-m-d'))->toBe('2024-04-15');
    });

    it('parses string date to Carbon', function () {
        $invoice = createTestInvoice([], ['dueDate' => '2024-04-15']);

        $result = $invoice->getDueDateAsCarbon();

        expect($result)->toBeInstanceOf(Carbon::class);
    });
});

describe('getInvoiceTypeCode', function () {
    it('returns CommercialInvoice by default', function () {
        $invoice = createTestInvoice();

        expect($invoice->getInvoiceTypeCode())->toBe(InvoiceTypeCode::CommercialInvoice);
    });

    it('returns specified invoice type code', function () {
        $invoice = createTestInvoice([], ['invoiceTypeCode' => InvoiceTypeCode::CreditNote]);

        expect($invoice->getInvoiceTypeCode())->toBe(InvoiceTypeCode::CreditNote);
    });
});

describe('getTotalExcludingVat', function () {
    it('calculates total for single line', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 2, unitPrice: 100.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalExcludingVat())->toBe(200.00);
    });

    it('calculates total for multiple lines', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 2, unitPrice: 100.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 3, unitPrice: 50.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalExcludingVat())->toBe(350.00); // 200 + 150
    });

    it('rounds to 2 decimal places', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 3, unitPrice: 33.333, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalExcludingVat())->toBe(100.00);
    });
});

describe('getTotalVat', function () {
    it('calculates VAT for single tax rate', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalVat())->toBe(19.00);
    });

    it('groups lines by tax rate before calculating', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        // 200 * 0.19 = 38.00 (grouped calculation)
        expect($invoice->getTotalVat())->toBe(38.00);
    });

    it('handles multiple tax rates', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxPercent: 9),
        ];
        $invoice = createTestInvoice($lines);

        // 100 * 0.19 = 19.00, 100 * 0.09 = 9.00
        expect($invoice->getTotalVat())->toBe(28.00);
    });

    it('handles zero tax rate', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxPercent: 0),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalVat())->toBe(0.00);
    });

    it('rounds correctly', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 3, unitPrice: 33.33, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        // Raw: 3 * 33.33 = 99.99, Tax: 99.99 * 0.19 = 18.9981 -> 19.00
        expect($invoice->getTotalVat())->toBe(19.00);
    });
});

describe('getTotalIncludingVat', function () {
    it('calculates total with VAT', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalIncludingVat())->toBe(119.00);
    });

    it('handles multiple lines and rates', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 2, unitPrice: 100.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 50.00, taxPercent: 9),
        ];
        $invoice = createTestInvoice($lines);

        // Excluding VAT: 200 + 50 = 250
        // VAT: 200 * 0.19 + 50 * 0.09 = 38 + 4.5 = 42.5
        // Total: 250 + 42.5 = 292.5
        expect($invoice->getTotalIncludingVat())->toBe(292.50);
    });
});
