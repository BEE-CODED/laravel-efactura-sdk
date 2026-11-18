<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\EFacturaServiceProvider;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeKind;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RequestNotSentException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\AnafAuthenticator;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\UploadOutcome;
use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

/** A log handler that fails every write, like a daily file another user created. */
class FailingLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new UnexpectedValueException('simulated: log write failed');
    }
}

function transportClient(): EFacturaClient
{
    return new EFacturaClient(
        vatNumber: '12345678',
        accessToken: 'token',
        refreshToken: 'refresh',
        expiresAt: Carbon::now()->addHour(),
    );
}

beforeEach(function () {
    $rateLimiter = Mockery::mock(RateLimiter::class);
    $rateLimiter->shouldIgnoreMissing();
    app()->instance(RateLimiter::class, $rateLimiter);
    app()->instance(AnafAuthenticatorInterface::class, Mockery::mock(AnafAuthenticatorInterface::class));

    config()->set('efactura-sdk.http.retry_delay', 0);
    config()->set('efactura-sdk.http.retry_times', 3);
});

describe('a failing log channel', function () {
    beforeEach(function () {
        // A real LogManager channel whose Monolog handler throws on every write.
        config()->set('logging.channels.failing', ['driver' => 'monolog', 'handler' => FailingLogHandler::class]);
        config()->set('efactura-sdk.logging.channel', 'failing');
    });

    it('never costs an upload its index', function () {
        Http::fake(['*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="5001234567"/>', 200)]);

        expect(transportClient()->uploadDocument('<Invoice/>')->indexIncarcare)->toBe('5001234567');
        Http::assertSentCount(1);
    });

    it('never re-sends a read', function () {
        Http::fake(['*' => Http::response('<?xml version="1.0"?><header stare="ok" id_descarcare="1"/>', 200)]);

        expect(transportClient()->getStatusMessage('12345')->idDescarcare)->toBe('1');
        Http::assertSentCount(1);
    });

    it('never costs a company lookup its result', function () {
        Http::fake(['*' => Http::response(['found' => [], 'notFound' => [18547290]], 200)]);

        $result = (new AnafDetailsClient)->getCompanyData('RO18547290');

        expect($result->success)->toBeTrue()
            ->and($result->notFound)->toBe([18547290]);
        Http::assertSentCount(1);
    });

    it('never turns a refused refresh into a log error', function () {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $authenticator = new AnafAuthenticator(Http::getFacadeRoot(), [
            'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.com/callback'],
            'endpoints' => ['oauth' => ['authorize' => 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize', 'token' => 'https://logincert.anaf.ro/anaf-oauth2/v1/token']],
        ]);

        expect(fn () => $authenticator->refreshAccessToken('refresh'))
            ->toThrow(AuthenticationException::class);
    });

    it('never fails a rate limiter over its cache-driver warning', function () {
        config()->set('cache.default', 'array');

        expect(new RateLimiter)->toBeInstanceOf(RateLimiter::class);
    });
});

/**
 * Fail the request the way Guzzle's CurlFactory does, so Laravel's own
 * PendingRequest turns it into the ConnectionException a real failure produces.
 *
 * @param  array<string, mixed>  $info  curl_getinfo() fields of the failed handle (Guzzle 7 only;
 *                                      Guzzle 8 attaches none and picks the class instead)
 */
function curlRejection(int $errno, string $error, array $info = []): Closure
{
    return function ($request) use ($errno, $error, $info) {
        $message = sprintf('cURL error %d: %s (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for %s', $errno, $error, $request->url());
        $psr = new Request($request->method(), $request->url());
        $context = ['errno' => $errno, 'error' => $error] + $info;

        if (class_exists(ConnectTimeoutException::class)) {
            // Guzzle 8: a timeout is a connect timeout only for its connect-phase error texts.
            return Create::rejectionFor(match (true) {
                $errno === 28 && str_contains($error, 'Connection timed out') => new ConnectTimeoutException($message, $psr),
                $errno === 28 => new NetworkTimeoutException($message, $psr),
                default => new ConnectException($message, $psr),
            });
        }

        return Create::rejectionFor($errno === 5
            ? new RequestException($message, $psr, null, null, $context)
            : new ConnectException($message, $psr, null, $context));
    };
}

describe('a failure before the request left this machine', function () {
    $connectPhase = ['request_size' => 0, 'connect_time' => 0.0, 'pretransfer_time' => 0.0];

    it('is a RequestNotSentException after the upload retries', function (int $errno, string $error, array $info) {
        Http::fake(['*' => curlRejection($errno, $error, $info)]);

        expect(fn () => transportClient()->uploadDocument('<Invoice/>'))
            ->toThrow(fn (RequestNotSentException $e) => expect($e->statusCode)->toBe(500));
        Http::assertSentCount(3);
    })->with([
        'proxy resolution (5)' => [5, 'Could not resolve proxy: proxy.local', []],
        'DNS (6)' => [6, 'Could not resolve host: api.anaf.ro', ['request_size' => 0]],
        'connect refused (7)' => [7, 'Failed to connect to api.anaf.ro port 443', []],
        'TLS handshake (35)' => [35, 'SSL connect error', []],
        'connect-phase timeout (28)' => [28, 'Connection timed out after 10001 milliseconds', $connectPhase],
    ]);

    it('reads a blank, zero or negative retry setting as its default or floor', function (mixed $retryTimes, mixed $retryDelay, bool $atBoot, int $sent) {
        config()->set('efactura-sdk.http.retry_times', $retryTimes);
        config()->set('efactura-sdk.http.retry_delay', $retryDelay);
        if ($atBoot) {
            $limiter = app(RateLimiter::class);
            (new EFacturaServiceProvider(app()))->register();
            // register() rebinds the singletons: put the mocks back.
            app()->instance(RateLimiter::class, $limiter);
            app()->instance(AnafAuthenticatorInterface::class, Mockery::mock(AnafAuthenticatorInterface::class));
        }
        Http::fake(['*' => curlRejection(6, 'Could not resolve host: api.anaf.ro', ['request_size' => 0])]);

        expect(fn () => transportClient()->uploadDocument('<Invoice/>'))->toThrow(RequestNotSentException::class);
        Http::assertSentCount($sent);
    })->with([
        'blank retry_times at boot' => ['', 0, true, 3],
        "retry_times '0' at boot" => ['0', 0, true, 1],
        'negative retry_delay at runtime' => [3, -1, false, 3],
    ]);

    it('is a RequestNotSentException for a read too', function () use ($connectPhase) {
        Http::fake(['*' => curlRejection(28, 'Connection timed out after 10001 milliseconds', $connectPhase)]);

        expect(fn () => transportClient()->getStatusMessage('12345'))->toThrow(RequestNotSentException::class);
        Http::assertSentCount(3);
    });

    it('is not claimed for a read once an earlier attempt may have been delivered', function () {
        $sentTimeout = curlRejection(28, 'Operation timed out after 30001 milliseconds with 0 bytes received', ['request_size' => 412, 'pretransfer_time' => 0.08]);
        $dns = curlRejection(6, 'Could not resolve host: api.anaf.ro', ['request_size' => 0]);
        $attempts = 0;
        Http::fake(['*' => function ($request) use (&$attempts, $sentTimeout, $dns) {
            return ++$attempts === 1 ? $sentTimeout($request) : $dns($request);
        }]);

        expect(fn () => transportClient()->getStatusMessage('12345'))
            ->toThrow(fn (ApiException $e) => expect($e)->not->toBeInstanceOf(RequestNotSentException::class)
                ->and($e->statusCode)->toBe(500));
        Http::assertSentCount(3);
    });

    it('is not claimed for a timeout once the request was sent', function (array $info) {
        Http::fake(['*' => curlRejection(28, 'Operation timed out after 30001 milliseconds with 0 bytes received', $info)]);

        try {
            transportClient()->uploadDocument('<Invoice/>');
            $this->fail('The upload should have failed.');
        } catch (ApiException $e) {
            expect($e)->not->toBeInstanceOf(RequestNotSentException::class)
                ->and($e->statusCode)->toBe(500);
        }
        Http::assertSentCount(1);
    })->with([
        'read timeout' => [['request_size' => 412, 'connect_time' => 0.02, 'pretransfer_time' => 0.08]],
        'request written, no transfer timing' => [['request_size' => 412, 'pretransfer_time' => 0.0]],
        'connected, nothing written' => [['request_size' => 0, 'pretransfer_time' => 0.08]],
        'no handler timings' => [[]],
    ]);
});

describe('an error answer after the retries', function () {
    it('reports a server error as 502 and passes a client error through, on both transport paths', function (Closure $call, int $answer, int $statusCode, int $sent) {
        Http::fake(['*' => Http::response(['eroare' => 'x'], $answer)]);

        expect(fn () => $call(transportClient()))
            ->toThrow(fn (ApiException $e) => expect($e)->not->toBeInstanceOf(AuthenticationException::class)
                ->and($e->statusCode)->toBe($statusCode));
        Http::assertSentCount($sent);
    })->with([
        'status (call), retried' => [fn (EFacturaClient $c) => $c->getStatusMessage('12345'), 503, 502, 3],
        'upload (callRaw), sent once' => [fn (EFacturaClient $c) => $c->uploadDocument('<Invoice/>'), 503, 502, 1],
        'validate (callRaw to a URL)' => [fn (EFacturaClient $c) => $c->validateXml('<Invoice/>', DocumentStandardType::FACT1), 404, 404, 1],
    ]);
});

describe('the access token in an exception trace', function () {
    it('keeps the bearer token out of the trace of an API failure', function (Closure $call, Closure $answer) {
        Http::fake(['*' => $answer]);
        $old = ini_set('zend.exception_ignore_args', '0');

        try {
            $client = new EFacturaClient(
                vatNumber: '12345678',
                accessToken: 'secret-access-token',
                refreshToken: 'refresh',
                expiresAt: Carbon::now()->addHour(),
            );
            $call($client);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            // Only this exception's own trace: a chained exception's Laravel frames hold the headers.
            $found = false;
            $trace = $e->getTrace();
            array_walk_recursive($trace, function (mixed $value) use (&$found) {
                $found = $found || (is_string($value) && str_contains($value, 'secret-access-token'));
            });
            expect($found)->toBeFalse();
        } finally {
            ini_set('zend.exception_ignore_args', (string) $old);
        }
    })->with([
        'status (call), 503' => [fn (EFacturaClient $c) => $c->getStatusMessage('12345'), fn () => Http::response(['eroare' => 'x'], 503)],
        'upload (callRaw), 400' => [fn (EFacturaClient $c) => $c->uploadDocument('<Invoice/>'), fn () => Http::response(['eroare' => 'x'], 400)],
        'upload (callRaw), read timeout' => [fn (EFacturaClient $c) => $c->uploadDocument('<Invoice/>'), fn ($request) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out', new Request('POST', $request->url())))],
    ]);
});

describe('the ANAF service endpoints', function () {
    it('keeps an absolute URL as the request URL', function () {
        expect(transportClient()->getRequestUrl('https://webservicesp.anaf.ro/prod/FCTEL/rest/validare/FACT1'))
            ->toBe('https://webservicesp.anaf.ro/prod/FCTEL/rest/validare/FACT1')
            ->and(transportClient()->getRequestUrl('/stareMesaj'))->toBe('https://api.anaf.ro/test/FCTEL/rest/stareMesaj');
    });

    it('sends validate and PDF through the shared transport', function (Closure $call, string $url) {
        config()->set('efactura-sdk.logging.debug', true);
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($logger);
        Http::fake([
            '*/transformare/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
            '*' => Http::response(['stare' => 'ok'], 200),
        ]);

        $call(transportClient());

        Http::assertSent(fn ($request) => $request->url() === $url
            && $request->header('Content-Type') === ['text/plain']
            && ! $request->hasHeader('Authorization')
            && $request->body() === '<Invoice/>');
        // Only the shared request() logs the body lengths.
        $logger->shouldHaveReceived('debug')->withArgs(
            fn (string $message, array $context) => str_starts_with($message, 'POST '.$url) && ($context['requestBodyLength'] ?? null) === 10
        )->once();
    })->with([
        'validate' => [fn (EFacturaClient $c) => $c->validateXml('<Invoice/>', DocumentStandardType::FACT1), 'https://webservicesp.anaf.ro/prod/FCTEL/rest/validare/FACT1'],
        'pdf' => [fn (EFacturaClient $c) => $c->convertXmlToPdf('<Invoice/>', DocumentStandardType::FACT1), 'https://webservicesp.anaf.ro/prod/FCTEL/rest/transformare/FACT1/DA'],
    ]);

    it('sends the invoice and its signature to ANAF signature validation as multipart files', function () {
        config()->set('efactura-sdk.logging.debug', true);
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($logger);
        // Only the request is checked: ANAF documents no answer for this service.
        Http::fake(['*' => Http::response([], 200)]);
        $signature = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo/></ds:Signature>';
        $url = 'https://webservicesp.anaf.ro/api/validate/signature';

        transportClient()->verifySignature('<Invoice/>', $signature);

        Http::assertSent(function ($request) use ($signature, $url) {
            // Read the parts the way a multipart server does: split on the declared boundary.
            if (preg_match('/^multipart\/form-data; boundary=(.+)$/', $request->header('Content-Type')[0] ?? '', $match) !== 1) {
                return false;
            }
            $parts = [];
            foreach (array_slice(explode("--{$match[1]}", $request->body()), 1, -1) as $part) {
                [$head, $content] = explode("\r\n\r\n", substr($part, 2), 2);
                preg_match('/Content-Disposition: form-data; name="([^"]+)"; filename="[^"]+"/', $head, $name);
                $parts[$name[1] ?? '(no name or filename)'] = substr($content, 0, -2);
            }

            return $request->method() === 'POST'
                && $request->url() === $url
                && $request->isMultipart()
                && ! $request->hasHeader('Authorization')
                && $parts === ['file' => '<Invoice/>', 'signature' => $signature]
                && str_ends_with($request->body(), "--{$match[1]}--\r\n");
        });
        Http::assertSentCount(1);
        $logger->shouldHaveReceived('debug')->withArgs(fn (string $message) => str_starts_with($message, 'POST '.$url))->once();
    });

    it('sends no bearer and refreshes nothing for ANAF\'s public validation service', function () {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(['stare' => 'ok'], 200)]);
        // Expired: a refresh would call the bare authenticator mock and throw.
        $client = new EFacturaClient(vatNumber: '12345678', accessToken: 'token', refreshToken: 'refresh', expiresAt: Carbon::now()->subMinute());

        $client->validateXml('<Invoice/>', DocumentStandardType::FACT1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'webservicesp.anaf.ro') && ! $request->hasHeader('Authorization'));
    });

    it('keeps the bearer for a service URL on the e-Factura API host', function () {
        config()->set('efactura-sdk.endpoints.services.verify_signature', 'https://api.anaf.ro/api/validate/signature');
        Http::fake(['*' => Http::response([], 200)]);

        transportClient()->verifySignature('<Invoice/>', '<Signature/>');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token'));
    });

    it('uploads to the shipped API URL when a host config overrides only part of endpoints', function () {
        config()->set('efactura-sdk.endpoints', ['services' => config('efactura-sdk.endpoints.services')]);
        Http::fake(['api.anaf.ro/test/FCTEL/rest/upload*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="5001"/>', 200)]);

        expect(transportClient()->uploadDocument('<Invoice/>')->indexIncarcare)->toBe('5001');
        Http::assertSentCount(1);
    });

    it('refuses a blank invoice or signature before spending quota', function (string $xml, string $signature) {
        $rateLimiter = Mockery::mock(RateLimiter::class);
        $rateLimiter->shouldNotReceive('checkGlobal');
        app()->instance(RateLimiter::class, $rateLimiter);
        Http::fake();

        expect(fn () => transportClient()->verifySignature($xml, $signature))->toThrow(ValidationException::class);
        Http::assertNothingSent();
    })->with([
        'blank invoice' => [' ', '<ds:Signature/>'],
        'blank signature' => ['<Invoice/>', ''],
    ]);

    it('refuses a body without a content type through call() at once, unsent and not retried', function () {
        Http::fake(['*' => Http::response('ok', 200)]);
        $client = new class extends AnafDetailsClient
        {
            public function send(): void
            {
                $this->call('', 'post', ['cui' => 1]);
            }
        };

        expect(fn () => $client->send())->toThrow(LogicException::class, 'A request body needs a content type');
        Http::assertNothingSent();
    });

    it('does not follow a redirect on an upload', function () {
        Http::fake([
            'api.anaf.ro/*' => Http::response('', 302, ['Location' => 'https://elsewhere.invalid/upload']),
            'elsewhere.invalid/*' => curlRejection(6, 'Could not resolve host: elsewhere.invalid', ['request_size' => 0]),
        ]);

        try {
            transportClient()->uploadDocument('<Invoice/>');
            $this->fail('The upload should have failed.');
        } catch (ApiException $e) {
            expect($e)->not->toBeInstanceOf(RequestNotSentException::class)
                ->and($e->statusCode)->toBe(302)
                ->and(UploadOutcome::fromThrowable($e)->kind)->toBe(UploadOutcomeKind::Uncertain);
        }
        Http::assertSentCount(1);
    });
});
