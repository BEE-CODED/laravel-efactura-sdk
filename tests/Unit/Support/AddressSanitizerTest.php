<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\AddressSanitizer;

describe('canonicalize', function () {
    it('returns the county and city a UBL address files', function (array $address, array $expected) {
        expect(AddressSanitizer::canonicalize(new AddressData('Str. 1', ...$address)))->toBe($expected);
    })->with([
        'RO county name' => [['city' => 'Cluj-Napoca', 'county' => 'Cluj'], ['county' => 'RO-CJ', 'city' => 'Cluj-Napoca']],
        'explicit sector wins' => [['city' => 'Bucuresti', 'county' => 'Municipiul Bucuresti', 'bucharestSector' => 5], ['county' => 'RO-B', 'city' => 'SECTOR5']],
        'sector in the city' => [['city' => 'Sectorul 2', 'county' => 'B', 'bucharestSector' => 5], ['county' => 'RO-B', 'city' => 'SECTOR5']],
        'sector in the county' => [['city' => 'Bucuresti', 'county' => 'Sector 6'], ['county' => 'RO-B', 'city' => 'SECTOR6']],
        'foreign B kept' => [['city' => 'Barcelona', 'county' => 'B', 'countryCode' => 'ES'], ['county' => 'B', 'city' => 'Barcelona']],
        'foreign sector kept' => [['city' => 'Chisinau', 'county' => 'Sect. 4', 'countryCode' => 'MD'], ['county' => 'Sect. 4', 'city' => 'Chisinau']],
        'foreign without county' => [['city' => 'Berlin', 'countryCode' => 'DE'], ['county' => null, 'city' => 'Berlin']],
        'foreign empty county' => [['city' => 'Berlin', 'county' => '', 'countryCode' => 'DE'], ['county' => null, 'city' => 'Berlin']],
        'foreign blank county' => [['city' => 'Berlin', 'county' => '  ', 'countryCode' => 'DE'], ['county' => null, 'city' => 'Berlin']],
        'NFD county' => [['city' => 'Brasov', 'county' => "Bras\u{0326}ov"], ['county' => 'RO-BV', 'city' => 'Brasov']],
    ]);

    it('refuses what ANAF would reject or what contradicts itself', function (array $address, string $message) {
        expect(fn () => AddressSanitizer::canonicalize(new AddressData('Str. 1', ...$address)))
            ->toThrow(ValidationException::class, $message);
    })->with([
        'Bucharest without a sector' => [['city' => 'Bucuresti', 'county' => 'Bucuresti'], 'needs its sector'],
        'unknown RO county' => [['city' => 'X', 'county' => 'Atlantis'], 'could not be mapped'],
        'sector out of range' => [['city' => 'Bucuresti', 'county' => 'B', 'bucharestSector' => 7], 'bucharestSector must be 1 to 6'],
        'sector outside Bucharest' => [['city' => 'Cluj-Napoca', 'county' => 'Cluj', 'bucharestSector' => 1], 'bucharestSector must be 1 to 6'],
        'sector on a foreign address' => [['city' => 'Paris', 'county' => 'RO-B', 'countryCode' => 'FR', 'bucharestSector' => 1], 'bucharestSector must be 1 to 6'],
    ]);
});

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
    it('maps a two-letter county code and a spaced hyphen or dash', function (string $county, string $expected) {
        expect(AddressSanitizer::sanitizeCounty($county))->toBe($expected);
    })->with([
        'plate code' => ['CJ', 'RO-CJ'],
        'lowercase plate code' => ['if', 'RO-IF'],
        'spaced hyphen' => ['Caras - Severin', 'RO-CS'],
        'spaced en dash' => ["Bistrita \u{2013} Nasaud", 'RO-BN'],
    ]);

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

    it('reads decomposed (NFD) diacritics', function () {
        expect(AddressSanitizer::sanitizeCounty("Baca\u{0306}u"))->toBe('RO-BC');
    });

    it('maps a selection of counties', function () {
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

    it('does not match partial strings containing Bucharest indicators', function () {
        // Regression test: exact matching prevents false positives
        // "BUCEGI" should not match just because it starts with "BUC"
        expect(AddressSanitizer::isBucharest('Bucegi'))->toBeFalse();
        expect(AddressSanitizer::isBucharest('BUCEGI'))->toBeFalse();
        // "Bucecea" is a town in Botoșani county
        expect(AddressSanitizer::isBucharest('Bucecea'))->toBeFalse();
        // "Bucium" is a commune in Alba county
        expect(AddressSanitizer::isBucharest('Bucium'))->toBeFalse();
        // Test that substring matching doesn't happen
        expect(AddressSanitizer::isBucharest('Bucurestii Noi'))->toBeFalse();
    });

    it('recognizes Bucharest sector patterns as Bucharest', function () {
        // County field may contain sector designations which should be recognized as Bucharest
        expect(AddressSanitizer::isBucharest('Sector 1'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sector 2'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sector 3'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sector 4'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sector 5'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sector 6'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('SECTOR 1'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sectorul 3'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('Sect. 2'))->toBeTrue();
        expect(AddressSanitizer::isBucharest('S. 4'))->toBeTrue();
    });

    it('does not recognize invalid sector numbers as Bucharest', function () {
        // Sectors 7+ don't exist in Bucharest
        expect(AddressSanitizer::isBucharest('Sector 7'))->toBeFalse();
        expect(AddressSanitizer::isBucharest('Sector 0'))->toBeFalse();
        expect(AddressSanitizer::isBucharest('Sector 10'))->toBeFalse();
    });
});

describe('getValidCountyCodes', function () {
    it('returns exactly the 42 ISO 3166-2:RO codes ANAF accepts', function () {
        $codes = array_values(AddressSanitizer::getValidCountyCodes());
        sort($codes);

        expect($codes)->toBe(['RO-AB', 'RO-AG', 'RO-AR', 'RO-B', 'RO-BC', 'RO-BH', 'RO-BN', 'RO-BR', 'RO-BT', 'RO-BV', 'RO-BZ', 'RO-CJ', 'RO-CL', 'RO-CS', 'RO-CT', 'RO-CV', 'RO-DB', 'RO-DJ', 'RO-GJ', 'RO-GL', 'RO-GR', 'RO-HD', 'RO-HR', 'RO-IF', 'RO-IL', 'RO-IS', 'RO-MH', 'RO-MM', 'RO-MS', 'RO-NT', 'RO-OT', 'RO-PH', 'RO-SB', 'RO-SJ', 'RO-SM', 'RO-SV', 'RO-TL', 'RO-TM', 'RO-TR', 'RO-VL', 'RO-VN', 'RO-VS']);
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
