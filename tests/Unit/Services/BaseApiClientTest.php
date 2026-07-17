<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Services\ApiClients\BaseApiClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
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

    // Expose protected method for testing
    public function testIsRetryableException(Throwable $exception, bool $idempotent): bool
    {
        return $this->isRetryableException($exception, $idempotent);
    }
}

describe('BaseApiClient', function () {
    describe('isRetryable', function () {
        it('returns true for a synthesised status 0, the defensive guard', function () {
            // NB: this does NOT cover connection failures, despite how the status-0
            // check reads. A real connection failure produces no Response at all --
            // Laravel raises a ConnectionException that call()/callRaw() catch and
            // route to isRetryableException() instead. A Response always wraps a PSR-7
            // response, whose status is a real HTTP code, so 0 has to be mocked to get
            // here. This pins the guard's behaviour, not a reachable code path.
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

    describe('isRetryableException', function () {
        // Guzzle raises ConnectException for cURL 28 (timeout) and 52 (empty reply)
        // just as it does for 6 (DNS) and 7 (refused) -- see CurlFactory's
        // $connectionErrors map. The class therefore proves nothing about whether
        // the request was delivered; only the errno does.

        $connectionException = function (int $errno, string $error): ConnectionException {
            $message = sprintf('cURL error %d: %s (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', $errno, $error);

            return new ConnectionException(
                $message,
                0,
                new ConnectException($message, new Request('POST', 'https://api.anaf.ro/'), null, ['errno' => $errno, 'error' => $error])
            );
        };

        it('retries any transport failure for an idempotent call', function () use ($connectionException) {
            $client = new TestableBaseApiClient;

            expect($client->testIsRetryableException($connectionException(28, 'Operation timed out'), true))->toBeTrue();
            expect($client->testIsRetryableException(new RuntimeException('anything'), true))->toBeTrue();
        });

        it('retries provably pre-send cURL errors for a non-idempotent call', function (int $errno, string $error) use ($connectionException) {
            $client = new TestableBaseApiClient;

            expect($client->testIsRetryableException($connectionException($errno, $error), false))->toBeTrue();
        })->with([
            'CURLE_COULDNT_RESOLVE_PROXY' => [5, 'Could not resolve proxy'],
            'CURLE_COULDNT_RESOLVE_HOST' => [6, 'Could not resolve host: api.anaf.ro'],
            'CURLE_COULDNT_CONNECT' => [7, 'Failed to connect to api.anaf.ro port 443: Connection refused'],
            'CURLE_SSL_CONNECT_ERROR' => [35, 'SSL connect error'],
        ]);

        it('refuses to retry possibly-delivered cURL errors for a non-idempotent call', function (int $errno, string $error) use ($connectionException) {
            $client = new TestableBaseApiClient;

            expect($client->testIsRetryableException($connectionException($errno, $error), false))->toBeFalse();
        })->with([
            'CURLE_OPERATION_TIMEDOUT (body may already be filed)' => [28, 'Operation timed out after 30001 milliseconds'],
            'CURLE_GOT_NOTHING (server hung up after accepting)' => [52, 'Empty reply from server'],
            'CURLE_SEND_ERROR (partial send, unknowable)' => [55, 'Failed sending data to the peer'],
            'CURLE_RECV_ERROR (failed mid-response)' => [56, 'Failure when receiving data from the peer'],
        ]);

        it('refuses to retry an unclassifiable exception for a non-idempotent call', function () {
            $client = new TestableBaseApiClient;

            expect($client->testIsRetryableException(new ConnectionException('no errno here'), false))->toBeFalse();
            expect($client->testIsRetryableException(new RuntimeException('anything'), false))->toBeFalse();
        });

        it('reads the errno from the message when no handler context survives', function () {
            // Laravel copies Guzzle's message verbatim, so the errno is recoverable
            // even from a bare ConnectionException with no previous.
            $client = new TestableBaseApiClient;

            expect($client->testIsRetryableException(
                new ConnectionException('cURL error 6: Could not resolve host: api.anaf.ro'),
                false
            ))->toBeTrue();

            expect($client->testIsRetryableException(
                new ConnectionException('cURL error 28: Operation timed out'),
                false
            ))->toBeFalse();
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
