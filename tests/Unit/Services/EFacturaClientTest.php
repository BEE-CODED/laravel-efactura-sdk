<?php

declare(strict_types=1);

use BeeCoded\EFactura\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFactura\Data\Auth\OAuthTokensData;
use BeeCoded\EFactura\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFactura\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFactura\Data\Invoice\UploadOptionsData;
use BeeCoded\EFactura\Enums\DocumentStandardType;
use BeeCoded\EFactura\Exceptions\ValidationException;
use BeeCoded\EFactura\Services\ApiClients\EFacturaClient;
use BeeCoded\EFactura\Services\RateLimiter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

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
    describe('constructor and fromTokens', function () {
        it('creates client with constructor', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'test-access-token',
                refreshToken: 'test-refresh-token',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->getVatNumber())->toBe('12345678');
            expect($client->wasTokenRefreshed())->toBeFalse();
        });

        it('creates client from OAuthTokensData', function () {
            $tokens = new OAuthTokensData(
                accessToken: 'access-token',
                refreshToken: 'refresh-token',
                expiresAt: Carbon::now()->addHour(),
            );

            $client = EFacturaClient::fromTokens('87654321', $tokens);

            expect($client->getVatNumber())->toBe('87654321');
            expect($client->getTokens()->accessToken)->toBe('access-token');
        });
    });

    describe('getTokens', function () {
        it('returns current tokens', function () {
            $expiresAt = Carbon::now()->addHour();

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'access',
                refreshToken: 'refresh',
                expiresAt: $expiresAt,
            );

            $tokens = $client->getTokens();

            expect($tokens->accessToken)->toBe('access');
            expect($tokens->refreshToken)->toBe('refresh');
            expect($tokens->expiresAt)->toBe($expiresAt);
        });
    });

    describe('static methods', function () {
        it('getBaseUrl returns API URL', function () {
            expect(EFacturaClient::getBaseUrl())->toBeString();
        });

        it('getTimeoutDuration returns timeout value', function () {
            expect(EFacturaClient::getTimeoutDuration())->toBeNumeric();
        });

        it('getLogger returns logger instance', function () {
            expect(EFacturaClient::getLogger())->toBeInstanceOf(Psr\Log\LoggerInterface::class);
        });
    });

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
            config()->set('efactura.endpoints.services.validate', null);

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
            config()->set('efactura.endpoints.services.verify_signature', null);

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
            config()->set('efactura.endpoints.services.transform', null);

            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            $client->convertXmlToPdf('<xml/>', DocumentStandardType::FACT1);
        })->throws(ValidationException::class, 'Missing configuration');
    });

    describe('getRateLimiter', function () {
        it('returns rate limiter instance', function () {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );

            expect($client->getRateLimiter())->toBeInstanceOf(RateLimiter::class);
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
});
