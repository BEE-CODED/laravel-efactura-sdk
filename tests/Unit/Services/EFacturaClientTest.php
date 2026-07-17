<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
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

/**
 * Build a ConnectionException shaped exactly like the one Laravel raises for a
 * real cURL failure: PendingRequest::marshalConnectionException() wraps Guzzle's
 * ConnectException, preserving its message verbatim and keeping it as $previous.
 */
function curlFailure(int $errno, string $error): ConnectionException
{
    $message = sprintf(
        'cURL error %d: %s (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.anaf.ro/test/FCTEL/rest/upload',
        $errno,
        $error
    );

    $guzzle = new ConnectException(
        $message,
        new Request('POST', 'https://api.anaf.ro/test/FCTEL/rest/upload'),
        null,
        ['errno' => $errno, 'error' => $error],
    );

    return new ConnectionException($message, 0, $guzzle);
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

        it('surfaces an ANAF 200 + {"eroare"} body through the full client path', function () {
            // The real runtime path the wrapper polls on: JSON 200 with no stare.
            // ANAF's message must reach the caller instead of being dropped.
            Http::fake([
                '*' => Http::response(['eroare' => 'Nu exista niciun mesaj cu id-ul=12345'], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $response = $client->getStatusMessage('12345');

            expect($response->errors)->toBe(['Nu exista niciun mesaj cu id-ul=12345']);
            expect($response->hasAnafError())->toBeTrue();
            // Still no verdict: the caller must treat this as indeterminate, not failed.
            expect($response->stare)->toBeNull();
            expect($response->isFailed())->toBeFalse();
        });
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

            $options = new UploadOptionsData(standard: StandardType::RASP);

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

            $options = new UploadOptionsData(standard: StandardType::UBL);

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

            $options = new UploadOptionsData(standard: StandardType::RASP);

            $client->uploadB2CDocument('<Invoice/>', $options);
        });
    });

    describe('upload retry safety (duplicate filing prevention)', function () {
        // ANAF mints a distinct index_incarcare per accepted POST and honours no
        // idempotency key, so an upload retried after the body was already delivered
        // files the same invoice twice. A read timeout is the dangerous case: the
        // document may be sitting in ANAF's queue, accepted, while we see only a stall.

        beforeEach(function () {
            // Retries must not actually sleep during tests.
            config()->set('efactura-sdk.http.retry_delay', 0);
            config()->set('efactura-sdk.http.retry_times', 3);
        });

        it('does NOT retry an upload after a read timeout', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                // cURL 28: the request WAS sent; ANAF may have accepted it already.
                throw curlFailure(28, 'Operation timed out after 30001 milliseconds with 0 bytes received');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(1);
        });

        it('does NOT retry an upload when the server closed without responding', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                // cURL 52: server accepted the body then hung up. Guzzle still calls
                // this a ConnectException, which is exactly why the class name cannot
                // be trusted as proof the request was never sent.
                throw curlFailure(52, 'Empty reply from server');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(1);
        });

        it('does NOT retry a B2C upload after a read timeout', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                throw curlFailure(28, 'Operation timed out after 30001 milliseconds with 0 bytes received');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadB2CDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(1);
        });

        it('DOES retry an upload when DNS resolution failed (nothing was sent)', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                // cURL 6: no connection was ever established, so no filing happened.
                throw curlFailure(6, 'Could not resolve host: api.anaf.ro');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('DOES retry an upload when the connection was refused (nothing was sent)', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                throw curlFailure(7, 'Failed to connect to api.anaf.ro port 443: Connection refused');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('does NOT retry an upload on an unclassifiable transport error', function () {
            // No cURL errno to reason about => cannot prove the request was not sent
            // => must not retry. Correctness beats availability for a legal filing.
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('Something went wrong');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(1);
        });

        it('does NOT retry an upload on a 5xx (ANAF received the document and responded)', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->uploadDocument('<Invoice/>'))->toThrow(ApiException::class);
            expect($attempts)->toBe(1);
        });

        it('still retries reads on a read timeout', function () {
            // Reads have no side effects at ANAF, so availability wins there.
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                throw curlFailure(28, 'Operation timed out after 30001 milliseconds with 0 bytes received');
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->getStatusMessage('12345'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('still retries reads on a 5xx', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->getStatusMessage('12345'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('consumes global rate limit quota once per HTTP attempt, not once per call', function () {
            // ANAF counts every HTTP request against the global cap, so a retried
            // read must consume a unit per attempt or the SDK under-counts.
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $this->rateLimiter->shouldReceive('checkGlobal')->times(3)->andReturn(null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->getStatusMessage('12345'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('consumes the per-message status bucket once per HTTP attempt too', function () {
            // The global bucket is not the only one ANAF meters per request. Counting
            // a retried status poll once leaves status_per_day_message (50/day)
            // under-counted by the retry factor, so ANAF trips the real limit while
            // getRemainingQuota() still reports headroom.
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $this->rateLimiter->shouldReceive('checkGlobal')->times(3)->andReturn(null);
            $this->rateLimiter->shouldReceive('checkStatusQuery')->times(3)->with('12345')->andReturn(null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->getStatusMessage('12345'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('consumes the per-message download bucket once per HTTP attempt', function () {
            // download_per_day_message defaults to 5. Four retried downloads of one
            // message during a 5xx window are 12 real requests but would count as 4.
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $this->rateLimiter->shouldReceive('checkGlobal')->times(3)->andReturn(null);
            $this->rateLimiter->shouldReceive('checkDownload')->times(3)->with('999')->andReturn(null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->downloadDocument('999'))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('consumes the per-CUI simple-list bucket once per HTTP attempt', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $this->rateLimiter->shouldReceive('checkGlobal')->times(3)->andReturn(null);
            $this->rateLimiter->shouldReceive('checkSimpleList')->times(3)->with('12345678')->andReturn(null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new ListMessagesParamsData(cif: '12345678', days: 7);

            expect(fn () => $client->getMessages($params))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('consumes the per-CUI paginated-list bucket once per HTTP attempt', function () {
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;

                return Http::response('Server Error', 500);
            });

            $this->rateLimiter->shouldReceive('checkGlobal')->times(3)->andReturn(null);
            $this->rateLimiter->shouldReceive('checkPaginatedList')->times(3)->with('12345678')->andReturn(null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: Carbon::now()->subDays(7)->getTimestampMs(),
                endTime: Carbon::now()->getTimestampMs(),
            );

            expect(fn () => $client->getMessagesPaginated($params))->toThrow(ApiException::class);
            expect($attempts)->toBe(3);
        });

        it('does not leak one call\'s endpoint bucket into the next call\'s retries', function () {
            // The retry hook has to be re-armed per logical call. A status poll that
            // retried must not keep charging checkStatusQuery while a later
            // validateXml (a global-only endpoint) retries.
            Http::fake(fn () => Http::response('Server Error', 500));

            $this->rateLimiter->shouldReceive('checkGlobal')->andReturn(null);
            // Exactly the 3 attempts of the FIRST call - never re-armed for the second.
            $this->rateLimiter->shouldReceive('checkStatusQuery')->times(3)->andReturn(null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect(fn () => $client->getStatusMessage('12345'))->toThrow(ApiException::class);
            expect(fn () => $client->validateXml('<Invoice/>', DocumentStandardType::FACT1))
                ->toThrow(ApiException::class);
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

    describe('downloadDocument body inspection', function () {
        // ANAF is known to answer 200 + a JSON error body on other endpoints
        // (listaMesaje errors arrive as 200 + "eroare"). If /descarcare ever does
        // the same, an unchecked 2xx body means saveTo('invoice.zip') writes JSON
        // into a file every downstream consumer will treat as a ZIP.

        it('throws instead of returning a JSON error body as a ZIP', function () {
            Http::fake([
                '*' => Http::response(
                    ['eroare' => 'Nu existe niciun mesaj cu id-ul=12345'],
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

            $client->downloadDocument('12345');
        })->throws(ApiException::class, 'Nu existe niciun mesaj cu id-ul=12345');

        it('detects a JSON error body even without a JSON content-type', function () {
            Http::fake([
                '*' => Http::response(
                    '{"eroare":"Id_descarcare cu id=12345 nu exista"}',
                    200,
                    ['Content-Type' => 'application/octet-stream']
                ),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->downloadDocument('12345');
        })->throws(ApiException::class, 'Id_descarcare cu id=12345 nu exista');

        it('rejects a body that is not a ZIP archive', function () {
            // e.g. an HTML maintenance page served with a 200.
            Http::fake([
                '*' => Http::response('<html><body>Service unavailable</body></html>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->downloadDocument('12345');
        })->throws(ApiException::class, 'did not return a ZIP archive');

        it('returns the download when the body is a real ZIP', function () {
            // "PK\x03\x04" - the local file header signature every ZIP starts with.
            $zip = "PK\x03\x04".str_repeat("\x00", 26).'invoice-content';

            Http::fake([
                '*' => Http::response($zip, 200, [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="12345.zip"',
                ]),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $result = $client->downloadDocument('12345');

            expect($result->content)->toBe($zip);
            expect($result->filename)->toBe('12345.zip');
        });

        it('accepts an empty ZIP archive', function () {
            // "PK\x05\x06" - end-of-central-directory, i.e. a valid empty archive.
            $emptyZip = "PK\x05\x06".str_repeat("\x00", 18);

            Http::fake([
                '*' => Http::response($emptyZip, 200, ['Content-Type' => 'application/zip']),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->downloadDocument('12345')->content)->toBe($emptyZip);
        });
    });

    describe('validation failure details', function () {
        // ANAF's validare service reports *why* a document is invalid in
        // Messages[].message, with a trace_id for support tickets:
        //   {"stare":"nok","Messages":[{"message":"E: ..."}],"trace_id":...}
        // Dropping those leaves the caller knowing only that the XML is bad.

        beforeEach(function () {
            config()->set('efactura-sdk.endpoints.services.validate', 'https://api.example.com/validare');
            config()->set('efactura-sdk.endpoints.services.verify_signature', 'https://api.example.com/verificare');
        });

        it('maps ANAF Messages[].message into errors', function () {
            Http::fake([
                '*' => Http::response([
                    'stare' => 'nok',
                    'Messages' => [
                        ['message' => 'E: validare fisier xml: linia 12: element lipsa'],
                        ['message' => 'E: BR-CO-15: valoarea totala nu corespunde'],
                    ],
                    'trace_id' => '8321634512',
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $result = $client->validateXml('<Invoice/>', DocumentStandardType::FACT1);

            expect($result->valid)->toBeFalse();
            expect($result->errors)->toBe([
                'E: validare fisier xml: linia 12: element lipsa',
                'E: BR-CO-15: valoarea totala nu corespunde',
            ]);
        });

        it('surfaces trace_id as info for support tickets', function () {
            Http::fake([
                '*' => Http::response([
                    'stare' => 'nok',
                    'Messages' => [['message' => 'E: ceva']],
                    'trace_id' => '8321634512',
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->validateXml('<Invoice/>', DocumentStandardType::FACT1)->info)->toBe('8321634512');
        });

        it('stringifies a numeric trace_id', function () {
            // Observed as both a quoted string and a bare number in the wild.
            Http::fake([
                '*' => Http::response([
                    'stare' => 'ok',
                    'trace_id' => 8321634512,
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $result = $client->validateXml('<Invoice/>', DocumentStandardType::FACT1);

            expect($result->valid)->toBeTrue();
            expect($result->info)->toBe('8321634512');
        });

        it('accepts plain-string Messages entries defensively', function () {
            Http::fake([
                '*' => Http::response([
                    'stare' => 'nok',
                    'Messages' => ['E: mesaj simplu'],
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->validateXml('<Invoice/>', DocumentStandardType::FACT1)->errors)->toBe(['E: mesaj simplu']);
        });

        it('still maps the legacy Errors key', function () {
            Http::fake([
                '*' => Http::response([
                    'stare' => 'nok',
                    'Errors' => ['legacy error'],
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->validateXml('<Invoice/>', DocumentStandardType::FACT1)->errors)->toBe(['legacy error']);
        });

        it('still maps the legacy eroare key', function () {
            Http::fake([
                '*' => Http::response([
                    'stare' => 'nok',
                    'eroare' => 'eroare simpla',
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->validateXml('<Invoice/>', DocumentStandardType::FACT1)->errors)->toBe(['eroare simpla']);
        });

        it('leaves errors null on a clean validation', function () {
            Http::fake([
                '*' => Http::response(['stare' => 'ok'], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $result = $client->validateXml('<Invoice/>', DocumentStandardType::FACT1);

            expect($result->valid)->toBeTrue();
            expect($result->errors)->toBeNull();
        });

        it('reports Messages from verifySignature too', function () {
            Http::fake([
                '*' => Http::response([
                    'stare' => 'nok',
                    'Messages' => [['message' => 'E: semnatura invalida']],
                ], 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->verifySignature('<Invoice/>')->errors)->toBe(['E: semnatura invalida']);
        });

        it('reports Messages in the convertXmlToPdf error branch', function () {
            config()->set('efactura-sdk.endpoints.services.transform', 'https://api.example.com/transformare');

            Http::fake([
                '*' => Http::response(
                    ['stare' => 'nok', 'Messages' => [['message' => 'E: XML invalid pentru transformare']]],
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
        })->throws(ApiException::class, 'E: XML invalid pentru transformare');
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
                expect($e->getPrevious())->toBeInstanceOf(LockTimeoutException::class);
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

        it('does not refresh when its own token is still valid', function () {
            // Was named "skips refresh when another process already refreshed token"
            // and claimed to cover the post-lock re-check -- but it passes a VALID
            // token, so getValidAccessToken() returns at the fast path and the lock
            // branch is never entered. It only ever proved "valid token => no
            // refresh". Renamed to what it actually asserts; the post-lock re-check
            // is covered by the 'concurrent token refresh' group below.
            $validTime = Carbon::now()->addHour();

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

    describe('concurrent token refresh (rotated refresh token)', function () {
        // ANAF rotates refresh tokens: once used, the old one is dead. Two workers
        // built from the same persisted tokens race here. A refreshes; B blocks on
        // the lock; B then wakes with only its own stale in-memory copy. Re-checking
        // $this->expiresAt at that point is a provable no-op -- nothing mutates it
        // while blocked. So B refreshes with A's already-spent refresh token, which
        // fails and can invalidate A's grant too. The client needs to re-read the
        // persisted tokens after acquiring the lock.

        it('adopts tokens another worker persisted instead of refreshing again', function () {
            $expiredTime = Carbon::now()->subMinutes(5);

            // What worker A refreshed and wrote to the store while B was blocked.
            $persisted = new OAuthTokensData(
                accessToken: 'rotated-access-token',
                refreshToken: 'rotated-refresh-token',
                expiresAt: Carbon::now()->addHour(),
            );

            // B must NOT refresh: A's rotation already produced a valid token, and
            // B's in-memory refresh token is now dead.
            $this->authenticator->shouldNotReceive('refreshAccessToken');

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'stale-access-token',
                refreshToken: 'already-used-refresh-token',
                expiresAt: $expiredTime,
                tokenReloader: fn () => $persisted,
            );

            $client->getStatusMessage('12345');

            expect($client->getTokens()->accessToken)->toBe('rotated-access-token');
            // We adopted persisted tokens rather than minting new ones, so there is
            // nothing for the caller to write back.
            expect($client->wasTokenRefreshed())->toBeFalse();

            Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer rotated-access-token'));
        });

        it('refreshes using the reloaded refresh token, never its own stale one', function () {
            // The persisted tokens are expired too, so a refresh is still needed --
            // but it must use the freshest refresh token, not the spent in-memory one.
            $persisted = new OAuthTokensData(
                accessToken: 'newer-but-expired-access-token',
                refreshToken: 'newer-refresh-token',
                expiresAt: Carbon::now()->subMinute(),
            );

            $this->authenticator->shouldReceive('refreshAccessToken')
                ->once()
                ->with('newer-refresh-token')
                ->andReturn(new OAuthTokensData(
                    accessToken: 'final-access-token',
                    refreshToken: 'final-refresh-token',
                    expiresAt: Carbon::now()->addHour(),
                ));

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'stale-access-token',
                refreshToken: 'already-used-refresh-token',
                expiresAt: Carbon::now()->subMinutes(5),
                tokenReloader: fn () => $persisted,
            );

            $client->getStatusMessage('12345');

            expect($client->getTokens()->accessToken)->toBe('final-access-token');
            expect($client->wasTokenRefreshed())->toBeTrue();
        });

        it('normalises an immutable expiresAt coming back from the reloader', function () {
            // Wrapper packages hydrate expires_at from the DB, which is a
            // CarbonImmutable under Date::use(CarbonImmutable::class).
            $persisted = new OAuthTokensData(
                accessToken: 'rotated-access-token',
                refreshToken: 'rotated-refresh-token',
                expiresAt: CarbonImmutable::now()->addHour(),
            );

            $this->authenticator->shouldNotReceive('refreshAccessToken');

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'stale-access-token',
                refreshToken: 'already-used-refresh-token',
                expiresAt: Carbon::now()->subMinutes(5),
                tokenReloader: fn () => $persisted,
            );

            $client->getStatusMessage('12345');

            expect($client->getTokens()->expiresAt)->toBeInstanceOf(Carbon::class);
        });

        it('falls back to its own tokens when the reloader returns null', function () {
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->once()
                ->with('own-refresh-token')
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
                accessToken: 'stale-access-token',
                refreshToken: 'own-refresh-token',
                expiresAt: Carbon::now()->subMinutes(5),
                tokenReloader: fn () => null,
            );

            $client->getStatusMessage('12345');

            expect($client->wasTokenRefreshed())->toBeTrue();
        });

        it('falls back to its own tokens when the reloader throws', function () {
            // A store lookup failure must not take down the API call: refreshing
            // with a possibly-stale token is still better than failing outright.
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->once()
                ->with('own-refresh-token')
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
                accessToken: 'stale-access-token',
                refreshToken: 'own-refresh-token',
                expiresAt: Carbon::now()->subMinutes(5),
                tokenReloader: fn () => throw new RuntimeException('database is down'),
            );

            $client->getStatusMessage('12345');

            expect($client->wasTokenRefreshed())->toBeTrue();
        });

        it('does not consult the reloader when the token is still valid', function () {
            // The fast path must stay allocation-free: no store hit per API call.
            $reloaderCalls = 0;

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'valid-token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
                tokenReloader: function () use (&$reloaderCalls) {
                    $reloaderCalls++;

                    return null;
                },
            );

            $client->getStatusMessage('12345');

            expect($reloaderCalls)->toBe(0);
        });

        it('accepts a reloader via fromTokens', function () {
            $persisted = new OAuthTokensData(
                accessToken: 'rotated-access-token',
                refreshToken: 'rotated-refresh-token',
                expiresAt: Carbon::now()->addHour(),
            );

            $this->authenticator->shouldNotReceive('refreshAccessToken');

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = EFacturaClient::fromTokens(
                '12345678',
                new OAuthTokensData(
                    accessToken: 'stale-access-token',
                    refreshToken: 'already-used-refresh-token',
                    expiresAt: Carbon::now()->subMinutes(5),
                ),
                $this->authenticator,
                fn () => $persisted,
            );

            $client->getStatusMessage('12345');

            expect($client->getTokens()->accessToken)->toBe('rotated-access-token');
        });

        it('does not clobber tokens it minted itself with the store\'s spent ones', function () {
            // The reloader reads a store the caller has NOT written back to yet: the
            // README's own pattern persists only AFTER the call returns
            // (`if ($client->wasTokenRefreshed()) { persist }`), so this window always
            // exists. A long-lived client (a batch worker) then refreshes a SECOND
            // time later in the same run -- and must not adopt the store's copy of the
            // refresh token it has already spent.
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            // The store still holds the ORIGINAL pair. Nobody has persisted since.
            $persisted = new OAuthTokensData(
                accessToken: 'orig-access',
                refreshToken: 'orig-refresh',
                expiresAt: Carbon::now()->subMinutes(5),
            );

            // ANAF rotates refresh tokens: spending one invalidates it for good.
            $spent = [];
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->andReturnUsing(function (string $refreshToken) use (&$spent) {
                    if (isset($spent[$refreshToken])) {
                        throw new AuthenticationException(
                            "invalid_grant: refresh token '{$refreshToken}' was already used"
                        );
                    }
                    $spent[$refreshToken] = true;

                    return match ($refreshToken) {
                        'orig-refresh' => new OAuthTokensData(
                            accessToken: 'new-access',
                            refreshToken: 'new-refresh',
                            expiresAt: Carbon::now()->addHour(),
                        ),
                        'new-refresh' => new OAuthTokensData(
                            accessToken: 'final-access',
                            refreshToken: 'final-refresh',
                            expiresAt: Carbon::now()->addHour(),
                        ),
                        default => throw new AuthenticationException("unknown refresh token '{$refreshToken}'"),
                    };
                });

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'orig-access',
                refreshToken: 'orig-refresh',
                expiresAt: Carbon::now()->subMinutes(5),
                tokenReloader: fn () => $persisted,
            );

            // Call 1: in-memory token is expired, the store's is too, so this client
            // spends 'orig-refresh' itself and mints 'new-refresh'.
            $client->getStatusMessage('12345');
            expect($client->getTokens()->refreshToken)->toBe('new-refresh');
            expect($client->wasTokenRefreshed())->toBeTrue();

            // The caller has still not persisted -- it does that at the end of the batch.
            // Two hours on, the token this client minted expires and it refreshes again.
            Carbon::setTestNow(Carbon::now()->addHours(2));

            $client->getStatusMessage('12345');

            // It must have refreshed with its OWN 'new-refresh', not the store's
            // long-spent 'orig-refresh'.
            expect($client->getTokens()->accessToken)->toBe('final-access');
            expect($client->getTokens()->refreshToken)->toBe('final-refresh');

            Carbon::setTestNow();
        });

        it('still adopts a store token that is genuinely fresher than its own', function () {
            // The converse of the test above: skipping adoption outright would brick
            // the multi-worker case this feature exists for. Another worker rotating
            // AFTER us must still win.
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $persisted = new OAuthTokensData(
                accessToken: 'orig-access',
                refreshToken: 'orig-refresh',
                expiresAt: Carbon::now()->subMinutes(5),
            );

            $spent = [];
            $this->authenticator->shouldReceive('refreshAccessToken')
                ->andReturnUsing(function (string $refreshToken) use (&$spent, &$persisted) {
                    if (isset($spent[$refreshToken])) {
                        throw new AuthenticationException(
                            "invalid_grant: refresh token '{$refreshToken}' was already used"
                        );
                    }
                    $spent[$refreshToken] = true;

                    return new OAuthTokensData(
                        accessToken: 'new-access',
                        refreshToken: 'new-refresh',
                        expiresAt: Carbon::now()->addHour(),
                    );
                });

            Http::fake([
                '*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="12345"/>', 200),
            ]);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'orig-access',
                refreshToken: 'orig-refresh',
                expiresAt: Carbon::now()->subMinutes(5),
                // NB: a by-reference closure, not `fn () =>` -- an arrow function
                // captures by value and would pin the store to its original contents.
                tokenReloader: function () use (&$persisted) {
                    return $persisted;
                },
            );

            $client->getStatusMessage('12345');
            expect($client->getTokens()->refreshToken)->toBe('new-refresh');

            // Meanwhile another worker rotated again and DID persist, so the store now
            // holds a strictly fresher pair than the one we minted.
            Carbon::setTestNow(Carbon::now()->addHours(2));
            $persisted = new OAuthTokensData(
                accessToken: 'other-worker-access',
                refreshToken: 'other-worker-refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->getStatusMessage('12345');

            // Ours is expired and the store's is valid and later: adopt it, do not
            // spend our own now-dead refresh token.
            expect($client->getTokens()->accessToken)->toBe('other-worker-access');
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

    describe('immutable date support', function () {
        // Apps calling Date::use(CarbonImmutable::class) hydrate expires_at as a
        // CarbonImmutable, which is NOT a Carbon subclass. It reaches this client via
        // OAuthTokensData -> fromTokens() on every single API operation.

        it('accepts an immutable expiresAt and normalises it', function () {
            $expiresAt = CarbonImmutable::create(2024, 12, 31, 23, 59, 59);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'access_token',
                refreshToken: 'refresh_token',
                expiresAt: $expiresAt,
            );

            // Round-trips as a mutable Carbon at the same instant, so the private
            // isTokenValid() expiry math never sees an immutable date.
            expect($client->getTokens()->expiresAt)->toBeInstanceOf(Carbon::class)
                ->and($client->getTokens()->expiresAt->equalTo($expiresAt))->toBeTrue();
        });

        it('preserves microseconds and timezone when normalising expiresAt', function () {
            // Carbon::instance() round-trips through 'U.u'; assert the precision rather than
            // trusting it, since a lossy conversion would shift token expiry.
            $expiresAt = CarbonImmutable::create(2024, 12, 31, 23, 59, 59, 'Europe/Bucharest')->addMicroseconds(123456);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'access_token',
                refreshToken: 'refresh_token',
                expiresAt: $expiresAt,
            );

            expect($client->getTokens()->expiresAt->format('Y-m-d H:i:s.u'))->toBe('2024-12-31 23:59:59.123456')
                ->and($client->getTokens()->expiresAt->getTimezone()->getName())->toBe('Europe/Bucharest')
                ->and($client->getTokens()->expiresAt->equalTo($expiresAt))->toBeTrue();
        });

        it('keeps a mutable Carbon expiresAt as the same instance', function () {
            // BC guard: existing callers must not silently start getting a copy.
            $expiresAt = Carbon::create(2024, 12, 31, 23, 59, 59);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'access_token',
                refreshToken: 'refresh_token',
                expiresAt: $expiresAt,
            );

            expect($client->getTokens()->expiresAt)->toBe($expiresAt);
        });
    });
});
