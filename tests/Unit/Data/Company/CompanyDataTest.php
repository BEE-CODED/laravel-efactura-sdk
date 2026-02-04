<?php

declare(strict_types=1);

use Beecoded\EFactura\Data\Company\CompanyData;
use Beecoded\EFactura\Data\Company\CompanyLookupResultData;
use Beecoded\EFactura\Data\Company\SplitVatData;
use Beecoded\EFactura\Data\Company\VatRegistrationData;
use Carbon\Carbon;

describe('CompanyData', function () {
    it('creates from ANAF response', function () {
        $response = [
            'date_generale' => [
                'cui' => '12345678',
                'denumire' => 'Test Company SRL',
                'adresa' => 'Str. Test 1, Bucuresti',
                'telefon' => '0212345678',
                'codPostal' => '010101',
                'nrRegCom' => 'J40/1234/2020',
            ],
            'inregistrare_scop_Tva' => [
                'scpTVA' => true,
                'data_inceput_ScpTVA' => '2020-01-01',
            ],
            'stare_inactiv' => [
                'statusInactivi' => false,
            ],
        ];

        $company = CompanyData::fromAnafResponse($response);

        expect($company->cui)->toBe('12345678');
        expect($company->name)->toBe('Test Company SRL');
        expect($company->address)->toBe('Str. Test 1, Bucuresti');
        expect($company->phone)->toBe('0212345678');
        expect($company->postalCode)->toBe('010101');
        expect($company->registrationNumber)->toBe('J40/1234/2020');
        expect($company->isVatPayer)->toBeTrue();
        expect($company->isInactive)->toBeFalse();
    });

    it('handles empty response', function () {
        $company = CompanyData::fromAnafResponse([]);

        expect($company->cui)->toBe('');
        expect($company->name)->toBe('');
        expect($company->isVatPayer)->toBeFalse();
    });

    it('parses VAT registration dates', function () {
        $response = [
            'date_generale' => [
                'cui' => '12345678',
                'denumire' => 'Test',
            ],
            'inregistrare_scop_Tva' => [
                'scpTVA' => true,
                'data_inceput_ScpTVA' => '2020-01-15',
                'data_sfarsit_ScpTVA' => '2023-06-30',
            ],
        ];

        $company = CompanyData::fromAnafResponse($response);

        expect($company->vatRegistrationDate)->toBeInstanceOf(Carbon::class);
        expect($company->vatRegistrationDate->format('Y-m-d'))->toBe('2020-01-15');
        expect($company->vatDeregistrationDate)->toBeInstanceOf(Carbon::class);
    });

    describe('getVatNumber', function () {
        it('returns CUI with RO prefix', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => '12345678',
                    'denumire' => 'Test',
                ],
            ]);

            expect($company->getVatNumber())->toBe('RO12345678');
        });
    });

    describe('isActive', function () {
        it('returns true when not inactive and not deregistered', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'stare_inactiv' => ['statusInactivi' => false],
            ]);

            expect($company->isActive())->toBeTrue();
        });

        it('returns false when inactive', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'stare_inactiv' => ['statusInactivi' => true],
            ]);

            expect($company->isActive())->toBeFalse();
        });

        it('returns false when deregistered', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'stare_inactiv' => ['dataRadiere' => '2023-01-01'],
            ]);

            expect($company->isActive())->toBeFalse();
        });
    });

    describe('getPrimaryAddress', function () {
        it('returns headquarters address when available', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'adresa_sediu_social' => [
                    'sdenumire_Strada' => 'Str. Test',
                    'snumar_Strada' => '1',
                    'sdenumire_Localitate' => 'Bucuresti',
                    'scod_Postal' => '010101',
                    'sdenumire_Judet' => 'Bucuresti',
                ],
            ]);

            $address = $company->getPrimaryAddress();

            expect($address)->not->toBeNull();
        });

        it('returns null when no address available', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);

            expect($company->getPrimaryAddress())->toBeNull();
        });
    });
});

describe('CompanyLookupResultData', function () {
    it('creates successful result with companies', function () {
        $company = CompanyData::fromAnafResponse([
            'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
        ]);

        $result = CompanyLookupResultData::success([$company]);

        expect($result->success)->toBeTrue();
        expect($result->companies)->toHaveCount(1);
        expect($result->notFound)->toBe([]);
        expect($result->invalidCodes)->toBe([]);
        expect($result->error)->toBeNull();
    });

    it('creates successful result with not found CUIs', function () {
        $result = CompanyLookupResultData::success([], [99999999, 88888888]);

        expect($result->success)->toBeTrue();
        expect($result->notFound)->toBe([99999999, 88888888]);
        expect($result->hasNotFound())->toBeTrue();
    });

    it('creates successful result with invalid codes', function () {
        $result = CompanyLookupResultData::success([], [], ['ABC123', 'INVALID']);

        expect($result->invalidCodes)->toBe(['ABC123', 'INVALID']);
        expect($result->hasInvalidCodes())->toBeTrue();
    });

    it('creates failed result with error', function () {
        $result = CompanyLookupResultData::failure('API Error');

        expect($result->success)->toBeFalse();
        expect($result->error)->toBe('API Error');
    });

    it('creates failed result with invalid codes', function () {
        $result = CompanyLookupResultData::failure('Validation error', ['BAD-CODE']);

        expect($result->success)->toBeFalse();
        expect($result->invalidCodes)->toBe(['BAD-CODE']);
    });

    describe('first()', function () {
        it('returns first company', function () {
            $company1 = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '11111111', 'denumire' => 'Company 1'],
            ]);
            $company2 = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '22222222', 'denumire' => 'Company 2'],
            ]);

            $result = CompanyLookupResultData::success([$company1, $company2]);

            expect($result->first()->cui)->toBe('11111111');
        });

        it('returns null when no companies', function () {
            $result = CompanyLookupResultData::success([]);

            expect($result->first())->toBeNull();
        });
    });

    describe('hasCompanies()', function () {
        it('returns true when companies exist', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);

            $result = CompanyLookupResultData::success([$company]);

            expect($result->hasCompanies())->toBeTrue();
        });

        it('returns false when no companies', function () {
            $result = CompanyLookupResultData::success([]);

            expect($result->hasCompanies())->toBeFalse();
        });
    });

    describe('getByCui()', function () {
        it('finds company by CUI', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);

            $result = CompanyLookupResultData::success([$company]);

            expect($result->getByCui('12345678')->name)->toBe('Test');
        });

        it('finds company with RO prefix', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);

            $result = CompanyLookupResultData::success([$company]);

            expect($result->getByCui('RO12345678'))->not->toBeNull();
            expect($result->getByCui('ro12345678'))->not->toBeNull();
        });

        it('returns null when not found', function () {
            $result = CompanyLookupResultData::success([]);

            expect($result->getByCui('99999999'))->toBeNull();
        });
    });
});

describe('SplitVatData', function () {
    it('creates with default values', function () {
        $splitVat = new SplitVatData;

        expect($splitVat->isActive)->toBeFalse();
        expect($splitVat->startDate)->toBeNull();
        expect($splitVat->cancelDate)->toBeNull();
    });

    it('creates with all values', function () {
        $startDate = Carbon::create(2020, 1, 1);
        $cancelDate = Carbon::create(2023, 6, 30);

        $splitVat = new SplitVatData(
            isActive: true,
            startDate: $startDate,
            cancelDate: $cancelDate,
        );

        expect($splitVat->isActive)->toBeTrue();
        expect($splitVat->startDate)->toBe($startDate);
        expect($splitVat->cancelDate)->toBe($cancelDate);
    });

    describe('fromAnafResponse', function () {
        it('parses active split VAT status', function () {
            $response = [
                'statusSplitTVA' => true,
                'dataInceputSplitTVA' => '2020-01-15',
            ];

            $splitVat = SplitVatData::fromAnafResponse($response);

            expect($splitVat->isActive)->toBeTrue();
            expect($splitVat->startDate)->toBeInstanceOf(Carbon::class);
            expect($splitVat->startDate->format('Y-m-d'))->toBe('2020-01-15');
        });

        it('parses cancelled split VAT status', function () {
            $response = [
                'statusSplitTVA' => false,
                'dataInceputSplitTVA' => '2020-01-15',
                'dataAnulareSplitTVA' => '2023-06-30',
            ];

            $splitVat = SplitVatData::fromAnafResponse($response);

            expect($splitVat->isActive)->toBeFalse();
            expect($splitVat->cancelDate)->toBeInstanceOf(Carbon::class);
            expect($splitVat->cancelDate->format('Y-m-d'))->toBe('2023-06-30');
        });

        it('handles empty response', function () {
            $splitVat = SplitVatData::fromAnafResponse([]);

            expect($splitVat->isActive)->toBeFalse();
            expect($splitVat->startDate)->toBeNull();
        });

        it('handles empty date strings', function () {
            $splitVat = SplitVatData::fromAnafResponse([
                'dataInceputSplitTVA' => '',
                'dataAnulareSplitTVA' => '   ',
            ]);

            expect($splitVat->startDate)->toBeNull();
            expect($splitVat->cancelDate)->toBeNull();
        });

        it('handles invalid date strings', function () {
            $splitVat = SplitVatData::fromAnafResponse([
                'dataInceputSplitTVA' => 'not-a-date',
            ]);

            expect($splitVat->startDate)->toBeNull();
        });
    });
});

describe('VatRegistrationData', function () {
    it('creates with default values', function () {
        $vatReg = new VatRegistrationData;

        expect($vatReg->isActive)->toBeFalse();
        expect($vatReg->startDate)->toBeNull();
        expect($vatReg->endDate)->toBeNull();
        expect($vatReg->updateDate)->toBeNull();
        expect($vatReg->publishDate)->toBeNull();
        expect($vatReg->actType)->toBeNull();
    });

    it('creates with all values', function () {
        $startDate = Carbon::create(2020, 1, 1);
        $endDate = Carbon::create(2023, 12, 31);

        $vatReg = new VatRegistrationData(
            isActive: true,
            startDate: $startDate,
            endDate: $endDate,
            actType: 'HOTARARE',
        );

        expect($vatReg->isActive)->toBeTrue();
        expect($vatReg->startDate)->toBe($startDate);
        expect($vatReg->endDate)->toBe($endDate);
        expect($vatReg->actType)->toBe('HOTARARE');
    });

    describe('fromAnafResponse', function () {
        it('parses active TVA incasare status', function () {
            $response = [
                'statusTvaIncasare' => true,
                'dataInceputTvaInc' => '2020-01-15',
                'tipActTvaInc' => 'HOTARARE',
            ];

            $vatReg = VatRegistrationData::fromAnafResponse($response);

            expect($vatReg->isActive)->toBeTrue();
            expect($vatReg->startDate)->toBeInstanceOf(Carbon::class);
            expect($vatReg->startDate->format('Y-m-d'))->toBe('2020-01-15');
            expect($vatReg->actType)->toBe('HOTARARE');
        });

        it('parses all date fields', function () {
            $response = [
                'statusTvaIncasare' => true,
                'dataInceputTvaInc' => '2020-01-15',
                'dataSfarsitTvaInc' => '2023-06-30',
                'dataActualizareTvaInc' => '2023-07-01',
                'dataPublicareTvaInc' => '2020-01-20',
            ];

            $vatReg = VatRegistrationData::fromAnafResponse($response);

            expect($vatReg->startDate->format('Y-m-d'))->toBe('2020-01-15');
            expect($vatReg->endDate->format('Y-m-d'))->toBe('2023-06-30');
            expect($vatReg->updateDate->format('Y-m-d'))->toBe('2023-07-01');
            expect($vatReg->publishDate->format('Y-m-d'))->toBe('2020-01-20');
        });

        it('handles empty response', function () {
            $vatReg = VatRegistrationData::fromAnafResponse([]);

            expect($vatReg->isActive)->toBeFalse();
            expect($vatReg->startDate)->toBeNull();
            expect($vatReg->actType)->toBeNull();
        });

        it('handles empty date strings', function () {
            $vatReg = VatRegistrationData::fromAnafResponse([
                'dataInceputTvaInc' => '',
                'dataSfarsitTvaInc' => '   ',
            ]);

            expect($vatReg->startDate)->toBeNull();
            expect($vatReg->endDate)->toBeNull();
        });

        it('handles invalid date strings', function () {
            $vatReg = VatRegistrationData::fromAnafResponse([
                'dataInceputTvaInc' => 'invalid-date',
            ]);

            expect($vatReg->startDate)->toBeNull();
        });
    });
});
