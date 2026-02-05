<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;

describe('InvoiceLineData construction', function () {
    it('has correct default values', function () {
        $line = new InvoiceLineData(
            name: 'Test Product',
            quantity: 1,
            unitPrice: 100.00,
        );

        expect($line->id)->toBeNull();
        expect($line->description)->toBeNull();
        expect($line->unitCode)->toBe('EA');
        expect($line->taxPercent)->toBe(0.0);
    });
});

describe('getLineTotal', function () {
    it('calculates quantity times unit price', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 2,
            unitPrice: 100.00,
        );

        expect($line->getLineTotal())->toBe(200.00);
    });

    it('rounds to 2 decimal places', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 3,
            unitPrice: 33.333,
        );

        expect($line->getLineTotal())->toBe(100.00);
    });

    it('handles decimal quantities', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1.5,
            unitPrice: 100.00,
        );

        expect($line->getLineTotal())->toBe(150.00);
    });

    it('handles zero quantity', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 0,
            unitPrice: 100.00,
        );

        expect($line->getLineTotal())->toBe(0.00);
    });

    it('handles zero price', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 2,
            unitPrice: 0,
        );

        expect($line->getLineTotal())->toBe(0.00);
    });
});

describe('getTaxAmount', function () {
    it('calculates tax based on line total', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxPercent: 19,
        );

        expect($line->getTaxAmount())->toBe(19.00);
    });

    it('returns zero for zero tax percent', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxPercent: 0,
        );

        expect($line->getTaxAmount())->toBe(0.00);
    });

    it('rounds to 2 decimal places', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 33.33,
            taxPercent: 19,
        );

        // 33.33 * 0.19 = 6.3327 -> 6.33
        expect($line->getTaxAmount())->toBe(6.33);
    });

    it('handles different tax rates', function () {
        $line9 = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxPercent: 9,
        );

        $line5 = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxPercent: 5,
        );

        expect($line9->getTaxAmount())->toBe(9.00);
        expect($line5->getTaxAmount())->toBe(5.00);
    });
});

describe('getRawLineTotal', function () {
    it('returns unrounded line total', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 3,
            unitPrice: 33.333,
        );

        expect($line->getRawLineTotal())->toBe(99.999);
    });

    it('is used for tax grouping calculations', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 3,
            unitPrice: 33.333,
        );

        // Raw total preserves precision for grouping
        expect($line->getRawLineTotal())->not->toBe($line->getLineTotal());
    });
});

describe('negative values', function () {
    it('allows negative quantity for credit notes', function () {
        $line = new InvoiceLineData(
            name: 'Returned Product',
            quantity: -2,
            unitPrice: 100.00,
            taxPercent: 19,
        );

        expect($line->getLineTotal())->toBe(-200.00);
        expect($line->getTaxAmount())->toBe(-38.00);
        expect($line->getLineTotalWithTax())->toBe(-238.00);
    });

    it('rejects negative tax percent via validation', function () {
        $validated = InvoiceLineData::validateAndCreate([
            'name' => 'Product',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'taxPercent' => -5,
        ]);
    })->throws(Illuminate\Validation\ValidationException::class, 'The tax percent field must be at least 0.');

    it('accepts zero tax percent', function () {
        $line = InvoiceLineData::validateAndCreate([
            'name' => 'Exempt Product',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'taxPercent' => 0,
        ]);

        expect($line->taxPercent)->toBe(0.0);
    });
});

describe('getLineTotalWithTax', function () {
    it('calculates line total plus tax', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxPercent: 19,
        );

        expect($line->getLineTotalWithTax())->toBe(119.00);
    });

    it('equals line total when tax is zero', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxPercent: 0,
        );

        expect($line->getLineTotalWithTax())->toBe(100.00);
    });

    it('rounds to 2 decimal places', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 33.33,
            taxPercent: 19,
        );

        // Line total: 33.33, Tax: 6.33, Total: 39.66
        expect($line->getLineTotalWithTax())->toBe(39.66);
    });
});
