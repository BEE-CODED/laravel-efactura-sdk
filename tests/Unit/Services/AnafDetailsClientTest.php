<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use Illuminate\Support\Facades\Http;

describe('AnafDetailsClient', function () {
    describe('getCompanyData', function () {
        it('returns company data for valid CUI', function () {
            Http::fake([
                '*' => Http::response([
                    'found' => [
                        [
                            'date_generale' => [
                                'cui' => 18547290,
                                'denumire' => 'Test Company SRL',
                                'adresa' => 'Test Address',
                                'nrRegCom' => 'J40/123/2020',
                                'telefon' => '',
                                'fax' => '',
                                'stare_inregistrare' => 'INREGISTRAT din data 01.01.2020',
                            ],
                            'inregistrare_scop_Tva' => [
                                'scpTVA' => true,
                                'data_inceput_ScpTVA' => '2020-01-01',
                            ],
                        ],
                    ],
                    'notfound' => [],
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('RO18547290');

            expect($result->success)->toBeTrue();
            expect($result->hasCompanies())->toBeTrue();
            expect($result->first()->cui)->toBe('18547290');
            expect($result->first()->name)->toBe('Test Company SRL');
        });

        it('returns failure for invalid VAT code format', function () {
            Http::preventStrayRequests();

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('abc');

            expect($result->success)->toBeFalse();
            expect($result->error)->toContain('invalid');
        });

        it('returns failure for not found CUI', function () {
            Http::fake([
                '*' => Http::response([
                    'found' => [],
                    'notfound' => [
                        ['cui' => 99999999],
                    ],
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('99999999');

            expect($result->success)->toBeFalse();
            expect($result->error)->toContain('not found');
        });

        it('handles empty VAT code', function () {
            Http::preventStrayRequests();

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('');

            expect($result->success)->toBeFalse();
        });
    });

    describe('batchGetCompanyData', function () {
        it('returns multiple companies', function () {
            Http::fake([
                '*' => Http::response([
                    'found' => [
                        [
                            'date_generale' => [
                                'cui' => 18547290,
                                'denumire' => 'Company A SRL',
                                'adresa' => 'Address A',
                            ],
                            'inregistrare_scop_Tva' => [
                                'scpTVA' => true,
                            ],
                        ],
                        [
                            'date_generale' => [
                                'cui' => 12345678,
                                'denumire' => 'Company B SRL',
                                'adresa' => 'Address B',
                            ],
                            'inregistrare_scop_Tva' => [
                                'scpTVA' => false,
                            ],
                        ],
                    ],
                    'notfound' => [],
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->batchGetCompanyData(['RO18547290', 'RO12345678']);

            expect($result->success)->toBeTrue();
            expect($result->companies)->toHaveCount(2);
        });

        it('returns failure for empty array', function () {
            Http::preventStrayRequests();

            $client = new AnafDetailsClient;
            $result = $client->batchGetCompanyData([]);

            expect($result->success)->toBeFalse();
            expect($result->error)->toContain('No VAT codes provided');
        });

        it('tracks invalid codes separately', function () {
            Http::fake([
                '*' => Http::response([
                    'found' => [
                        [
                            'date_generale' => [
                                'cui' => 18547290,
                                'denumire' => 'Valid Company',
                                'adresa' => 'Address',
                            ],
                            'inregistrare_scop_Tva' => [
                                'scpTVA' => true,
                            ],
                        ],
                    ],
                    'notfound' => [],
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->batchGetCompanyData(['RO18547290', 'invalid', 'abc123']);

            expect($result->success)->toBeTrue();
            expect($result->invalidCodes)->toContain('invalid');
            expect($result->invalidCodes)->toContain('abc123');
        });

        it('fails when all codes are invalid', function () {
            Http::preventStrayRequests();

            $client = new AnafDetailsClient;
            $result = $client->batchGetCompanyData(['abc', 'xyz', '']);

            expect($result->success)->toBeFalse();
            expect($result->error)->toContain('invalid');
        });

        it('handles API errors gracefully', function () {
            Http::fake([
                '*' => Http::response('Server Error', 500),
            ]);

            // Create a test subclass with zero retry delay for fast test execution
            $client = new class extends AnafDetailsClient
            {
                protected function getRetryDelay(): int
                {
                    return 0;
                }
            };

            $result = $client->getCompanyData('RO18547290');

            expect($result->success)->toBeFalse();
        });

        it('handles unexpected response structure', function () {
            Http::fake([
                '*' => Http::response([
                    'unexpected' => 'structure',
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('RO18547290');

            expect($result->success)->toBeFalse();
        });
    });

    describe('isValidVatCode', function () {
        it('returns true for valid CUI with checksum', function () {
            $client = new AnafDetailsClient;

            expect($client->isValidVatCode('RO18547290'))->toBeTrue();
            expect($client->isValidVatCode('18547290'))->toBeTrue();
        });

        it('returns false for invalid CUI', function () {
            $client = new AnafDetailsClient;

            expect($client->isValidVatCode('RO18547291'))->toBeFalse();
            expect($client->isValidVatCode('abc'))->toBeFalse();
            expect($client->isValidVatCode(''))->toBeFalse();
        });
    });

    describe('getBaseUrl', function () {
        it('returns ANAF company lookup URL', function () {
            expect(AnafDetailsClient::getBaseUrl())->toContain('anaf.ro');
        });
    });

    describe('getTimeoutDuration', function () {
        it('returns default timeout from config', function () {
            expect(AnafDetailsClient::getTimeoutDuration())->toBeNumeric();
        });
    });
});
