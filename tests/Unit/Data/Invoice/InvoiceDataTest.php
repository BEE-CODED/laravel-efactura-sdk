<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

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

    // The filed XML sums per-line ROUNDED net amounts (one cbc:LineExtensionAmount
    // per line, each capped at 2 decimals by BR-DEC-*), so the helper must round
    // per line too. Rounding a raw sum once at the end loses a bani per pair of
    // sub-cent lines against the document the customer actually receives.
    it('sums per-line rounded amounts, as the filed XML does', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 0.5, unitPrice: 0.01, taxAmount: 0.00, taxPercent: 0),
            new InvoiceLineData(name: 'Product 2', quantity: 0.5, unitPrice: 0.01, taxAmount: 0.00, taxPercent: 0),
        ];
        $invoice = createTestInvoice($lines);

        // Each line files as round(0.005, 2) = 0.01, so the document total is 0.02.
        expect($invoice->getTotalExcludingVat())->toBe(0.02);
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

    // The filed XML rounds VAT once PER TAX-RATE GROUP (each cac:TaxSubtotal
    // carries its own cbc:TaxAmount) and the document total BT-110 is the sum of
    // those rounded subtotals. Rounding the raw all-lines sum once therefore
    // diverges as soon as two rate groups each carry a sub-cent residue.
    it('sums per-tax-group rounded amounts, as the filed XML does', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 1.00, taxAmount: 0.005, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 1.00, taxAmount: 0.005, taxPercent: 5),
        ];
        $invoice = createTestInvoice($lines);

        // Two groups, each rounding to 0.01 in its own cac:TaxSubtotal -> 0.02.
        expect($invoice->getTotalVat())->toBe(0.02);
    });

    // ...but WITHIN one rate group the XML rounds the accumulated total once, so
    // per-line rounding would be wrong here. This pins the distinction.
    it('rounds once per group, not per line, when lines share a tax rate', function () {
        $lines = [
            new InvoiceLineData(name: 'Product 1', quantity: 1, unitPrice: 1.00, taxAmount: 0.005, taxPercent: 19),
            new InvoiceLineData(name: 'Product 2', quantity: 1, unitPrice: 1.00, taxAmount: 0.005, taxPercent: 19),
        ];
        $invoice = createTestInvoice($lines);

        // One group: round(0.005 + 0.005, 2) = 0.01 (NOT 0.01 + 0.01 = 0.02).
        expect($invoice->getTotalVat())->toBe(0.01);
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

describe('InvoiceData immutable date support', function () {
    // Apps calling Date::use(CarbonImmutable::class) hydrate Eloquent datetime casts as
    // CarbonImmutable, which is NOT a Carbon subclass. The README documents passing those
    // casts straight into InvoiceData, so the constructor must accept them.

    it('accepts an immutable issueDate and normalises it to Carbon', function () {
        $invoice = createTestInvoice(overrides: [
            'issueDate' => CarbonImmutable::create(2024, 3, 15),
        ]);

        expect($invoice->issueDate)->toBeInstanceOf(Carbon::class)
            ->and($invoice->getIssueDateAsCarbon()->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('accepts an immutable dueDate and normalises it to Carbon', function () {
        $invoice = createTestInvoice(overrides: [
            'dueDate' => CarbonImmutable::create(2024, 4, 14),
        ]);

        expect($invoice->dueDate)->toBeInstanceOf(Carbon::class)
            ->and($invoice->getDueDateAsCarbon()?->format('Y-m-d'))->toBe('2024-04-14');
    });

    it('preserves a mutable Carbon issueDate as-is', function () {
        // BC guard: existing callers must keep getting the very same instance back.
        $issueDate = Carbon::create(2024, 3, 15);

        $invoice = createTestInvoice(overrides: ['issueDate' => $issueDate]);

        expect($invoice->issueDate)->toBe($issueDate);
    });

    it('still accepts a string issueDate', function () {
        $invoice = createTestInvoice(overrides: ['issueDate' => '2024-03-15']);

        expect($invoice->issueDate)->toBe('2024-03-15')
            ->and($invoice->getIssueDateAsCarbon()->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('does not alias the date returned by getIssueDateAsCarbon', function () {
        // getIssueDateAsCarbon() documents that it returns a copy; mutating the result
        // must not corrupt the invoice.
        $invoice = createTestInvoice(overrides: [
            'issueDate' => CarbonImmutable::create(2024, 3, 15),
        ]);

        $invoice->getIssueDateAsCarbon()->addYear();

        expect($invoice->getIssueDateAsCarbon()->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('yields a mutable Carbon for an immutable issueDate on the ::from() path', function () {
        // Note what this does and does not guard. laravel-data's cast converts the immutable
        // date to a Carbon BEFORE the constructor runs, so this passes with or without the
        // constructor's normalisation -- it is not a guard for that. What it does guard is the
        // un-promotion rewrite: the resolver direct-writes un-promoted properties, so anything
        // other than a Carbon reaching $issueDate would TypeError against its declared type,
        // and getIssueDateAsCarbon()'s `instanceof Carbon` check would stop holding.
        $address = new AddressData(street: 'Str. Test 1', city: 'Bucuresti', postalZone: '010101', county: 'Bucuresti');
        $party = new PartyData(registrationName: 'Test SRL', companyId: 'RO12345678', address: $address, isVatPayer: true);

        $invoice = InvoiceData::from([
            'invoiceNumber' => 'INV-001',
            'issueDate' => CarbonImmutable::create(2024, 3, 15),
            'dueDate' => CarbonImmutable::create(2024, 4, 14),
            'supplier' => $party,
            'customer' => $party,
            'lines' => [['name' => 'P1', 'quantity' => 1, 'unitPrice' => 100.0, 'taxAmount' => 19.0, 'taxPercent' => 19]],
        ]);

        expect($invoice->issueDate)->toBeInstanceOf(Carbon::class)
            ->and($invoice->dueDate)->toBeInstanceOf(Carbon::class)
            ->and($invoice->lines[0])->toBeInstanceOf(InvoiceLineData::class)
            ->and($invoice->getIssueDateAsCarbon()->format('Y-m-d'))->toBe('2024-03-15')
            ->and($invoice->getDueDateAsCarbon()?->format('Y-m-d'))->toBe('2024-04-14');
    });

    it('preserves microseconds and timezone when normalising', function () {
        // Carbon::instance() round-trips through 'U.u'; assert the precision the docblock
        // promises rather than trusting it.
        $issueDate = CarbonImmutable::create(2024, 3, 15, 10, 30, 45, 'Europe/Bucharest')->addMicroseconds(123456);

        $invoice = createTestInvoice(overrides: ['issueDate' => $issueDate]);

        expect($invoice->issueDate)->toBeInstanceOf(Carbon::class)
            ->and($invoice->issueDate->format('Y-m-d H:i:s.u'))->toBe('2024-03-15 10:30:45.123456')
            ->and($invoice->issueDate->getTimezone()->getName())->toBe('Europe/Bucharest')
            ->and($invoice->issueDate->equalTo($issueDate))->toBeTrue();
    });
});

describe('InvoiceData serialisation contract', function () {
    it('serialises keys in the declared order', function () {
        // The date properties are declared in the class body rather than promoted, and
        // spatie derives serialisation order from declaration order. Declaring them out
        // of constructor order would silently reorder every consumer's toArray()/toJson().
        expect(array_keys(createTestInvoice()->toArray()))->toBe([
            'invoiceNumber', 'issueDate', 'supplier', 'customer', 'lines',
            'dueDate', 'currency', 'paymentIban', 'invoiceTypeCode', 'precedingInvoiceNumber',
            'taxAmountRon',
        ]);
    });

    it('omits defaulted fields from validation rules', function () {
        // Body-declared properties carry the constructor's defaults so spatie still treats
        // them as optional; without them, currency/dueDate would become required.
        expect(array_keys(InvoiceData::getValidationRules([])))
            ->not->toContain('currency')
            ->and(array_keys(InvoiceData::getValidationRules([])))->not->toContain('dueDate');
    });

    it('carries taxAmountRon through ::from() hydration', function () {
        // taxAmountRon is body-declared like its siblings, so it must survive the
        // ::from() path that direct-writes un-promoted properties.
        $invoice = InvoiceData::from([
            ...createTestInvoice()->toArray(),
            'currency' => 'EUR',
            'taxAmountRon' => 944.30,
        ]);

        expect($invoice->taxAmountRon)->toBe(944.30);
    });
});
