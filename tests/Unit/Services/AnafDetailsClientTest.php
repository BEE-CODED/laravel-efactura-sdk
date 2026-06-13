<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Bind a no-op rate limiter so the HTTP-fake tests below are isolated from
    // the real 1/sec per-second window (which would otherwise trip across tests).
    $rateLimiter = Mockery::mock(RateLimiter::class);
    $rateLimiter->shouldReceive('checkCompanyLookup')->andReturnNull()->byDefault();
    app()->instance(RateLimiter::class, $rateLimiter);
});

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
                    'notFound' => [],
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

        it('returns success with notFound for a nonexistent CUI (live v9 shape: camelCase key, int items)', function () {
            Http::fake([
                '*' => Http::response([
                    'found' => [],
                    'notFound' => [99999999],
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('99999999');

            expect($result->success)->toBeTrue();
            expect($result->hasCompanies())->toBeFalse();
            expect($result->hasNotFound())->toBeTrue();
            expect($result->notFound)->toBe([99999999]);
        });

        it('still parses the legacy notfound shape (lowercase key, object items)', function () {
            Http::fake([
                '*' => Http::response([
                    'found' => [],
                    'notfound' => [['cui' => 99999999]],
                ], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('99999999');

            expect($result->success)->toBeTrue();
            expect($result->notFound)->toBe([99999999]);
        });

        it('treats ANAF HTTP 404 with a notFound body as a not-found result, not a failure', function () {
            // ANAF returns 404 (not 200) with a {found, notFound} body when NONE of the
            // queried CUIs exist — a documented "not found" response, not an error.
            Http::fake([
                '*' => Http::response([
                    'found' => [],
                    'notFound' => [99999999],
                ], 404),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('99999999');

            expect($result->success)->toBeTrue();
            expect($result->hasCompanies())->toBeFalse();
            expect($result->hasNotFound())->toBeTrue();
            expect($result->notFound)->toBe([99999999]);
        });

        it('still fails on a 404 without a recognizable ANAF body', function () {
            Http::fake([
                '*' => Http::response('Not Found', 404),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('12345678');

            expect($result->success)->toBeFalse();
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
                    'notFound' => [],
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
                    'notFound' => [],
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

        it('handles invalid JSON response with proper error message', function () {
            // When ANAF returns non-JSON (maintenance page, HTML error, etc.)
            Http::fake([
                '*' => Http::response('This is not valid JSON', 200, [
                    'Content-Type' => 'text/html',
                ]),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('RO18547290');

            expect($result->success)->toBeFalse();
            expect($result->error)->toContain('Invalid or malformed JSON response');
        });

        it('distinguishes between null response and empty array response', function () {
            // Empty array is valid JSON but unexpected structure - handled by transformResponse
            Http::fake([
                '*' => Http::response([], 200),
            ]);

            $client = new AnafDetailsClient;
            $result = $client->getCompanyData('RO18547290');

            // Should fail with "Unexpected response structure" not "Invalid JSON"
            expect($result->success)->toBeFalse();
            expect($result->error)->not->toContain('Invalid or malformed JSON');
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

        it('accepts all-zeros CNP (0000000000000) as valid identifier', function () {
            // Bug fix: ANAF allows 0000000000000 as a special case valid identifier
            // This is used for certain types of entities (foreign companies, etc.)
            $client = new AnafDetailsClient;

            expect($client->isValidVatCode('0000000000000'))->toBeTrue();
        });
    });
});

describe('rate limiting', function () {
    it('checks the company-lookup limit before issuing the HTTP request', function () {
        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('checkCompanyLookup')->once();
        app()->instance(RateLimiter::class, $rateLimiter);

        Http::fake(['*' => Http::response(['found' => [], 'notFound' => [12345678]], 200)]);

        (new AnafDetailsClient)->getCompanyData('12345678');

        Http::assertSentCount(1);
    });

    it('propagates RateLimitExceededException and sends no HTTP request', function () {
        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('checkCompanyLookup')
            ->andThrow(new RateLimitExceededException('limit', remaining: 0, retryAfterSeconds: 1));
        app()->instance(RateLimiter::class, $rateLimiter);

        Http::preventStrayRequests();

        expect(fn () => (new AnafDetailsClient)->getCompanyData('12345678'))
            ->toThrow(RateLimitExceededException::class);
    });

    it('does not consume a lookup token when all codes are invalid (never reaches ANAF)', function () {
        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('checkCompanyLookup')->never();
        app()->instance(RateLimiter::class, $rateLimiter);

        Http::preventStrayRequests();

        $result = (new AnafDetailsClient)->batchGetCompanyData(['abc', 'xyz']);

        expect($result->success)->toBeFalse();
    });
});
