<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException as SdkValidationException;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataContainer;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

describe('InvoiceLineData construction', function () {
    it('has correct default values', function () {
        $line = new InvoiceLineData(
            name: 'Test Product',
            quantity: 1,
            unitPrice: 100.00,
            taxAmount: 0.00,
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
            taxAmount: 0.00,
        );

        expect($line->getLineTotal())->toBe(200.00);
    });

    it('rounds to 2 decimal places', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 3,
            unitPrice: 33.333,
            taxAmount: 0.00,
        );

        expect($line->getLineTotal())->toBe(100.00);
    });

    it('handles decimal quantities', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1.5,
            unitPrice: 100.00,
            taxAmount: 0.00,
        );

        expect($line->getLineTotal())->toBe(150.00);
    });

    it('handles zero quantity', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 0,
            unitPrice: 100.00,
            taxAmount: 0.00,
        );

        expect($line->getLineTotal())->toBe(0.00);
    });

    it('handles zero price', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 2,
            unitPrice: 0,
            taxAmount: 0.00,
        );

        expect($line->getLineTotal())->toBe(0.00);
    });
});

describe('getTaxAmount', function () {
    it('returns pre-computed tax amount', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxAmount: 19.00,
            taxPercent: 19,
        );

        expect($line->getTaxAmount())->toBe(19.00);
    });

    it('returns zero for zero tax', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxAmount: 0.00,
            taxPercent: 0,
        );

        expect($line->getTaxAmount())->toBe(0.00);
    });

    it('rounds to 2 decimal places', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 33.33,
            taxAmount: 6.3327,
            taxPercent: 19,
        );

        // Pre-computed 6.3327 rounds to 6.33
        expect($line->getTaxAmount())->toBe(6.33);
    });

    it('handles different tax rates', function () {
        $line9 = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxAmount: 9.00,
            taxPercent: 9,
        );

        $line5 = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxAmount: 5.00,
            taxPercent: 5,
        );

        expect($line9->getTaxAmount())->toBe(9.00);
        expect($line5->getTaxAmount())->toBe(5.00);
    });

    it('preserves pre-computed value that differs from calculated', function () {
        // Simulates tax-included scenario: total=100, base=82.64, vat=17.36
        // But round(82.64 * 0.21, 2) = 17.35 — our pre-computed 17.36 wins
        $line = new InvoiceLineData(
            name: 'Tax-included product',
            quantity: 1,
            unitPrice: 82.64,
            taxAmount: 17.36,
            taxPercent: 21,
        );

        expect($line->getTaxAmount())->toBe(17.36);
    });
});

describe('getRawLineTotal', function () {
    it('returns unrounded line total', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 3,
            unitPrice: 33.333,
            taxAmount: 0.00,
        );

        expect($line->getRawLineTotal())->toBe(99.999);
    });

    it('is used for tax grouping calculations', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 3,
            unitPrice: 33.333,
            taxAmount: 0.00,
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
            taxAmount: -38.00,
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
            'taxAmount' => 0.00,
            'taxPercent' => -5,
        ]);
    })->throws(ValidationException::class, 'The tax percent field must be at least 0.');

    it('accepts zero tax percent', function () {
        $line = InvoiceLineData::validateAndCreate([
            'name' => 'Exempt Product',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'taxAmount' => 0.00,
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
            taxAmount: 19.00,
            taxPercent: 19,
        );

        expect($line->getLineTotalWithTax())->toBe(119.00);
    });

    it('equals line total when tax is zero', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 100.00,
            taxAmount: 0.00,
            taxPercent: 0,
        );

        expect($line->getLineTotalWithTax())->toBe(100.00);
    });

    it('rounds to 2 decimal places', function () {
        $line = new InvoiceLineData(
            name: 'Product',
            quantity: 1,
            unitPrice: 33.33,
            taxAmount: 6.33,
            taxPercent: 19,
        );

        // Line total: 33.33, Tax: 6.33, Total: 39.66
        expect($line->getLineTotalWithTax())->toBe(39.66);
    });
});

describe('explicit VAT category compatibility', function () {
    it('preserves positional constructors and the exact omitted or null serialized shape', function () {
        $expected = [
            'name' => 'Product', 'quantity' => 2.0, 'unitPrice' => 100.0, 'taxAmount' => 0.0,
            'id' => '1', 'description' => 'Description', 'unitCode' => 'HUR', 'taxPercent' => 0.0,
        ];
        $positional = new InvoiceLineData('Product', 2, 100, 0, '1', 'Description', 'HUR', 0);
        foreach ([$expected, [...$expected, 'taxCategoryId' => null]] as $payload) {
            foreach ([$positional, InvoiceLineData::from($payload), InvoiceLineData::validateAndCreate($payload)] as $line) {
                expect($line->taxCategoryId)->toBeNull()
                    ->and($line->toArray())->toBe($expected)
                    ->and($line->toJson())->toBe($positional->toJson())
                    ->and(InvoiceLineData::from(json_decode($line->toJson(), true, flags: JSON_THROW_ON_ERROR))->toArray())->toBe($expected);
            }
        }
    });

    it('hydrates and serializes the appended enum without retaining null exclusions', function () {
        $line = new InvoiceLineData('Product', 1, 100, 0);
        $legacy = $line->toArray();
        $line->taxCategoryId = TaxCategoryId::ReverseCharge;
        $expected = [...$legacy, 'taxCategoryId' => 'AE'];
        $positional = new InvoiceLineData('Product', 1, 100, 0, null, null, 'EA', 0, TaxCategoryId::ReverseCharge);
        foreach ([$line, $positional, InvoiceLineData::from($expected), InvoiceLineData::validateAndCreate($expected)] as $candidate) {
            expect($candidate->taxCategoryId)->toBe(TaxCategoryId::ReverseCharge)
                ->and($candidate->toArray())->toBe($expected)
                ->and(InvoiceLineData::from(json_decode($candidate->toJson(), true, flags: JSON_THROW_ON_ERROR))->toArray())->toBe($expected);
        }
        $line->taxCategoryId = null;
        expect($line->toArray())->toBe($legacy);
    });

    it('keeps category and rate resolution distinct without changing legacy inference', function () {
        $line = new InvoiceLineData('Product', 1, 100, 0);
        expect($line->getTaxCategory(true))->toBe(TaxCategoryId::ZeroRated)
            ->and($line->getTaxCategory(false))->toBe(TaxCategoryId::NotSubject);
        $line->taxCategoryId = TaxCategoryId::ReverseCharge;
        expect($line->getTaxGroupKey(true))->toBe('AE:0');
        $line->taxCategoryId = TaxCategoryId::ZeroRated;
        expect($line->getTaxGroupKey(true))->toBe('Z:0');
        $line->taxCategoryId = TaxCategoryId::Standard;
        $line->taxPercent = 19.00000001;
        expect($line->getTaxGroupKey(true))->toBe('S:19');
    });

    it('rejects raw nonzero AE values even when money or rate rounding would hide them', function (string $field, float $value) {
        $line = InvoiceLineData::from([
            'name' => 'Product', 'quantity' => 1, 'unitPrice' => 100,
            'taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => 'AE',
        ]);
        $line->{$field} = $value;
        expect(fn () => $line->getTaxCategory(true))->toThrow(SdkValidationException::class, 'exactly zero raw');
    })->with([
        'tiny rate' => ['taxPercent', 0.000001],
        'tiny negative rate' => ['taxPercent', -0.000001],
        'tiny tax' => ['taxAmount', 0.000001],
        'tiny negative tax' => ['taxAmount', -0.000001],
        'NaN tax' => ['taxAmount', NAN],
        'infinite rate' => ['taxPercent', INF],
    ]);

    it('rejects unknown categories at the real data validation gate', function () {
        InvoiceLineData::validateAndCreate([
            'name' => 'Product', 'quantity' => 1, 'unitPrice' => 100,
            'taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => 'UNKNOWN',
        ]);
    })->throws(ValidationException::class);
});

describe('explicit VAT category transformation contexts', function () {
    beforeEach(function () {
        $originalMapping = config('data.name_mapping_strategy');
        $this->beforeApplicationDestroyed(static function () use ($originalMapping): void {
            config()->set('data.name_mapping_strategy', $originalMapping);
            app(DataConfig::class)->reset();
            DataContainer::get()->reset();
        });
        DataContainer::get()->reset();
        app(DataConfig::class)->reset();
    });

    it('omits null before mapping and wrapping and preserves mapped hydration', function () {
        config()->set('data.name_mapping_strategy.input', SnakeCaseMapper::class);
        config()->set('data.name_mapping_strategy.output', SnakeCaseMapper::class);
        app(DataConfig::class)->reset();
        $line = (new InvoiceLineData('Product', 1, 100, 0))->wrap('line');
        $factory = TransformationContextFactory::create()->withWrapping();
        $legacy = $line->transform($factory);
        expect(array_keys($legacy['line']))->not->toContain('tax_category_id');
        $line->taxCategoryId = TaxCategoryId::ReverseCharge;
        $expected = [...$legacy['line'], 'tax_category_id' => 'AE'];
        expect($line->transform($factory))->toBe(['line' => $expected])
            ->and(InvoiceLineData::from($expected)->toArray())->toBe($expected);
        $line->taxCategoryId = null;
        expect($line->transform($factory))->toBe($legacy);
    });

    it('preserves caller context partials and settings across category mutation', function (bool $useFactory) {
        $line = (new InvoiceLineData('Product', 1, 100, 0))->wrap('line');
        $factory = TransformationContextFactory::create()
            ->withoutValueTransformation()->withWrapping()
            ->only('name', 'id', 'taxCategoryId')->except('id')->maxDepth(8, false);
        $context = $factory->get($line);
        $context->depth = 2;
        $caller = $useFactory ? $factory : $context;
        $before = $useFactory ? $factory->get($line)->toArray() : $context->toArray();
        expect($line->transform($caller))->toBe(['line' => ['name' => 'Product']]);
        $line->taxCategoryId = TaxCategoryId::ReverseCharge;
        expect($line->transform($caller))->toBe(['line' => ['name' => 'Product', 'taxCategoryId' => TaxCategoryId::ReverseCharge]]);
        $line->taxCategoryId = null;
        expect($line->transform($caller))->toBe(['line' => ['name' => 'Product']])
            ->and($useFactory ? $factory->get($line)->toArray() : $context->toArray())->toBe($before)
            ->and($context->depth)->toBe(2);
    })->with(['factory' => true, 'context' => false]);
});
