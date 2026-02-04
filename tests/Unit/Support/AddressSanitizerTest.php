<?php

declare(strict_types=1);

use BeeCoded\EFactura\Support\AddressSanitizer;

describe('normalizeDiacritics', function () {
    it('converts lowercase Romanian diacritics', function () {
        expect(AddressSanitizer::normalizeDiacritics('București'))->toBe('Bucuresti');
        expect(AddressSanitizer::normalizeDiacritics('Brașov'))->toBe('Brasov');
        expect(AddressSanitizer::normalizeDiacritics('Timișoara'))->toBe('Timisoara');
        expect(AddressSanitizer::normalizeDiacritics('Iași'))->toBe('Iasi');
    });

    it('converts uppercase Romanian diacritics', function () {
        expect(AddressSanitizer::normalizeDiacritics('BUCUREȘTI'))->toBe('BUCURESTI');
        expect(AddressSanitizer::normalizeDiacritics('BRAȘOV'))->toBe('BRASOV');
    });

    it('handles mixed text', function () {
        expect(AddressSanitizer::normalizeDiacritics('Strada Ștefan cel Mare, București'))->toBe('Strada Stefan cel Mare, Bucuresti');
    });

    it('preserves non-diacritic characters', function () {
        expect(AddressSanitizer::normalizeDiacritics('Hello World 123'))->toBe('Hello World 123');
    });
});

describe('sanitizeCounty', function () {
    it('converts county names to ISO 3166-2:RO codes', function () {
        expect(AddressSanitizer::sanitizeCounty('București'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeCounty('Bucuresti'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeCounty('Cluj'))->toBe('RO-CJ');
        expect(AddressSanitizer::sanitizeCounty('Timis'))->toBe('RO-TM');
        expect(AddressSanitizer::sanitizeCounty('Iasi'))->toBe('RO-IS');
    });

    it('handles JUDETUL prefix', function () {
        expect(AddressSanitizer::sanitizeCounty('Judetul Cluj'))->toBe('RO-CJ');
        expect(AddressSanitizer::sanitizeCounty('JUDETUL TIMIS'))->toBe('RO-TM');
    });

    it('handles JUD. prefix', function () {
        expect(AddressSanitizer::sanitizeCounty('Jud. Cluj'))->toBe('RO-CJ');
    });

    it('handles MUN. prefix', function () {
        expect(AddressSanitizer::sanitizeCounty('Mun. Bucuresti'))->toBe('RO-B');
    });

    it('is case insensitive', function () {
        expect(AddressSanitizer::sanitizeCounty('CLUJ'))->toBe('RO-CJ');
        expect(AddressSanitizer::sanitizeCounty('cluj'))->toBe('RO-CJ');
        expect(AddressSanitizer::sanitizeCounty('Cluj'))->toBe('RO-CJ');
    });

    it('returns null for unknown counties', function () {
        expect(AddressSanitizer::sanitizeCounty('UnknownCounty'))->toBeNull();
        expect(AddressSanitizer::sanitizeCounty(''))->toBeNull();
    });

    it('handles all Romanian counties', function () {
        // Test a selection of counties
        expect(AddressSanitizer::sanitizeCounty('Alba'))->toBe('RO-AB');
        expect(AddressSanitizer::sanitizeCounty('Arad'))->toBe('RO-AR');
        expect(AddressSanitizer::sanitizeCounty('Arges'))->toBe('RO-AG');
        expect(AddressSanitizer::sanitizeCounty('Bacau'))->toBe('RO-BC');
        expect(AddressSanitizer::sanitizeCounty('Bihor'))->toBe('RO-BH');
        expect(AddressSanitizer::sanitizeCounty('Constanta'))->toBe('RO-CT');
        expect(AddressSanitizer::sanitizeCounty('Dolj'))->toBe('RO-DJ');
        expect(AddressSanitizer::sanitizeCounty('Prahova'))->toBe('RO-PH');
        expect(AddressSanitizer::sanitizeCounty('Sibiu'))->toBe('RO-SB');
        expect(AddressSanitizer::sanitizeCounty('Valcea'))->toBe('RO-VL');
        expect(AddressSanitizer::sanitizeCounty('Vilcea'))->toBe('RO-VL'); // Common typo
    });

    it('handles compound county names', function () {
        expect(AddressSanitizer::sanitizeCounty('Bistrita Nasaud'))->toBe('RO-BN');
        expect(AddressSanitizer::sanitizeCounty('Bistrita-Nasaud'))->toBe('RO-BN');
        expect(AddressSanitizer::sanitizeCounty('Caras Severin'))->toBe('RO-CS');
        expect(AddressSanitizer::sanitizeCounty('Satu Mare'))->toBe('RO-SM');
    });
});

describe('sanitizeBucharestSector', function () {
    it('extracts sector and returns RO-B', function () {
        expect(AddressSanitizer::sanitizeBucharestSector('Sector 1'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeBucharestSector('Sector 3'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeBucharestSector('Sector 6'))->toBe('RO-B');
    });

    it('handles various sector formats', function () {
        expect(AddressSanitizer::sanitizeBucharestSector('SECTOR 1'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeBucharestSector('Sectorul 2'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeBucharestSector('Sect. 3'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeBucharestSector('S. 4'))->toBe('RO-B');
    });

    it('returns RO-B for Bucharest without sector', function () {
        expect(AddressSanitizer::sanitizeBucharestSector('Bucuresti'))->toBe('RO-B');
        expect(AddressSanitizer::sanitizeBucharestSector('BUCURESTI'))->toBe('RO-B');
    });

    it('returns null for non-Bucharest addresses', function () {
        expect(AddressSanitizer::sanitizeBucharestSector('Cluj'))->toBeNull();
        expect(AddressSanitizer::sanitizeBucharestSector('Strada Ion'))->toBeNull();
    });

    it('ignores invalid sector numbers', function () {
        expect(AddressSanitizer::sanitizeBucharestSector('Sector 7'))->toBeNull();
        expect(AddressSanitizer::sanitizeBucharestSector('Sector 0'))->toBeNull();
    });
});

describe('extractBucharestSectorNumber', function () {
    it('returns sector number 1-6', function () {
        expect(AddressSanitizer::extractBucharestSectorNumber('Sector 1'))->toBe(1);
        expect(AddressSanitizer::extractBucharestSectorNumber('Sector 3'))->toBe(3);
        expect(AddressSanitizer::extractBucharestSectorNumber('Sectorul 6'))->toBe(6);
    });

    it('returns null for invalid sectors', function () {
        expect(AddressSanitizer::extractBucharestSectorNumber('Sector 7'))->toBeNull();
        expect(AddressSanitizer::extractBucharestSectorNumber('Sector 0'))->toBeNull();
        expect(AddressSanitizer::extractBucharestSectorNumber('No sector'))->toBeNull();
    });
});

describe('isBucharest', function () {
    it('returns true for Bucharest variations', function () {
        expect(AddressSanitizer::isBucharest('București'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Bucuresti'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('BUCURESTI'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Buc'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('B'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Municipiul Bucuresti'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('RO-B'))->toBeTrue();
    });

    it('returns false for non-Bucharest', function () {
        expect(AddressSanitizer::isBucharest('Cluj'))->toBeFalse();
        expect(AddressSanitizer::isBucharest('Timisoara'))->toBeFalse();
        expect(AddressSanitizer::isBucharest(''))->toBeFalse();
    });
});

describe('getValidCountyCodes', function () {
    it('returns array of unique ISO codes', function () {
        $codes = AddressSanitizer::getValidCountyCodes();

        expect($codes)->toBeArray();
        expect($codes)->toContain('RO-B');
        expect($codes)->toContain('RO-CJ');
        expect($codes)->toContain('RO-TM');
        expect(count($codes))->toBe(count(array_unique($codes)));
    });
});

describe('isValidCountyCode', function () {
    it('returns true for valid ISO codes', function () {
        expect(AddressSanitizer::isValidCountyCode('RO-B'))->toBeTrue();
        expect(AddressSanitizer::isValidCountyCode('RO-CJ'))->toBeTrue();
        expect(AddressSanitizer::isValidCountyCode('RO-TM'))->toBeTrue();
    });

    it('returns false for invalid codes', function () {
        expect(AddressSanitizer::isValidCountyCode('RO-XX'))->toBeFalse();
        expect(AddressSanitizer::isValidCountyCode('B'))->toBeFalse();
        expect(AddressSanitizer::isValidCountyCode(''))->toBeFalse();
    });
});
