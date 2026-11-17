<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;

describe('withCountryPrefix', function () {
    it('prefixes a VAT identifier exactly as the builder files BT-31/BT-48', function (string $id, ?string $country, string $expected) {
        expect(VatNumberValidator::withCountryPrefix($id, $country))->toBe($expected);
    })->with([
        'bare RO CUI' => ['12345678', 'RO', 'RO12345678'],
        'null country means RO' => ['12345678', null, 'RO12345678'],
        'already prefixed, lowercase and padded' => [' ro87654321 ', 'RO', 'RO87654321'],
        'separator removed, not doubled' => ['RO 12345678', 'RO', 'RO12345678'],
        'Greek id with its VAT prefix' => ['EL123456789', 'GR', 'EL123456789'],
        'Greek id with its country code' => ['GR123456789', 'RO', 'EL123456789'],
        'Northern Ireland on a GB address' => ['XI123456789', 'GB', 'XI123456789'],
        'foreign registration kept' => ['DE123456789', 'RO', 'DE123456789'],
        'bare German id' => ['123456789', 'DE', 'DE123456789'],
    ]);
});

describe('canonical', function () {
    it('reads a CUI or CNP into its bare digits, or null when the shape is wrong', function (string|int $input, ?string $expected) {
        expect(VatNumberValidator::canonical($input))->toBe($expected);
    })->with([
        'spaces and prefix' => ['RO 1234 5678', '12345678'],
        'padded lowercase prefix' => [' ro12345678 ', '12345678'],
        'an int' => [18547290, '18547290'],
        'a CNP' => ['1900101123457', '1900101123457'],
        'the zero CNP' => ['0000000000000', '0000000000000'],
        'a leading zero' => ['0123', null],
        'prefix only' => ['RO', null],
        'blank' => [' ', null],
        'eleven digits' => ['12345678901', null],
        'a zero-led 13 digits' => ['0000000000012', null],
        'a negative int' => ['-5', null],
    ]);
});

describe('isValid', function () {
    it('refuses a leading zero and reads spaces and a prefixed CNP', function (string $input, bool $expected) {
        expect(VatNumberValidator::isValid($input))->toBe($expected);
    })->with([
        'two zeros' => ['00', false],
        'prefixed zeros' => ['RO0000000000', false],
        'a leading zero' => ['014399840', false],
        'spaced' => ['RO 1439 9840', true],
        'prefixed CNP' => ['RO1900101123457', true],
        'the zero CNP' => ['0000000000000', true],
    ]);

    it('validates CUI with correct checksum', function () {
        // Known valid Romanian CUIs
        expect(VatNumberValidator::isValid('18547290'))->toBeTrue();
        expect(VatNumberValidator::isValid('RO18547290'))->toBeTrue();
        expect(VatNumberValidator::isValid('ro18547290'))->toBeTrue();
    });

    it('validates CUIs of various lengths', function () {
        // Known valid Romanian CUIs - shorter ones
        // CUI 21 has valid checksum using control key 753217532
        // Padded: 000000002, Control: 753217532
        // Products: 0,0,0,0,0,0,0,0,2*2=4, Sum=4, 4*10=40, 40%11=7, last digit should be 7
        // So 27 would be valid, not 21
        expect(VatNumberValidator::isValid('27'))->toBeTrue();
    });

    it('returns false for invalid checksum', function () {
        expect(VatNumberValidator::isValid('18547291'))->toBeFalse();
        expect(VatNumberValidator::isValid('RO18547291'))->toBeFalse();
    });

    it('returns false for empty string', function () {
        expect(VatNumberValidator::isValid(''))->toBeFalse();
    });

    it('returns false for invalid format', function () {
        expect(VatNumberValidator::isValid('ABC123'))->toBeFalse();
        expect(VatNumberValidator::isValid('1'))->toBeFalse(); // Too short
        expect(VatNumberValidator::isValid('12345678901'))->toBeFalse(); // Too long (11 digits)
    });

    it('validates valid CNP as VAT code', function () {
        // Testing that isValid delegates to CnpValidator for 13-digit numbers
        expect(VatNumberValidator::isValid('1900101123457'))->toBeTrue();
        expect(VatNumberValidator::isValid('0000000000000'))->toBeTrue(); // ANAF special case
    });

    it('returns false for invalid 13-digit CNP', function () {
        // 13-digit numbers are treated as CNPs - if CNP validation fails, isValid returns false
        // This ensures the fix where we check CNP validity for 13-digit numbers works
        expect(VatNumberValidator::isValid('1900101123458'))->toBeFalse(); // Invalid CNP checksum
        expect(VatNumberValidator::isValid('9999999999996'))->toBeFalse(); // Invalid CNP (invalid month 99)
    });
});

describe('isValidFormat', function () {
    it('validates format without checksum', function () {
        expect(VatNumberValidator::isValidFormat('12345678'))->toBeTrue();
        expect(VatNumberValidator::isValidFormat('RO12345678'))->toBeTrue();
        expect(VatNumberValidator::isValidFormat('ro12345678'))->toBeTrue();
    });

    it('accepts 2-10 digit numbers', function () {
        expect(VatNumberValidator::isValidFormat('12'))->toBeTrue();
        expect(VatNumberValidator::isValidFormat('1234567890'))->toBeTrue();
    });

    it('validates 13-digit CNP format', function () {
        expect(VatNumberValidator::isValidFormat('1234567890123'))->toBeTrue();
    });

    it('returns false for invalid formats', function () {
        expect(VatNumberValidator::isValidFormat('0012345678'))->toBeFalse();
        expect(VatNumberValidator::isValidFormat('0123456789012'))->toBeFalse();
        expect(VatNumberValidator::isValidFormat(''))->toBeFalse();
        expect(VatNumberValidator::isValidFormat('1'))->toBeFalse();
        expect(VatNumberValidator::isValidFormat('ABC'))->toBeFalse();
    });
});

describe('normalize', function () {
    it('adds RO prefix if missing', function () {
        expect(VatNumberValidator::normalize('12345678'))->toBe('RO12345678');
    });

    it('keeps existing RO prefix', function () {
        expect(VatNumberValidator::normalize('RO12345678'))->toBe('RO12345678');
    });

    it('normalizes lowercase ro prefix to uppercase', function () {
        expect(VatNumberValidator::normalize('ro12345678'))->toBe('RO12345678');
    });

    it('keeps CNP unchanged (no RO prefix)', function () {
        expect(VatNumberValidator::normalize('0000000000000'))->toBe('0000000000000');
    });

    it('returns a prefixed CNP bare and drops spaces from a CUI', function () {
        expect(VatNumberValidator::normalize('RO1900101123457'))->toBe('1900101123457');
        expect(VatNumberValidator::normalize(' ro 1439 9840 '))->toBe('RO14399840');
    });

    it('throws exception for a leading zero', function () {
        VatNumberValidator::normalize('014399840');
    })->throws(InvalidArgumentException::class, 'Invalid VAT number format');

    it('throws exception for empty string', function () {
        VatNumberValidator::normalize('');
    })->throws(InvalidArgumentException::class, 'Company VAT number is missing');

    it('throws exception for whitespace only', function () {
        VatNumberValidator::normalize('   ');
    })->throws(InvalidArgumentException::class, 'Company VAT number is missing');

    it('throws exception for invalid format', function () {
        VatNumberValidator::normalize('ABC123');
    })->throws(InvalidArgumentException::class, 'Invalid VAT number format');

    it('throws exception for single digit (too short)', function () {
        VatNumberValidator::normalize('5');
    })->throws(InvalidArgumentException::class, 'Invalid VAT number format');

    it('throws exception for 11 digits (too long for CUI, too short for CNP)', function () {
        VatNumberValidator::normalize('12345678901');
    })->throws(InvalidArgumentException::class, 'Invalid VAT number format');

    it('throws exception for 12 digits (invalid length)', function () {
        VatNumberValidator::normalize('123456789012');
    })->throws(InvalidArgumentException::class, 'Invalid VAT number format');

    it('throws exception for 13 digits with invalid CNP checksum', function () {
        // Bug fix: 13-digit numbers with invalid CNP checksum should not get RO prefix
        // Instead, they should throw an exception
        VatNumberValidator::normalize('1900101123458');
    })->throws(InvalidArgumentException::class, 'Invalid CNP');

    it('throws exception for 13 digits with invalid date in CNP', function () {
        // 13 digits starting with valid sex digit but invalid month (99)
        VatNumberValidator::normalize('1999913123459');
    })->throws(InvalidArgumentException::class, 'Invalid CNP');
});

describe('stripPrefix', function () {
    it('removes RO prefix', function () {
        expect(VatNumberValidator::stripPrefix('RO12345678'))->toBe('12345678');
    });

    it('removes lowercase ro prefix', function () {
        expect(VatNumberValidator::stripPrefix('ro12345678'))->toBe('12345678');
    });

    it('removes a prefix followed by spaces', function () {
        expect(VatNumberValidator::stripPrefix('RO 14399840'))->toBe('14399840');
        expect(VatNumberValidator::stripPrefix(' ro 14399840 '))->toBe('14399840');
    });

    it('returns as-is if no prefix', function () {
        expect(VatNumberValidator::stripPrefix('12345678'))->toBe('12345678');
    });

    it('throws exception for empty string', function () {
        VatNumberValidator::stripPrefix('');
    })->throws(InvalidArgumentException::class, 'Company VAT number is missing');
});

describe('CUI checksum algorithm', function () {
    it('correctly validates checksum using control key 753217532', function () {
        // Testing the algorithm:
        // CUI: 18547290
        // Without check digit: 1854729
        // Padded to 9: 001854729
        // Control key:         753217532
        // Products: 0*7=0, 0*5=0, 1*3=3, 8*2=16, 5*1=5, 4*7=28, 7*5=35, 2*3=6, 9*2=18
        // Sum: 0+0+3+16+5+28+35+6+18 = 111
        // 111 * 10 = 1110
        // 1110 % 11 = 10 -> check digit = 0
        // Last digit of 18547290 is 0 -> Valid!
        expect(VatNumberValidator::isValid('18547290'))->toBeTrue();

        // Invalid: changing last digit
        expect(VatNumberValidator::isValid('18547291'))->toBeFalse();
    });
});
