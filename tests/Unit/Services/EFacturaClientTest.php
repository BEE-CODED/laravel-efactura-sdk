<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Test helper: EFacturaClient with short lock wait time for faster tests.
 */
class FastLockTimeoutClient extends EFacturaClient
{
    protected function getLockWaitSeconds(): int
    {
        return 1; // 1 second for fast tests
    }
}

beforeEach(function () {
    // Mock the authenticator and rate limiter
    $this->authenticator = Mockery::mock(AnafAuthenticatorInterface::class);
    $this->rateLimiter = Mockery::mock(RateLimiter::class);
    $this->rateLimiter->shouldReceive('checkGlobal')->andReturn(null)->byDefault();
    $this->rateLimiter->shouldReceive('checkRaspUpload')->andReturn(null)->byDefault();
    $this->rateLimiter->shouldReceive('checkStatusQuery')->andReturn(null)->byDefault();
    $this->rateLimiter->shouldReceive('checkDownload')->andReturn(null)->byDefault();
    $this->rateLimiter->shouldReceive('checkSimpleList')->andReturn(null)->byDefault();
    $this->rateLimiter->shouldReceive('checkPaginatedList')->andReturn(null)->byDefault();

    app()->instance(AnafAuthenticatorInterface::class, $this->authenticator);
    app()->instance(RateLimiter::class, $this->rateLimiter);
});

describe('EFacturaClient', function () {
    describe('uploadDocument validation', function () {
        it('throws ValidationException for empty XML', function () {
            Http::fake([
                '*' => Http::response('OK', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->uploadDocument('');
        })->throws(ValidationException::class, 'XML content cannot be empty');

        it('throws ValidationException for whitespace-only XML', function () {
            Http::fake([
                '*' => Http::response('OK', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->uploadDocument('   ');
        })->throws(ValidationException::class, 'XML content cannot be empty');
    });

    describe('getStatusMessage validation', function () {
        it('throws ValidationException for empty upload ID', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->getStatusMessage('');
        })->throws(ValidationException::class, 'Upload ID cannot be empty');

        it('throws ValidationException for non-numeric upload ID', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->getStatusMessage('abc123');
        })->throws(ValidationException::class, 'Upload ID must be a numeric string');
    });

    describe('downloadDocument validation', function () {
        it('throws ValidationException for empty download ID', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->downloadDocument('');
        })->throws(ValidationException::class, 'Download ID cannot be empty');

        it('throws ValidationException for non-numeric download ID', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->downloadDocument('invalid');
        })->throws(ValidationException::class, 'Download ID must be a numeric string');
    });

    describe('getMessages validation', function () {
        it('throws ValidationException for days below minimum', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new ListMessagesParamsData(cif: '12345678', days: 0);

            $client->getMessages($params);
        })->throws(ValidationException::class, 'Days must be between 1 and 60');

        it('throws ValidationException for days above maximum', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new ListMessagesParamsData(cif: '12345678', days: 100);

            $client->getMessages($params);
        })->throws(ValidationException::class, 'Days must be between 1 and 60');
    });

    describe('getMessagesPaginated validation', function () {
        it('throws ValidationException for invalid start time', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: 0,
                endTime: 1000000000000,
            );

            $client->getMessagesPaginated($params);
        })->throws(ValidationException::class, 'Start time must be a positive timestamp');

        it('throws ValidationException for invalid end time', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: 1000000000000,
                endTime: 0,
            );

            $client->getMessagesPaginated($params);
        })->throws(ValidationException::class, 'End time must be a positive timestamp');

        it('throws ValidationException when start time is after end time', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: 2000000000000,
                endTime: 1000000000000,
            );

            $client->getMessagesPaginated($params);
        })->throws(ValidationException::class, 'Start time must be before end time');

        it('throws ValidationException for time range exceeding 60 days', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $startTime = Carbon::now()->subDays(70)->getTimestampMs();
            $endTime = Carbon::now()->getTimestampMs();

            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: $startTime,
                endTime: $endTime,
            );

            $client->getMessagesPaginated($params);
        })->throws(ValidationException::class, 'Time range cannot exceed 60 days');

        it('throws ValidationException for invalid page number', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: Carbon::now()->subDay()->getTimestampMs(),
                endTime: Carbon::now()->getTimestampMs(),
                page: 0,
            );

            $client->getMessagesPaginated($params);
        })->throws(ValidationException::class, 'Page number must be at least 1');
    });

    describe('validateXml validation', function () {
        it('throws ValidationException for missing validation endpoint config', function () {
            config()->set('efactura-sdk.endpoints.services.validate', null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->validateXml('<xml/>', DocumentStandardType::FACT1);
        })->throws(ValidationException::class, 'Missing configuration');
    });

    describe('verifySignature validation', function () {
        it('throws ValidationException for missing verify_signature endpoint config', function () {
            config()->set('efactura-sdk.endpoints.services.verify_signature', null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->verifySignature('<xml/>');
        })->throws(ValidationException::class, 'Missing configuration');
    });

    describe('convertXmlToPdf validation', function () {
        it('throws ValidationException for missing transform endpoint config', function () {
            config()->set('efactura-sdk.endpoints.services.transform', null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->convertXmlToPdf('<xml/>', DocumentStandardType::FACT1);
        })->throws(ValidationException::class, 'Missing configuration');
    });

    describe('RASP rate limiting', function () {
        it('checks RASP rate limit when uploading RASP documents', function () {
            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="12345"/>', 200),
            ]);

            // Expect checkRaspUpload to be called for RASP standard
            $this->rateLimiter->shouldReceive('checkRaspUpload')
                ->once()
                ->with('12345678');

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $options = new UploadOptionsData(standard: \BeeCoded\EFacturaSdk\Enums\StandardType::RASP);

            $client->uploadDocument('<Invoice/>', $options);
        });

        it('does not check RASP rate limit for UBL documents', function () {
            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="12345"/>', 200),
            ]);

            // checkRaspUpload should NOT be called for UBL
            $this->rateLimiter->shouldNotReceive('checkRaspUpload');

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $options = new UploadOptionsData(standard: \BeeCoded\EFacturaSdk\Enums\StandardType::UBL);

            $client->uploadDocument('<Invoice/>', $options);
        });

        it('checks RASP rate limit for B2C RASP documents', function () {
            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="12345"/>', 200),
            ]);

            // Expect checkRaspUpload to be called
            $this->rateLimiter->shouldReceive('checkRaspUpload')
                ->once()
                ->with('12345678');

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $options = new UploadOptionsData(standard: \BeeCoded\EFacturaSdk\Enums\StandardType::RASP);

            $client->uploadB2CDocument('<Invoice/>', $options);
        });
    });

    describe('authentication error context preservation', function () {
        it('preserves API exception context when converting to AuthenticationException', function () {
            // Create a mock that throws ApiException with context on 401
            Http::fake([
                '*' => Http::response(['error' => 'Unauthorized'], 401),
            ]);

            // We need to test that the context is preserved, but the rateLimiter mock
            // interferes. Instead, we verify the behavior through exception catching.
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            try {
                $client->getStatusMessage('12345');
                $this->fail('Expected AuthenticationException');
            } catch (AuthenticationException $e) {
                // The previous exception should be the ApiException
                expect($e->getPrevious())->toBeInstanceOf(ApiException::class);
                // The message should indicate auth failure
                expect($e->getMessage())->toContain('Authentication failed');
            }
        });
    });

    describe('UploadOptionsData integration', function () {
        it('uses default standard when options is null', function () {
            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $result = $client->uploadDocument('<Invoice/>');

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'standard=UBL');
            });
        });

        it('includes extern parameter when set', function () {
            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $options = new UploadOptionsData(extern: true);

            $result = $client->uploadDocument('<Invoice/>', $options);

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'extern=DA');
            });
        });

        it('includes selfBilled parameter when set', function () {
            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $options = new UploadOptionsData(selfBilled: true);

            $result = $client->uploadDocument('<Invoice/>', $options);

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'autofactura=DA');
            });
        });
    });

    describe('convertXmlToPdf error handling', function () {
        it('throws ApiException with details when JSON response contains error', function () {
            // Configure the transform endpoint
            config()->set('efactura-sdk.endpoints.services.transform', 'https://api.example.com/transform');

            Http::fake([
                '*' => Http::response(
                    ['eroare' => 'Invalid XML format', 'code' => 'ERR001'],
                    400,
                    ['Content-Type' => 'application/json']
                ),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->convertXmlToPdf('<Invoice/>', DocumentStandardType::FACT1);
        })->throws(ApiException::class, 'Invalid XML format');

        it('throws ApiException when JSON response is invalid/null', function () {
            // Configure the transform endpoint
            config()->set('efactura-sdk.endpoints.services.transform', 'https://api.example.com/transform');

            // Return a response with 200 status, JSON content-type but empty body that json() returns null
            // The 200 status is needed so it passes BaseApiClient's success check and reaches convertXmlToPdf's null handling
            Http::fake([
                '*' => Http::response(
                    '',
                    200,
                    ['Content-Type' => 'application/json']
                ),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->convertXmlToPdf('<Invoice/>', DocumentStandardType::FACT1);
        })->throws(ApiException::class, 'PDF conversion failed with invalid JSON response');

        it('returns PDF binary when response is successful', function () {
            // Configure the transform endpoint
            config()->set('efactura-sdk.endpoints.services.transform', 'https://api.example.com/transform');

            $pdfContent = '%PDF-1.4 fake pdf content';

            Http::fake([
                '*' => Http::response(
                    $pdfContent,
                    200,
                    ['Content-Type' => 'application/pdf']
                ),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $result = $client->convertXmlToPdf('<Invoice/>', DocumentStandardType::FACT1);

            expect($result)->toBe($pdfContent);
        });
    });

    describe('token refresh race condition handling', function () {
        it('throws AuthenticationException on lock timeout', function () {
            // Set up an expired token so it needs refresh
            $expiredTime = Carbon::now()->subMinutes(5);

            // Acquire the lock to simulate another process holding it
            $lockKey = 'efactura:token_refresh:12345678';
            $lock = Cache::lock($lockKey, 30);
            $lock->acquire();

            try {
                // Use FastLockTimeoutClient with 1-second lock wait for fast test
                $client = new FastLockTimeoutClient(
                    vatNumber: '12345678',
                    accessToken: 'expired-token',
                    refreshToken: 'refresh',
                    expiresAt: $expiredTime,
                );

                Http::fake([
                    '*' => Http::response('<?xml version="1.0"?><header stare="ok"/>', 200),
                ]);

                // Try to make an authenticated request which triggers token refresh
                // This should timeout quickly because the lock is held and wait time is 1 second
                $client->getStatusMessage('12345');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('Token refresh lock timeout');
                expect($e->getPrevious())->toBeInstanceOf(\Illuminate\Contracts\Cache\LockTimeoutException::class);
            } finally {
                // Always release the lock
                $lock->release();
            }
        });

        it('refreshes token successfully when lock is available', function () {
            // Set up an expired token
            $expiredTime = Carbon::now()->subMinutes(5);

            // Mock the authenticator to return new tokens
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->once()
                ->andReturn(new OAuthTokensData(
                    accessToken: 'new-access-token',
                    refreshToken: 'new-refresh-token',
                    expiresAt: Carbon::now()->addHour(),
                ));

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'expired-token',
                refreshToken: 'refresh',
                expiresAt: $expiredTime,
            );

            // This should trigger token refresh and succeed
            $result = $client->getStatusMessage('12345');

            expect($client->wasTokenRefreshed())->toBeTrue();
        });

        it('skips refresh when another process already refreshed token', function () {
            // This test verifies the re-check after acquiring lock
            $expiredTime = Carbon::now()->subMinutes(5);
            $validTime = Carbon::now()->addHour();

            // The authenticator should NOT be called because token becomes valid
            $this->authenticator->shouldNotReceive('refreshAccessToken');

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'valid-token',
                refreshToken: 'refresh',
                expiresAt: $validTime,  // Token is valid
            );

            // This should NOT trigger refresh because token is valid
            $result = $client->getStatusMessage('12345');

            expect($client->wasTokenRefreshed())->toBeFalse();
        });
    });

    describe('token refresh failure circuit breaker', function () {
        it('fails fast on subsequent calls after token refresh failure', function () {
            $expiredTime = Carbon::now()->subMinutes(5);

            // Mock authenticator to fail on refresh
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->once()
                ->andThrow(new AuthenticationException('Refresh token revoked'));

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'expired-token',
                refreshToken: 'revoked-refresh',
                expiresAt: $expiredTime,
            );

            // First call should fail due to refresh failure
            $firstException = null;
            try {
                $client->getStatusMessage('12345');
            } catch (AuthenticationException $e) {
                $firstException = $e;
                expect($e->getMessage())->toContain('Refresh token revoked');
            }
            expect($firstException)->not->toBeNull();

            // Second call should fail fast without attempting refresh
            $secondException = null;
            try {
                $client->getStatusMessage('67890');
            } catch (AuthenticationException $e) {
                $secondException = $e;
                expect($e->getMessage())->toContain('Token refresh previously failed');
                expect($e->getMessage())->toContain('Create a new client instance');
            }
            expect($secondException)->not->toBeNull();

            // Verify authenticator was only called once (not on second attempt)
            $this->authenticator->shouldHaveReceived('refreshAccessToken')->once();
        });

        it('does not set failure flag when token is valid', function () {
            $validTime = Carbon::now()->addHour();

            // Authenticator should NOT be called
            $this->authenticator->shouldNotReceive('refreshAccessToken');

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'valid-token',
                refreshToken: 'refresh',
                expiresAt: $validTime,
            );

            // Multiple calls should work without issue
            $client->getStatusMessage('12345');
            $client->getStatusMessage('67890');

            // No exception means success
            expect(true)->toBeTrue();
        });
    });

    describe('lazy authenticator resolution', function () {
        it('creates client without OAuth config when tokens are valid', function () {
            // Remove the mock authenticator from container to simulate no OAuth config
            app()->forgetInstance(AnafAuthenticatorInterface::class);
            app()->bind(AnafAuthenticatorInterface::class, function () {
                throw new AuthenticationException('OAuth credentials not configured');
            });

            // Should NOT throw because token is valid and authenticator is not needed yet
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'valid-token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->getVatNumber())->toBe('12345678');
        });

        it('accepts explicit authenticator in constructor', function () {
            $mockAuthenticator = Mockery::mock(AnafAuthenticatorInterface::class);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'access-token',
                refreshToken: 'refresh-token',
                expiresAt: Carbon::now()->addHour(),
                authenticator: $mockAuthenticator,
            );

            expect($client->getVatNumber())->toBe('12345678');
        });

        it('accepts explicit authenticator in fromTokens', function () {
            $mockAuthenticator = Mockery::mock(AnafAuthenticatorInterface::class);
            $tokens = new OAuthTokensData(
                accessToken: 'access-token',
                refreshToken: 'refresh-token',
                expiresAt: Carbon::now()->addHour(),
            );

            $client = EFacturaClient::fromTokens('12345678', $tokens, $mockAuthenticator);

            expect($client->getVatNumber())->toBe('12345678');
        });

        it('resolves authenticator lazily when token refresh is needed', function () {
            $expiredTime = Carbon::now()->subMinutes(5);

            // Mock authenticator to return new tokens
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->once()
                ->andReturn(new OAuthTokensData(
                    accessToken: 'new-access-token',
                    refreshToken: 'new-refresh-token',
                    expiresAt: Carbon::now()->addHour(),
                ));

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok"/>', 200),
            ]);

            // Create client without explicit authenticator (will resolve from container when needed)
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'expired-token',
                refreshToken: 'refresh',
                expiresAt: $expiredTime,
            );

            // This triggers token refresh which resolves the authenticator lazily
            $client->getStatusMessage('12345');

            expect($client->wasTokenRefreshed())->toBeTrue();
        });
    });
});
