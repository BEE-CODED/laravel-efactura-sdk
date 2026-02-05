<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Services\ApiClients\BaseApiClient;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;

/**
 * Test subclass to expose protected methods for testing.
 */
class TestableBaseApiClient extends BaseApiClient
{
    public static function getBaseUrl(): string
    {
        return 'https://example.com/api';
    }

    public static function getTimeoutDuration(): float|int
    {
        return 30;
    }

    protected function getHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public static function getLogger(): LoggerInterface
    {
        return Mockery::mock(LoggerInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('debug')->andReturn(null);
            $mock->shouldReceive('error')->andReturn(null);
        });
    }

    // Expose protected method for testing
    public function testIsRetryable(Response $response): bool
    {
        return $this->isRetryable($response);
    }

    // Expose protected method for testing
    public function testExtractErrorMessage(Response $response): string
    {
        return $this->extractErrorMessage($response);
    }
}

describe('BaseApiClient', function () {
    describe('isRetryable', function () {
        it('returns true for status code 0 (connection failure)', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(0);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeTrue();
        });

        it('returns true for 500 Internal Server Error', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(500);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeTrue();
        });

        it('returns true for 502 Bad Gateway', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(502);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeTrue();
        });

        it('returns true for 503 Service Unavailable', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(503);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeTrue();
        });

        it('does NOT retry 429 Too Many Requests', function () {
            // Bug fix: 429 is NOT retried because:
            // 1. Client-side rate limiting should prevent most 429s
            // 2. Blind retry without Retry-After header is counterproductive
            // 3. If client-side limits are wrong/disabled, failing fast is better
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(429);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeFalse();
        });

        it('does NOT retry 400 Bad Request', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(400);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeFalse();
        });

        it('does NOT retry 401 Unauthorized', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(401);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeFalse();
        });

        it('does NOT retry 403 Forbidden', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(403);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeFalse();
        });

        it('does NOT retry 404 Not Found', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('status')->andReturn(404);

            $client = new TestableBaseApiClient;

            expect($client->testIsRetryable($response))->toBeFalse();
        });
    });

    describe('extractErrorMessage', function () {
        it('extracts message from "message" key', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->with('message')->andReturn('Error message from message key');
            $response->shouldReceive('json')->with('eroare')->andReturn(null);
            $response->shouldReceive('json')->with('error')->andReturn(null);

            $client = new TestableBaseApiClient;

            expect($client->testExtractErrorMessage($response))->toBe('Error message from message key');
        });

        it('extracts message from "eroare" key (Romanian)', function () {
            // Bug fix: Now uses standardized error extraction with Romanian key support
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->with('message')->andReturn(null);
            $response->shouldReceive('json')->with('eroare')->andReturn('Eroare de validare XML');
            $response->shouldReceive('json')->with('error')->andReturn(null);

            $client = new TestableBaseApiClient;

            expect($client->testExtractErrorMessage($response))->toBe('Eroare de validare XML');
        });

        it('extracts message from "error" key', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->with('message')->andReturn(null);
            $response->shouldReceive('json')->with('eroare')->andReturn(null);
            $response->shouldReceive('json')->with('error')->andReturn('Generic error');

            $client = new TestableBaseApiClient;

            expect($client->testExtractErrorMessage($response))->toBe('Generic error');
        });

        it('returns default message when no error keys found', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->with('message')->andReturn(null);
            $response->shouldReceive('json')->with('eroare')->andReturn(null);
            $response->shouldReceive('json')->with('error')->andReturn(null);

            $client = new TestableBaseApiClient;

            expect($client->testExtractErrorMessage($response))->toBe('No error message in API response');
        });

        it('prioritizes message over eroare', function () {
            // Tests that keys are checked in correct order
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->with('message')->andReturn('Primary error');
            // Other keys should not be checked due to null coalescing

            $client = new TestableBaseApiClient;

            expect($client->testExtractErrorMessage($response))->toBe('Primary error');
        });
    });
});
