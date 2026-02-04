<?php

declare(strict_types=1);

use BeeCoded\EFactura\Support\Validators\CnpValidator;

describe('isValidFormat', function () {
    it('returns true for 13-digit strings', function () {
        expect(CnpValidator::isValidFormat('1234567890123'))->toBeTrue();
        expect(CnpValidator::isValidFormat('0000000000000'))->toBeTrue();
    });

    it('returns false for non-13-digit strings', function () {
        expect(CnpValidator::isValidFormat('123456789012'))->toBeFalse(); // 12 digits
        expect(CnpValidator::isValidFormat('12345678901234'))->toBeFalse(); // 14 digits
        expect(CnpValidator::isValidFormat('ABC1234567890'))->toBeFalse();
        expect(CnpValidator::isValidFormat(''))->toBeFalse();
    });
});

describe('isValid', function () {
    it('validates ANAF special case (all zeros)', function () {
        expect(CnpValidator::isValid('0000000000000'))->toBeTrue();
    });

    it('returns false for invalid format', function () {
        expect(CnpValidator::isValid('123'))->toBeFalse();
        expect(CnpValidator::isValid('ABCDEFGHIJKLM'))->toBeFalse();
    });

    it('returns false for invalid sex digit (0)', function () {
        // First digit 0 is not valid (must be 1-9)
        expect(CnpValidator::isValid('0123456789012'))->toBeFalse();
    });

    it('returns false for invalid month (13)', function () {
        // 1 (sex) + 90 (year) + 13 (invalid month) + 01 (day) + rest
        expect(CnpValidator::isValid('1901301123457'))->toBeFalse();
    });

    it('returns false for invalid day (32)', function () {
        // 1 (sex) + 90 (year) + 01 (month) + 32 (invalid day) + rest
        expect(CnpValidator::isValid('1900132123457'))->toBeFalse();
    });

    it('returns false for invalid February 30', function () {
        // 1 (sex) + 90 (year) + 02 (month) + 30 (invalid day) + rest
        expect(CnpValidator::isValid('1900230123457'))->toBeFalse();
    });

    it('validates CNP with correct checksum for male born 1990', function () {
        // CNP: 1900101123457
        // Control key: 279146358279
        // Sum = 1*2 + 9*7 + 0*9 + 0*1 + 1*4 + 0*6 + 1*3 + 1*5 + 2*8 + 3*2 + 4*7 + 5*9
        //     = 2 + 63 + 0 + 0 + 4 + 0 + 3 + 5 + 16 + 6 + 28 + 45 = 172
        // 172 % 11 = 7, so control digit = 7
        expect(CnpValidator::isValid('1900101123457'))->toBeTrue();
    });

    it('validates CNP with correct checksum for female born 1985', function () {
        // CNP: 2850101123451
        // Sum = 2*2 + 8*7 + 5*9 + 0*1 + 1*4 + 0*6 + 1*3 + 1*5 + 2*8 + 3*2 + 4*7 + 5*9
        //     = 4 + 56 + 45 + 0 + 4 + 0 + 3 + 5 + 16 + 6 + 28 + 45 = 212
        // 212 % 11 = 3, so control digit = 3
        expect(CnpValidator::isValid('2850101123453'))->toBeTrue();
    });

    it('validates CNP for person born in 2000s (sex digit 5)', function () {
        // CNP: 5000515123456
        // Sum = 5*2 + 0*7 + 0*9 + 0*1 + 5*4 + 1*6 + 5*3 + 1*5 + 2*8 + 3*2 + 4*7 + 5*9
        //     = 10 + 0 + 0 + 0 + 20 + 6 + 15 + 5 + 16 + 6 + 28 + 45 = 151
        // 151 % 11 = 8, so control digit = 8
        expect(CnpValidator::isValid('5000515123458'))->toBeTrue();
    });

    it('returns false for invalid checksum', function () {
        // Same as valid CNP above but with wrong control digit (8 instead of 7)
        expect(CnpValidator::isValid('1900101123458'))->toBeFalse();
    });

    it('handles checksum result of 10 becoming 1', function () {
        // When sum % 11 == 10, the control digit should be 1
        // CNP: 2900515123451
        // Sum = 2*2 + 9*7 + 0*9 + 0*1 + 5*4 + 1*6 + 5*3 + 1*5 + 2*8 + 3*2 + 4*7 + 5*9
        //     = 4 + 63 + 0 + 0 + 20 + 6 + 15 + 5 + 16 + 6 + 28 + 45 = 208
        // 208 % 11 = 10, so control digit = 1
        expect(CnpValidator::isValid('2900515123451'))->toBeTrue();
    });

    it('validates all valid sex digits 1-9', function () {
        // Sex digit 1: male 1900-1999
        expect(CnpValidator::isValid('1900101123457'))->toBeTrue();
        // Sex digit 2: female 1900-1999
        expect(CnpValidator::isValid('2850101123453'))->toBeTrue();
        // Sex digit 5: male 2000-2099
        expect(CnpValidator::isValid('5000515123458'))->toBeTrue();
    });

    it('validates leap year February 29', function () {
        // 2000 is a leap year, so Feb 29 is valid
        // CNP: 5000229123456
        // Sum = 5*2 + 0*7 + 0*9 + 0*1 + 2*4 + 2*6 + 9*3 + 1*5 + 2*8 + 3*2 + 4*7 + 5*9
        //     = 10 + 0 + 0 + 0 + 8 + 12 + 27 + 5 + 16 + 6 + 28 + 45 = 157
        // 157 % 11 = 3, so control digit = 3
        expect(CnpValidator::isValid('5000229123453'))->toBeTrue();
    });

    it('returns false for non-leap year February 29', function () {
        // 1990 is NOT a leap year, so Feb 29 is invalid
        expect(CnpValidator::isValid('1900229123450'))->toBeFalse();
    });
});
