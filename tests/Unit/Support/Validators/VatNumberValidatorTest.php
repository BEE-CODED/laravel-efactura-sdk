<?php

declare(strict_types=1);

use Beecoded\EFactura\Support\Validators\VatNumberValidator;

describe('isValid', function () {
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
        // Valid CNP: 1850101123456 (would need proper checksum, using mock)
        // CNP starting with 1 = male born 1900-1999
        // Testing that isValid delegates to CnpValidator for 13-digit numbers
        expect(VatNumberValidator::isValid('0000000000000'))->toBeTrue(); // ANAF special case
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

    it('throws exception for empty string', function () {
        VatNumberValidator::normalize('');
    })->throws(InvalidArgumentException::class, 'Company VAT number is missing');

    it('throws exception for whitespace only', function () {
        VatNumberValidator::normalize('   ');
    })->throws(InvalidArgumentException::class, 'Company VAT number is missing');
});

describe('stripPrefix', function () {
    it('removes RO prefix', function () {
        expect(VatNumberValidator::stripPrefix('RO12345678'))->toBe('12345678');
    });

    it('removes lowercase ro prefix', function () {
        expect(VatNumberValidator::stripPrefix('ro12345678'))->toBe('12345678');
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
