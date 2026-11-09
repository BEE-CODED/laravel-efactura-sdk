<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Support\AnafValue;

describe('AnafValue::id', function () {
    it('normalises identifiers', function (mixed $raw, ?string $expected) {
        expect(AnafValue::id($raw))->toBe($expected);
    })->with([
        'int' => [5001234567, '5001234567'],
        'digit string' => ['5001234567', '5001234567'],
        'padded string' => ['  42 ', '42'],
        'non-digit string kept' => ['ABC-1', 'ABC-1'],
        'big integer string' => ['123456789012345678901234', '123456789012345678901234'],
        'empty string' => ['', null],
        'whitespace' => ['   ', null],
        'float' => [5.0, null],
        'bool' => [true, null],
        'array' => [['5'], null],
        'null' => [null, null],
    ]);
});

describe('AnafValue::text', function () {
    it('turns scalars into strings and everything else into null', function (mixed $raw, ?string $expected) {
        expect(AnafValue::text($raw))->toBe($expected);
    })->with([
        'string' => ['Lista Mesaje', 'Lista Mesaje'],
        'int' => [12, '12'],
        'float' => [1.5, '1.5'],
        'empty string' => ['', ''],
        'array' => [['a'], null],
        'null' => [null, null],
    ]);
});

describe('AnafValue::int', function () {
    it('accepts whole numbers only', function (mixed $raw, ?int $expected) {
        expect(AnafValue::int($raw))->toBe($expected);
    })->with([
        'int' => [3, 3],
        'digit string' => ['17', 17],
        'signed padded string' => [' -2 ', -2],
        'whole float' => [4.0, 4],
        'fractional float' => [4.5, null],
        'decimal string' => ['4.5', null],
        'word' => ['abc', null],
        'overflowing digits' => ['99999999999999999999', null],
        'array' => [[1], null],
        'null' => [null, null],
    ]);
});

describe('AnafValue::errors', function () {
    it('normalises every Errors shape ANAF sends', function (mixed $raw, ?array $expected) {
        expect(AnafValue::errors($raw))->toBe($expected);
    })->with([
        'string' => ['Fisier invalid', ['Fisier invalid']],
        'single errorMessage object' => [['errorMessage' => 'Bad'], ['Bad']],
        'numeric errorMessage' => [['errorMessage' => 500], ['500']],
        'list of strings and objects' => [['a', ['errorMessage' => 'b'], 7], ['a', 'b', '7']],
        'list drops junk and empties' => [['', '  ', ['other' => 'x'], ['errorMessage' => ['x']], true, ['nested'], 'kept'], ['kept']],
        'object without errorMessage' => [['nested' => 'thing'], null],
        'empty list' => [[], null],
        'empty string' => ['', null],
        'bool' => [false, null],
        'null' => [null, null],
    ]);
});

describe('flag', function () {
    it('reads only an explicit yes', function (mixed $value, bool $expected) {
        expect(AnafValue::flag($value))->toBe($expected);
    })->with([
        'true' => [true, true],
        'false' => [false, false],
        'int 1' => [1, true],
        'int 0' => [0, false],
        'string 1' => ['1', true],
        'string true' => [' TRUE ', true],
        'string false' => ['false', false],
        'string 0' => ['0', false],
        'empty' => ['', false],
        'null' => [null, false],
        'array' => [[true], false],
    ]);
});
