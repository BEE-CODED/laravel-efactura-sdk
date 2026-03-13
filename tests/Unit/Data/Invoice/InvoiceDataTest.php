<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
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
            taxAmount: 38.00,
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

    it('throws exception for invalid date string', function () {
        $invoice = createTestInvoice([], ['dueDate' => 'not-a-valid-date']);

        $invoice->getDueDateAsCarbon();
    })->throws(InvalidArgumentException::class, 'Invalid due date format');
});

describe('getIssueDateAsCarbon exception handling', function () {
    it('throws exception for invalid issue date string', function () {
        $invoice = createTestInvoice([], ['issueDate' => 'invalid-date-format']);

        $invoice->getIssueDateAsCarbon();
    })->throws(InvalidArgumentException::class, 'Invalid issue date format');

    it('throws exception for malformed date', function () {
        $invoice = createTestInvoice([], ['issueDate' => '2024-13-45']);

        $invoice->getIssueDateAsCarbon();
    })->throws(InvalidArgumentException::class, 'Invalid issue date format');

    it('includes original value in exception message', function () {
        $invoice = createTestInvoice([], ['issueDate' => 'foobar']);

        try {
            $invoice->getIssueDateAsCarbon();
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('foobar');
            expect($e->getPrevious())->not->toBeNull();
        }
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
            new InvoiceLineData(name: 'Product', quantity: 2, unitPrice: 100.00, taxAmount: 38.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalExcludingVat())->toBe(200.00);
    });

    it('calculates total for multiple lines', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 2, unitPrice: 100.00, taxAmount: 38.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 3, unitPrice: 50.00, taxAmount: 28.50, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalExcludingVat())->toBe(350.00); // 200 + 150
    });

    it('rounds to 2 decimal places', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 3, unitPrice: 33.333, taxAmount: 19.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalExcludingVat())->toBe(100.00);
    });
});

describe('getTotalVat', function () {
    it('calculates VAT for single tax rate', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalVat())->toBe(19.00);
    });

    it('sums per-line tax amounts', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        // 19.00 + 19.00 = 38.00 (per-line sum)
        expect($invoice->getTotalVat())->toBe(38.00);
    });

    it('handles multiple tax rates', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 9.00, taxPercent: 9),
        ];
        $invoice = createTestInvoice($lines);

        // 19.00 + 9.00 = 28.00
        expect($invoice->getTotalVat())->toBe(28.00);
    });

    it('handles zero tax rate', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxAmount: 0.00, taxPercent: 0),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalVat())->toBe(0.00);
    });

    it('rounds correctly', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 3, unitPrice: 33.33, taxAmount: 19.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        // Pre-computed taxAmount: round(99.99 * 0.19, 2) = 19.00
        expect($invoice->getTotalVat())->toBe(19.00);
    });

    it('sums lines with floating-point precision differences in tax rate', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.0),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.001),
        ];
        $invoice = createTestInvoice($lines);

        // 19.00 + 19.00 = 38.00
        expect($invoice->getTotalVat())->toBe(38.00);
    });

    it('sums lines with rates that round to same 2-decimal value', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.001),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.004),
        ];
        $invoice = createTestInvoice($lines);

        // 19.00 + 19.00 = 38.00
        expect($invoice->getTotalVat())->toBe(38.00);
    });

    it('keeps different tax amounts separate when rates round to different values', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19.004),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 100.00, taxAmount: 19.01, taxPercent: 19.006),
        ];
        $invoice = createTestInvoice($lines);

        // 19.00 + 19.01 = 38.01
        expect($invoice->getTotalVat())->toBe(38.01);
    });
});

describe('getTotalIncludingVat', function () {
    it('calculates total with VAT', function () {
        $lines = [
            new InvoiceLineData(name: 'Product', quantity: 1, unitPrice: 100.00, taxAmount: 19.00, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        expect($invoice->getTotalIncludingVat())->toBe(119.00);
    });

    it('handles multiple lines and rates', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 2, unitPrice: 100.00, taxAmount: 38.00, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 50.00, taxAmount: 4.50, taxPercent: 9),
        ];
        $invoice = createTestInvoice($lines);

        // Excluding VAT: 200 + 50 = 250
        // VAT: 38.00 + 4.50 = 42.50
        // Total: 250 + 42.50 = 292.50
        expect($invoice->getTotalIncludingVat())->toBe(292.50);
    });
});
