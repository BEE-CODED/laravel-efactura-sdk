<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Response\UploadResponseData;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeKind;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeReason;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\RequestNotSentException;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\UploadOutcome;
use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->rateLimiter = Mockery::mock(RateLimiter::class);
    $this->rateLimiter->shouldIgnoreMissing();
    $this->authenticator = Mockery::mock(AnafAuthenticatorInterface::class);
    app()->instance(RateLimiter::class, $this->rateLimiter);
    app()->instance(AnafAuthenticatorInterface::class, $this->authenticator);

    config()->set('efactura-sdk.http.retry_delay', 0);
});

/** Upload through the real client and classify what it returned or threw. */
function uploadOutcome(string $xml = '<Invoice/>', ?Carbon $expiresAt = null): UploadOutcome
{
    $client = new EFacturaClient('12345678', 'token', 'refresh', $expiresAt ?? Carbon::now()->addHour());

    try {
        return UploadOutcome::fromResponse($client->uploadDocument($xml));
    } catch (Throwable $e) {
        return UploadOutcome::fromThrowable($e);
    }
}

function outcomeParts(UploadOutcome $outcome): array
{
    return [$outcome->kind, $outcome->reason, $outcome->index, $outcome->retryAfterSeconds, $outcome->providerText];
}

describe('an upload ANAF answered', function () {
    it('is classified from the answer', function (string $body, array $expected) {
        Http::fake(['*' => Http::response($body, 200)]);

        expect(outcomeParts(uploadOutcome()))->toBe($expected);
    })->with([
        'an index' => [
            '<header ExecutionStatus="0" index_incarcare="5001234567"/>',
            [UploadOutcomeKind::Accepted, null, '5001234567', null, null],
        ],
        'an index despite status 1' => [
            '<header ExecutionStatus="1" index_incarcare="5001234567"/>',
            [UploadOutcomeKind::Accepted, null, '5001234567', null, null],
        ],
        'status 1 without an index' => [
            '<header ExecutionStatus="1"><Errors errorMessage="Fisierul transmis nu este valid."/></header>',
            [UploadOutcomeKind::Refused, UploadOutcomeReason::FailedWithoutIndex, null, null, 'Fisierul transmis nu este valid.'],
        ],
        'status 1 without an index or a reason' => [
            '<header ExecutionStatus="1"/>',
            [UploadOutcomeKind::Refused, UploadOutcomeReason::FailedWithoutIndex, null, null, null],
        ],
        'status 0 without an index' => [
            '<header ExecutionStatus="0"/>',
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
        'status 0 with an index that is not a number' => [
            '<header ExecutionStatus="0" index_incarcare="5001-234"/>',
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
        'status 1 with an index that is not a number' => [
            '<header ExecutionStatus="1" index_incarcare="abc"/>',
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
        'an unrecognised status without an index' => [
            '<header ExecutionStatus="2"/>',
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
    ]);

    it('refuses an index longer than 64 digits', function () {
        $outcome = UploadOutcome::fromResponse(UploadResponseData::fromAnafResponse([
            'ExecutionStatus' => 0,
            'index_incarcare' => str_repeat('1', 65),
        ]));

        expect($outcome->kind)->toBe(UploadOutcomeKind::Uncertain);
    });
});

describe('an upload that threw', function () {
    it('is classified from the throwable', function (Closure $arrange, array $expected) {
        $arrange($this);

        expect(outcomeParts(uploadOutcome()))->toBe($expected);
    })->with([
        'a failure before sending' => [
            fn () => Http::fake(['*' => fn ($request) => Create::rejectionFor(new ConnectException(
                'cURL error 6: Could not resolve host: api.anaf.ro',
                new Request('POST', $request->url()),
                null,
                ['errno' => 6, 'error' => 'Could not resolve host'],
            ))]),
            [UploadOutcomeKind::NotSent, UploadOutcomeReason::Transport, null, null, null],
        ],
        'the SDK rate limit' => [
            fn ($test) => $test->rateLimiter->shouldReceive('checkGlobal')->andThrow(
                new RateLimitExceededException('limit', retryAfterSeconds: 17, scope: RateLimitScope::Global)
            ),
            [UploadOutcomeKind::NotSent, UploadOutcomeReason::RateLimited, null, 17, null],
        ],
        'ANAF 429' => [
            fn () => Http::fake(['*' => Http::response('Too Many Requests', 429)]),
            [UploadOutcomeKind::NotSent, UploadOutcomeReason::RateLimited, null, null, null],
        ],
        'ANAF 400 with a reason' => [
            fn () => Http::fake(['*' => Http::response(['message' => 'CIF invalid'], 400)]),
            [UploadOutcomeKind::Refused, UploadOutcomeReason::Rejected, null, null, 'CIF invalid'],
        ],
        'ANAF 403 without a reason' => [
            fn () => Http::fake(['*' => Http::response('', 403)]),
            [UploadOutcomeKind::Refused, UploadOutcomeReason::Rejected, null, null, null],
        ],
        'ANAF 401' => [
            fn () => Http::fake(['*' => Http::response('', 401)]),
            [UploadOutcomeKind::NotSent, UploadOutcomeReason::GrantRefused, null, null, null],
        ],
        'ANAF 408' => [
            fn () => Http::fake(['*' => Http::response('Request Timeout', 408)]),
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
        'ANAF 5xx' => [
            fn () => Http::fake(['*' => Http::response('Server Error', 503)]),
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
        'an unreadable 2xx' => [
            fn () => Http::fake(['*' => Http::response('maintenance', 200)]),
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
        'a read timeout' => [
            fn () => Http::fake(['*' => fn ($request) => Create::rejectionFor(new ConnectException(
                'cURL error 28: Operation timed out after 30001 milliseconds',
                new Request('POST', $request->url()),
                null,
                ['errno' => 28, 'error' => 'Operation timed out', 'request_size' => 412, 'pretransfer_time' => 0.08],
            ))]),
            [UploadOutcomeKind::Uncertain, null, null, null, null],
        ],
    ]);

    it('reads the local refusal from the SDK validation', function () {
        expect(outcomeParts(uploadOutcome('  ')))
            ->toBe([UploadOutcomeKind::Refused, UploadOutcomeReason::LocalRefusal, null, null, null]);
    });

    it('classifies a refused token refresh by its code', function (int $code, UploadOutcomeReason $reason) {
        $this->authenticator->shouldReceive('refreshAccessToken')->andThrow(new AuthenticationException('refresh failed', $code));
        Http::fake();

        expect(outcomeParts(uploadOutcome(expiresAt: Carbon::now()->subMinute())))
            ->toBe([UploadOutcomeKind::NotSent, $reason, null, null, null]);
        Http::assertNothingSent();
    })->with([
        'grant refused (400)' => [400, UploadOutcomeReason::GrantRefused],
        'grant refused (401)' => [401, UploadOutcomeReason::GrantRefused],
        'transport or configuration (0)' => [0, UploadOutcomeReason::Transient],
        'OAuth server error (503)' => [503, UploadOutcomeReason::Transient],
    ]);

    it('is uncertain for anything it does not know', function () {
        expect(UploadOutcome::fromThrowable(new RuntimeException('?'))->kind)->toBe(UploadOutcomeKind::Uncertain);
    });

    it('keeps no wait for a provider rate limit, which carries only the SDK estimate', function () {
        // Unreachable from an upload today (ANAF's 429 on an upload is ApiException 429);
        // only a company lookup throws fromProvider: true.
        $outcome = UploadOutcome::fromThrowable(new RateLimitExceededException('limit', retryAfterSeconds: 2, scope: RateLimitScope::CompanyLookup, fromProvider: true));

        expect(outcomeParts($outcome))->toBe([UploadOutcomeKind::NotSent, UploadOutcomeReason::RateLimited, null, null, null]);
    });
});

describe('the SDK rate-limit metering', function () {
    it('fails before sending as not sent when its cache store fails', function () {
        $failure = new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
        $this->rateLimiter->shouldReceive('checkGlobal')->andThrow($failure);
        Http::fake();

        $client = new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour());

        expect(fn () => $client->uploadDocument('<Invoice/>'))
            ->toThrow(fn (RequestNotSentException $e) => expect($e->getPrevious())->toBe($failure)
                ->and(outcomeParts(UploadOutcome::fromThrowable($e)))->toBe([UploadOutcomeKind::NotSent, UploadOutcomeReason::Transport, null, null, null]));
        Http::assertNothingSent();
    });

    it('fails a retry of an unsent upload as not sent when its cache store fails', function () {
        $calls = 0;
        $this->rateLimiter->shouldReceive('checkGlobal')->andReturnUsing(function () use (&$calls) {
            if (++$calls > 1) {
                throw new RuntimeException('Connection refused');
            }
        });
        Http::fake(['*' => fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 6: Could not resolve host: api.anaf.ro',
            new Request('POST', $request->url()),
            null,
            ['errno' => 6, 'error' => 'Could not resolve host'],
        ))]);

        expect(outcomeParts(uploadOutcome()))->toBe([UploadOutcomeKind::NotSent, UploadOutcomeReason::Transport, null, null, null]);
        Http::assertSentCount(1);
    });

    it('reads a failed retry metering as possibly sent once ANAF answered an earlier attempt', function () {
        $failure = new RuntimeException('Connection refused');
        $calls = 0;
        $this->rateLimiter->shouldReceive('checkGlobal')->andReturnUsing(function () use (&$calls, $failure) {
            if (++$calls > 1) {
                throw $failure;
            }
        });
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $client = new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour());

        // The 502 reached ANAF (and counts there): possibly sent, so ApiException(500), never RequestNotSentException.
        expect(fn () => $client->getStatusMessage('12345'))->toThrow(fn (ApiException $e) => expect($e)->not->toBeInstanceOf(RequestNotSentException::class)
            ->and($e->statusCode)->toBe(500)
            ->and($e->getPrevious())->toBe($failure));
        Http::assertSentCount(1);
    });

    it('starts every call on a reused client as undelivered', function (string $second) {
        $unreachable = fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 6: Could not resolve host: api.anaf.ro',
            new Request($request->method(), $request->url()),
            null,
            ['errno' => 6, 'error' => 'Could not resolve host'],
        ));
        $reads = 0;
        Http::fake(['*' => function ($request) use (&$reads, $unreachable) {
            // The first read reaches ANAF (502); everything after it never leaves.
            return str_contains($request->url(), 'stareMesaj') && ++$reads === 1 ? Http::response('Bad Gateway', 502) : $unreachable($request);
        }]);
        $client = new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour());
        config()->set('efactura-sdk.http.retry_times', 1);

        expect(fn () => $client->getStatusMessage('12345'))->toThrow(ApiException::class);

        $call = $second === 'upload' ? fn () => $client->uploadDocument('<Invoice/>') : fn () => $client->getStatusMessage('12345');
        expect($call)->toThrow(RequestNotSentException::class);
    })->with(['an upload' => 'upload', 'another read' => 'read']);

    it('still reads a failed retry metering as not sent when no earlier attempt was delivered', function () {
        $calls = 0;
        $this->rateLimiter->shouldReceive('checkGlobal')->andReturnUsing(function () use (&$calls) {
            if (++$calls > 1) {
                throw new RuntimeException('Connection refused');
            }
        });
        Http::fake(['*' => fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 6: Could not resolve host: api.anaf.ro',
            new Request('GET', $request->url()),
            null,
            ['errno' => 6, 'error' => 'Could not resolve host'],
        ))]);

        $client = new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour());

        expect(fn () => $client->getStatusMessage('12345'))->toThrow(RequestNotSentException::class);
        Http::assertSentCount(1);
    });

    it('lets a programming error through as itself', function () {
        $this->rateLimiter->shouldReceive('checkGlobal')->andThrow(new InvalidArgumentException('bad limiter input'));
        Http::fake();

        expect(fn () => (new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour()))->uploadDocument('<Invoice/>'))
            ->toThrow(InvalidArgumentException::class, 'bad limiter input');
        Http::assertNothingSent();
    });
});

describe('the token refresh lock', function () {
    it('fails before sending as not sent when its cache store fails', function (bool $onCreate) {
        $failure = new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow($failure);
        // A store that is down fails the release too; that must not replace the outcome.
        $lock->shouldReceive('release')->andThrow($failure);
        $onCreate
            ? Cache::shouldReceive('lock')->andThrow($failure)
            : Cache::shouldReceive('lock')->andReturn($lock);
        $this->authenticator->shouldNotReceive('refreshAccessToken');
        Http::fake();

        $client = new EFacturaClient('12345678', 'expired', 'refresh', Carbon::now()->subMinute());

        expect(fn () => $client->uploadDocument('<Invoice/>'))
            ->toThrow(fn (AuthenticationException $e) => expect($e->getCode())->toBe(0)
                ->and($e->getPrevious())->toBe($failure)
                ->and(outcomeParts(UploadOutcome::fromThrowable($e)))->toBe([UploadOutcomeKind::NotSent, UploadOutcomeReason::Transient, null, null, null]));
        Http::assertNothingSent();
    })->with(['the lock wait' => false, 'creating the lock' => true]);

    it('lets go of a lock its store took before failing', function () {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new RuntimeException('reply lost after SET NX'));
        $lock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->andReturn($lock);
        Http::fake();

        expect(fn () => (new EFacturaClient('12345678', 'expired', 'refresh', Carbon::now()->subMinute()))->uploadDocument('<Invoice/>'))
            ->toThrow(AuthenticationException::class);
        Http::assertNothingSent();
    });

    it('sends the upload after a refresh even when releasing the lock fails', function () {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andReturn(true);
        $lock->shouldReceive('release')->andThrow(new RuntimeException('Connection refused'));
        Cache::shouldReceive('lock')->andReturn($lock);
        $this->authenticator->shouldReceive('refreshAccessToken')->once()->andReturn(new OAuthTokensData(
            accessToken: 'new-access',
            refreshToken: 'new-refresh',
            expiresAt: Carbon::now()->addHour(),
        ));
        Http::fake(['*' => Http::response('<?xml version="1.0"?><header ExecutionStatus="0" index_incarcare="5001234567"/>', 200)]);

        $response = (new EFacturaClient('12345678', 'expired', 'refresh', Carbon::now()->subMinute()))->uploadDocument('<Invoice/>');

        expect($response->indexIncarcare)->toBe('5001234567');
        Http::assertSentCount(1);
    });

    it('reads a lock wait that runs out as not sent too', function () {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        $lock->shouldReceive('release');
        Cache::shouldReceive('lock')->andReturn($lock);
        Http::fake();

        expect(outcomeParts(uploadOutcome(expiresAt: Carbon::now()->subMinute())))
            ->toBe([UploadOutcomeKind::NotSent, UploadOutcomeReason::Transient, null, null, null]);
        Http::assertNothingSent();
    });
});

describe('a token persister that throws during an upload', function () {
    it('reaches the caller as itself with nothing sent and the new tokens kept, read as uncertain', function () {
        $this->authenticator->shouldReceive('refreshAccessToken')->once()->andReturn(new OAuthTokensData(
            accessToken: 'new-access',
            refreshToken: 'new-refresh',
            expiresAt: Carbon::now()->addHour(),
        ));
        Http::fake();
        $failure = new RuntimeException('store down');
        $client = new EFacturaClient('12345678', 'expired', 'refresh', Carbon::now()->subMinute(), tokenPersister: fn () => throw $failure);

        // fromThrowable() cannot tell the host's own failure from the upload's: the caller classifies it.
        expect(fn () => $client->uploadDocument('<Invoice/>'))
            ->toThrow(fn (RuntimeException $e) => expect($e)->toBe($failure)
                ->and(UploadOutcome::fromThrowable($e)->kind)->toBe(UploadOutcomeKind::Uncertain))
            ->and($client->wasTokenRefreshed())->toBeTrue()
            ->and($client->getTokens()->refreshToken)->toBe('new-refresh');
        Http::assertNothingSent();
    });
});
