<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Company\AddressData;
use BeeCoded\EFacturaSdk\Data\Company\CompanyData;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Data\Company\SplitVatData;
use BeeCoded\EFacturaSdk\Data\Company\VatPeriodData;
use BeeCoded\EFacturaSdk\Data\Company\VatRegistrationData;
use BeeCoded\EFacturaSdk\Enums\RegistrationStatus;
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

    describe('isDeregistered consistency', function () {
        it('sets isDeregistered true only when deregistration date is valid', function () {
            // Bug fix: isDeregistered should be true only when we have a valid parsed date
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'stare_inactiv' => ['dataRadiere' => '2023-06-15'],
            ]);

            expect($company->isDeregistered)->toBeTrue();
            expect($company->deregistrationDate)->not->toBeNull();
            expect($company->deregistrationDate->format('Y-m-d'))->toBe('2023-06-15');
        });

        it('sets isDeregistered false when deregistration date is empty', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'stare_inactiv' => ['dataRadiere' => ''],
            ]);

            expect($company->isDeregistered)->toBeFalse();
            expect($company->deregistrationDate)->toBeNull();
        });

        it('sets isDeregistered false when deregistration date is invalid', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'stare_inactiv' => ['dataRadiere' => 'not-a-date'],
            ]);

            expect($company->isDeregistered)->toBeFalse();
            expect($company->deregistrationDate)->toBeNull();
        });

        it('sets isDeregistered false when stare_inactiv is missing', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);

            expect($company->isDeregistered)->toBeFalse();
            expect($company->deregistrationDate)->toBeNull();
        });
    });

    describe('registration status (stare_inregistrare)', function () {
        it('parses RADIERE status and flips isDeregistered (live radiated CUI 3432305 shape)', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => 3432305,
                    'denumire' => 'B & C SRL',
                    'stare_inregistrare' => 'RADIERE din data 29.03.2024',
                ],
                'stare_inactiv' => [
                    'dataInactivare' => '', 'dataReactivare' => '', 'dataPublicare' => '',
                    'dataRadiere' => '', 'statusInactivi' => false,
                ],
            ]);

            expect($company->registrationStatusRaw)->toBe('RADIERE din data 29.03.2024');
            expect($company->registrationStatus)->toBe(RegistrationStatus::Deregistered);
            expect($company->registrationStatusDate->format('Y-m-d'))->toBe('2024-03-29');
            expect($company->isDeregistered)->toBeTrue();
            expect($company->deregistrationDate->format('Y-m-d'))->toBe('2024-03-29');
            expect($company->isActive())->toBeFalse();
            expect($company->isRegistered())->toBeFalse();
        });

        it('parses INREGISTRAT status as registered and active', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => 41318860,
                    'denumire' => 'OSIRIS INVESTMENT S.R.L.',
                    'stare_inregistrare' => 'INREGISTRAT din data 26.06.2019',
                ],
            ]);

            expect($company->registrationStatus)->toBe(RegistrationStatus::Registered);
            expect($company->registrationStatusDate->format('Y-m-d'))->toBe('2019-06-26');
            expect($company->isRegistered())->toBeTrue();
            expect($company->isDeregistered)->toBeFalse();
            expect($company->isActive())->toBeTrue();
        });

        it('defaults to Unknown when stare_inregistrare is missing or empty', function () {
            $missing = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);
            $empty = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test', 'stare_inregistrare' => ''],
            ]);

            foreach ([$missing, $empty] as $company) {
                expect($company->registrationStatusRaw)->toBeNull();
                expect($company->registrationStatus)->toBe(RegistrationStatus::Unknown);
                expect($company->registrationStatusDate)->toBeNull();
                expect($company->isRegistered())->toBeFalse();
                expect($company->isDeregistered)->toBeFalse();
                expect($company->isActive())->toBeTrue();
            }
        });

        it('keeps unrecognized statuses fail-open with raw preserved', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => '12345678', 'denumire' => 'Test',
                    'stare_inregistrare' => 'INTRERUPERE TEMPORARA DE ACTIVITATE din data 01.01.2020',
                ],
            ]);

            expect($company->registrationStatus)->toBe(RegistrationStatus::Unknown);
            expect($company->registrationStatusRaw)->toContain('INTRERUPERE');
            expect($company->registrationStatusDate->format('Y-m-d'))->toBe('2020-01-01');
            expect($company->isDeregistered)->toBeFalse();
            expect($company->isActive())->toBeTrue();
        });

        it('trusts RADIERE status even when its date is unparseable', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => '12345678', 'denumire' => 'Test',
                    'stare_inregistrare' => 'RADIERE din data necunoscuta',
                ],
            ]);

            expect($company->isDeregistered)->toBeTrue();
            expect($company->registrationStatusDate)->toBeNull();
            expect($company->deregistrationDate)->toBeNull();
            expect($company->isActive())->toBeFalse();
        });

        it('prefers stare_inactiv dataRadiere over the status-string date', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => '12345678', 'denumire' => 'Test',
                    'stare_inregistrare' => 'RADIERE din data 29.03.2024',
                ],
                'stare_inactiv' => ['dataRadiere' => '2023-06-15'],
            ]);

            expect($company->isDeregistered)->toBeTrue();
            expect($company->deregistrationDate->format('Y-m-d'))->toBe('2023-06-15');
        });
    });

    describe('e-Factura registry and fiscal registration date', function () {
        it('parses statusRO_e_Factura and enrollment date', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => '12345678', 'denumire' => 'Test',
                    'statusRO_e_Factura' => true,
                    'data_inreg_Reg_RO_e_Factura' => '2022-07-01',
                    'data_inregistrare' => '1993-03-02',
                ],
            ]);

            expect($company->isRegisteredInEFactura)->toBeTrue();
            expect($company->eFacturaRegistrationDate->format('Y-m-d'))->toBe('2022-07-01');
            expect($company->registrationDate->format('Y-m-d'))->toBe('1993-03-02');
        });

        it('defaults when fields are missing or empty', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => [
                    'cui' => '12345678', 'denumire' => 'Test',
                    'statusRO_e_Factura' => false,
                    'data_inreg_Reg_RO_e_Factura' => '',
                ],
            ]);

            expect($company->isRegisteredInEFactura)->toBeFalse();
            expect($company->eFacturaRegistrationDate)->toBeNull();
            expect($company->registrationDate)->toBeNull();
        });
    });

    describe('VAT periods (perioade_TVA)', function () {
        it('derives VAT dates from the latest period (live radiated CUI shape)', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => 3432305, 'denumire' => 'B & C SRL'],
                'inregistrare_scop_Tva' => [
                    'scpTVA' => false,
                    'perioade_TVA' => [
                        ['data_inceput_ScpTVA' => '2007-07-01', 'data_sfarsit_ScpTVA' => '2018-08-01', 'data_anul_imp_ScpTVA' => '2018-08-01', 'mesaj_ScpTVA' => 'Anulare din oficiu'],
                        ['data_inceput_ScpTVA' => '1996-03-01', 'data_sfarsit_ScpTVA' => '1999-04-01', 'data_anul_imp_ScpTVA' => '', 'mesaj_ScpTVA' => 'Anulare la cerere'],
                    ],
                ],
            ]);

            expect($company->vatPeriods)->toHaveCount(2);
            expect($company->vatRegistrationDate->format('Y-m-d'))->toBe('2007-07-01');
            expect($company->vatDeregistrationDate->format('Y-m-d'))->toBe('2018-08-01');
            expect($company->vatPeriods[0]->message)->toBe('Anulare din oficiu');
        });

        it('leaves vatDeregistrationDate null for an open period (live active CUI shape)', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => 41318860, 'denumire' => 'OSIRIS INVESTMENT S.R.L.'],
                'inregistrare_scop_Tva' => [
                    'scpTVA' => true,
                    'perioade_TVA' => [
                        ['data_inceput_ScpTVA' => '2020-09-01', 'data_sfarsit_ScpTVA' => '', 'data_anul_imp_ScpTVA' => '', 'mesaj_ScpTVA' => ''],
                    ],
                ],
            ]);

            expect($company->isVatPayer)->toBeTrue();
            expect($company->vatRegistrationDate->format('Y-m-d'))->toBe('2020-09-01');
            expect($company->vatDeregistrationDate)->toBeNull();
        });

        it('picks the latest period regardless of response order', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'inregistrare_scop_Tva' => [
                    'perioade_TVA' => [
                        ['data_inceput_ScpTVA' => '1996-03-01', 'data_sfarsit_ScpTVA' => '1999-04-01'],
                        ['data_inceput_ScpTVA' => '2007-07-01', 'data_sfarsit_ScpTVA' => '2018-08-01'],
                    ],
                ],
            ]);

            expect($company->vatRegistrationDate->format('Y-m-d'))->toBe('2007-07-01');
        });

        it('prefers flat keys over periods when both are present (legacy shape)', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
                'inregistrare_scop_Tva' => [
                    'data_inceput_ScpTVA' => '2020-01-15',
                    'perioade_TVA' => [
                        ['data_inceput_ScpTVA' => '2007-07-01'],
                    ],
                ],
            ]);

            expect($company->vatRegistrationDate->format('Y-m-d'))->toBe('2020-01-15');
        });

        it('defaults to empty vatPeriods when absent', function () {
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '12345678', 'denumire' => 'Test'],
            ]);

            expect($company->vatPeriods)->toBe([]);
            expect($company->vatRegistrationDate)->toBeNull();
        });
    });

    it('parses the complete live v9 response for a radiated company end-to-end', function () {
        $company = CompanyData::fromAnafResponse([
            'date_generale' => [
                'data' => '2026-06-12',
                'cui' => 3432305,
                'denumire' => 'B & C SRL',
                'adresa' => 'JUD. PRAHOVA, MUN. PLOIEŞTI, STR. BRÂNCOVEANU VODĂ, NR.2A',
                'telefon' => '0244510775',
                'fax' => '',
                'codPostal' => '100400',
                'act' => '',
                'stare_inregistrare' => 'RADIERE din data 29.03.2024',
                'data_inreg_Reg_RO_e_Factura' => '',
                'organFiscalCompetent' => 'Administraţia Judeţeană a Finanţelor Publice Prahova',
                'forma_de_proprietate' => 'PROPR.PRIVATA-CAPITAL PRIVAT AUTOHTON',
                'forma_organizare' => 'PERSOANA JURIDICA',
                'forma_juridica' => 'SOCIETATE COMERCIALĂ CU RĂSPUNDERE LIMITATĂ',
                'statusRO_e_Factura' => false,
                'data_inregistrare' => '1993-03-02',
                'nrRegCom' => 'J29/3210/1992',
                'cod_CAEN' => '6202',
                'iban' => '',
            ],
            'inregistrare_scop_Tva' => [
                'scpTVA' => false,
                'perioade_TVA' => [
                    ['data_inceput_ScpTVA' => '2007-07-01', 'data_sfarsit_ScpTVA' => '2018-08-01', 'data_anul_imp_ScpTVA' => '2018-08-01', 'mesaj_ScpTVA' => 'Anularea înregistrarii în scopuri de TVA a fost efectuata din oficiu'],
                    ['data_inceput_ScpTVA' => '1996-03-01', 'data_sfarsit_ScpTVA' => '1999-04-01', 'data_anul_imp_ScpTVA' => '', 'mesaj_ScpTVA' => 'Anularea înregistrarii în scopuri de TVA a fost efectuata la cererea persoanei impozabile'],
                ],
            ],
            'inregistrare_RTVAI' => [
                'dataSfarsitTvaInc' => '2018-08-01',
                'dataInceputTvaInc' => '2013-01-01',
                'tipActTvaInc' => 'Radiere',
                'statusTvaIncasare' => false,
                'dataActualizareTvaInc' => '2018-08-01',
                'dataPublicareTvaInc' => '2018-08-02',
            ],
            'stare_inactiv' => [
                'dataInactivare' => '', 'dataReactivare' => '', 'dataPublicare' => '',
                'dataRadiere' => '', 'statusInactivi' => false,
            ],
            'inregistrare_SplitTVA' => [
                'statusSplitTVA' => false, 'dataInceputSplitTVA' => '', 'dataAnulareSplitTVA' => '',
            ],
            'adresa_sediu_social' => [
                'sdenumire_Strada' => 'Str. BRÂNCOVEANU VODĂ', 'snumar_Strada' => '2A',
                'scod_Localitate' => '323', 'sdenumire_Judet' => 'PRAHOVA',
                'sdenumire_Localitate' => 'Mun. Ploieşti', 'scod_Judet' => '29',
                'scod_JudetAuto' => 'PH', 'sdetalii_Adresa' => '', 'scod_Postal' => '', 'stara' => '',
            ],
            'adresa_domiciliu_fiscal' => [
                'ddenumire_Strada' => 'Str. Brâncoveanu Vodă', 'dnumar_Strada' => '2A',
                'dcod_Localitate' => '323', 'ddenumire_Judet' => 'PRAHOVA',
                'dcod_Judet' => '29', 'dcod_JudetAuto' => 'PH', 'ddetalii_Adresa' => '',
                'dcod_Postal' => '100400', 'dtara' => '', 'ddenumire_Localitate' => 'Mun. Ploieşti',
            ],
        ]);

        // The bug this feature fixes: radiated company must NOT be active
        expect($company->isActive())->toBeFalse();
        expect($company->isDeregistered)->toBeTrue();
        expect($company->deregistrationDate->format('Y-m-d'))->toBe('2024-03-29');
        expect($company->isRegistered())->toBeFalse();
        // Untouched behavior still intact
        expect($company->cui)->toBe('3432305');
        expect($company->name)->toBe('B & C SRL');
        expect($company->isInactive)->toBeFalse();
        expect($company->isRtvai)->toBeFalse();
        expect($company->rtvaiDetails->actType)->toBe('Radiere');
        // New surface
        expect($company->isRegisteredInEFactura)->toBeFalse();
        expect($company->registrationDate->format('Y-m-d'))->toBe('1993-03-02');
        expect($company->vatPeriods)->toHaveCount(2);
        expect($company->vatRegistrationDate->format('Y-m-d'))->toBe('2007-07-01');
        expect($company->getPrimaryAddress())->not->toBeNull();
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

        it('returns null for RO-only input (empty CUI after prefix removal)', function () {
            // Edge case: if input is just "RO", stripping the prefix leaves empty string
            // This should return null, not match a company with empty CUI
            $company = CompanyData::fromAnafResponse([
                'date_generale' => ['cui' => '', 'denumire' => 'Empty CUI Company'],
            ]);

            $result = CompanyLookupResultData::success([$company]);

            expect($result->getByCui('RO'))->toBeNull();
            expect($result->getByCui('ro'))->toBeNull();
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

    describe('fromAnafResponse (VAT registration)', function () {
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

describe('VatPeriodData', function () {
    it('parses a closed period from ANAF response', function () {
        $period = VatPeriodData::fromAnafResponse([
            'data_inceput_ScpTVA' => '2007-07-01',
            'data_sfarsit_ScpTVA' => '2018-08-01',
            'data_anul_imp_ScpTVA' => '2018-08-01',
            'mesaj_ScpTVA' => 'Anularea înregistrarii în scopuri de TVA a fost efectuata din oficiu',
        ]);

        expect($period->startDate->format('Y-m-d'))->toBe('2007-07-01');
        expect($period->endDate->format('Y-m-d'))->toBe('2018-08-01');
        expect($period->cancellationDate->format('Y-m-d'))->toBe('2018-08-01');
        expect($period->message)->toContain('din oficiu');
    });

    it('parses an open period with empty end fields', function () {
        $period = VatPeriodData::fromAnafResponse([
            'data_inceput_ScpTVA' => '2020-09-01',
            'data_sfarsit_ScpTVA' => '',
            'data_anul_imp_ScpTVA' => '',
            'mesaj_ScpTVA' => '',
        ]);

        expect($period->startDate->format('Y-m-d'))->toBe('2020-09-01');
        expect($period->endDate)->toBeNull();
        expect($period->cancellationDate)->toBeNull();
        expect($period->message)->toBeNull();
    });

    it('handles empty and invalid input', function () {
        $period = VatPeriodData::fromAnafResponse([]);
        expect($period->startDate)->toBeNull();

        $period = VatPeriodData::fromAnafResponse(['data_inceput_ScpTVA' => 'not-a-date']);
        expect($period->startDate)->toBeNull();
    });
});

describe('AddressData', function () {
    describe('getFullAddress', function () {
        it('formats address with all parts', function () {
            $address = new AddressData(
                street: 'Str. Exemplu',
                streetNumber: '123',
                details: 'Bl. A, Sc. 1',
                city: 'Bucuresti',
                county: 'Bucuresti',
                postalCode: '010101',
                country: 'Romania',
            );

            $fullAddress = $address->getFullAddress();

            expect($fullAddress)->toContain('Str. Exemplu');
            expect($fullAddress)->toContain('nr. 123');
            expect($fullAddress)->toContain('Bl. A, Sc. 1');
            expect($fullAddress)->toContain('Bucuresti');
            expect($fullAddress)->toContain('010101');
            expect($fullAddress)->toContain('Romania');
        });

        it('skips null and empty values', function () {
            $address = new AddressData(
                street: 'Str. Test',
                streetNumber: null,
                city: 'Bucuresti',
            );

            $fullAddress = $address->getFullAddress();

            expect($fullAddress)->toBe('Str. Test, Bucuresti');
            expect($fullAddress)->not->toContain('nr.');
        });

        it('preserves zero string values', function () {
            // Edge case: '0' should not be filtered out by array_filter
            $address = new AddressData(
                street: '0',
                city: 'Test City',
            );

            $fullAddress = $address->getFullAddress();

            expect($fullAddress)->toContain('0');
            expect($fullAddress)->toBe('0, Test City');
        });

        it('handles street number zero correctly', function () {
            $address = new AddressData(
                street: 'Strada Zero',
                streetNumber: '0',
                city: 'Bucuresti',
            );

            $fullAddress = $address->getFullAddress();

            expect($fullAddress)->toContain('nr. 0');
        });

        it('returns empty string when all fields are null', function () {
            $address = new AddressData;

            expect($address->getFullAddress())->toBe('');
        });
    });
});
