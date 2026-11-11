<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\Amount;
use Brick\Math\BigDecimal;

describe('Amount::parse', function () {
    it('reads exact decimals at the requested scale', function (mixed $value, int $scale, string $expected) {
        expect((string) Amount::parse($value, $scale, 'field'))->toBe($expected);
    })->with([
        'int' => [5, 2, '5.00'],
        'numeric string' => ['12.5', 2, '12.50'],
        'trailing zeros beyond the scale' => ['1.500000000', 2, '1.50'],
        'negative string' => ['-0.01', 2, '-0.01'],
        'BigDecimal' => [BigDecimal::of('0.000001'), 6, '0.000001'],
        '17 significant digits filed verbatim' => ['12345678901.234567', 6, '12345678901.234567'],
        'float with binary noise' => [0.1 + 0.2, 2, '0.30'],
        'float at scale 6' => [1.375, 6, '1.375000'],
        'negative zero float' => [-0.0, 2, '0.00'],
        'large float below the scale-2 limit' => [2 ** 45 + 0.25, 2, '35184372088832.25'],
    ]);

    it('refuses values that would lose precision or are not amounts, naming the field', function (mixed $value, int $scale) {
        expect(fn () => Amount::parse($value, $scale, 'lines.0.quantity'))
            ->toThrow(ValidationException::class, 'lines.0.quantity');
    })->with([
        'string with excess decimals' => ['0.005', 2],
        'BigDecimal with excess decimals' => [BigDecimal::of('0.0000001'), 6],
        'meaningful sub-cent float' => [0.001, 2],
        'float past the noise tolerance' => [0.01000001, 2],
        'tiny float at scale 2' => [0.000001, 2],
        'NaN' => [NAN, 2],
        'INF' => [INF, 6],
        '-INF' => [-INF, 2],
        // Above each limit yet rendered exactly by number_format, so only the limit refuses them.
        'float above the scale-2 limit' => [1e14 + 0.5, 2],
        'float above the scale-6 limit' => [1e10, 6],
        'non-numeric string' => ['abc', 2],
        'empty string' => ['', 2],
        'null' => [null, 2],
        'bool' => [true, 2],
        'array' => [['1'], 2],
    ]);
});
