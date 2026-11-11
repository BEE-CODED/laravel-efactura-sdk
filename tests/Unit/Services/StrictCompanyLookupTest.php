<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Enums\CompanyLookupFailureKind;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\DateHelper;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $rateLimiter = Mockery::mock(RateLimiter::class);
    $rateLimiter->shouldReceive('checkCompanyLookup')->andReturnNull()->byDefault();
    app()->instance(RateLimiter::class, $rateLimiter);

    config()->set('efactura-sdk.http.retry_delay', 0);
    config()->set('efactura-sdk.http.retry_times', 3);
});

/**
 * One found entry as ANAF v9 answers it.
 *
 * @param  array<string, mixed>  $general
 * @param  array<string, mixed>  $inactive
 * @return array<string, mixed>
 */
function foundCompany(int $cui, array $general = [], array $inactive = []): array
{
    return [
        'date_generale' => $general + [
            'cui' => $cui,
            'data' => DateHelper::getCurrentDateForAnaf(),
            'denumire' => "Company {$cui} SRL",
            'adresa' => 'Str. Test 1',
            'nrRegCom' => 'J40/1/2020',
            'stare_inregistrare' => 'INREGISTRAT din data 01.01.2020',
        ],
        'inregistrare_scop_Tva' => ['scpTVA' => true],
        'stare_inactiv' => $inactive + [
            'dataInactivare' => '',
            'dataReactivare' => '',
            'dataPublicare' => '',
            'dataRadiere' => '',
            'statusInactivi' => false,
        ],
    ];
}

function strictClient(): AnafDetailsClient
{
    return new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 5);
}

describe('a provider rate limit', function () {
    it('is a RateLimitExceededException from the provider, not a lookup failure', function () {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        expect(fn () => (new AnafDetailsClient)->getCompanyData('RO18547290'))->toThrow(
            fn (RateLimitExceededException $e) => expect($e->scope)->toBe(RateLimitScope::CompanyLookup)
                ->and($e->fromProvider)->toBeTrue()
                ->and($e->retryAfterSeconds)->toBe(2)
        );
        Http::assertSentCount(1);
    });
});

describe('strict evidence', function () {
    it('confirms every requested company exactly once', function () {
        Http::fake(['*' => Http::response(['found' => [foundCompany(18547290)], 'notFound' => [14399840]], 200)]);

        $result = strictClient()->batchGetCompanyData(['RO18547290', '14399840', 'ro18547290']);

        expect($result->success)->toBeTrue()
            ->and($result->first()?->cui)->toBe('18547290')
            ->and($result->notFound)->toBe([14399840])
            ->and($result->failureKind)->toBeNull();
        // The RO prefix is normalised, so a CUI is asked for once.
        Http::assertSent(fn ($request) => array_column(json_decode($request->body(), true), 'cui') === [18547290, 14399840]);
    });

    it('accepts a 404 that reports every requested CUI as not found', function () {
        Http::fake(['*' => Http::response(['found' => [], 'notFound' => [99999999]], 404)]);

        $result = strictClient()->getCompanyData('99999999');

        expect($result->success)->toBeTrue()
            ->and($result->notFound)->toBe([99999999]);
    });

    it('refuses an answer that does not confirm the request', function (array $body, int $status = 200) {
        Http::fake(['*' => Http::response($body, $status)]);

        $result = strictClient()->batchGetCompanyData(['RO18547290', '14399840']);

        expect($result->success)->toBeFalse()
            ->and($result->failureKind)->toBe(CompanyLookupFailureKind::InvalidResponse)
            ->and($result->companies)->toBe([]);
        Http::assertSentCount(1);
    })->with([
        'a requested CUI missing' => [['found' => [foundCompany(18547290)], 'notFound' => []]],
        'a CUI answered twice' => [['found' => [foundCompany(18547290)], 'notFound' => [14399840, 18547290]]],
        'a CUI nobody asked for' => [['found' => [foundCompany(18547290), foundCompany(11111111)], 'notFound' => [14399840]]],
        'a CUI as text' => [['found' => [foundCompany(18547290)], 'notFound' => ['14399840']]],
        'a found CUI as text' => [['found' => [foundCompany(18547290, ['cui' => '18547290'])], 'notFound' => [14399840]]],
        'the legacy lowercase notfound' => [['found' => [foundCompany(18547290)], 'notfound' => [14399840]]],
        'both not-found keys' => [['found' => [foundCompany(18547290)], 'notFound' => [14399840], 'notfound' => []]],
        'found as an object' => [['found' => ['a' => foundCompany(18547290)], 'notFound' => [14399840]]],
        'notFound missing' => [['found' => [foundCompany(18547290), foundCompany(14399840)]]],
        'another date' => [['found' => [foundCompany(18547290, ['data' => '2020-01-01'])], 'notFound' => [14399840]]],
        'a blank name' => [['found' => [foundCompany(18547290, ['denumire' => '  '])], 'notFound' => [14399840]]],
        'an unknown registration state' => [['found' => [foundCompany(18547290, ['stare_inregistrare' => 'SUSPENDAT'])], 'notFound' => [14399840]]],
        'an impossible registration date' => [['found' => [foundCompany(18547290, ['stare_inregistrare' => 'INREGISTRAT din data 31.02.2020'])], 'notFound' => [14399840]]],
        'statusInactivi as text' => [['found' => [foundCompany(18547290, [], ['statusInactivi' => 'false'])], 'notFound' => [14399840]]],
        'no dataRadiere' => [['found' => [array_merge(foundCompany(18547290), ['stare_inactiv' => ['statusInactivi' => false]])], 'notFound' => [14399840]]],
        'an invalid date' => [['found' => [foundCompany(18547290, [], ['dataInactivare' => '2020-13-01'])], 'notFound' => [14399840]]],
        'no stare_inactiv' => [['found' => [array_diff_key(foundCompany(18547290), ['stare_inactiv' => true])], 'notFound' => [14399840]]],
        'a company on a 404' => [['found' => [foundCompany(18547290)], 'notFound' => [14399840]], 404],
    ]);

    it('keeps a permissive client permissive', function () {
        Http::fake(['*' => Http::response(['found' => [foundCompany(18547290, ['stare_inregistrare' => 'SUSPENDAT'])], 'notfound' => [['cui' => 14399840]]], 200)]);

        $result = (new AnafDetailsClient)->batchGetCompanyData(['RO18547290', '14399840']);

        expect($result->success)->toBeTrue()
            ->and($result->notFound)->toBe([14399840]);
    });

    it('uses its own try count and timeout', function () {
        $timeouts = [];
        Http::fake(function ($request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response('Server Error', 500);
        });

        strictClient()->getCompanyData('RO18547290');

        expect($timeouts)->toBe([5]);
    });
});

describe('the failure kind', function () {
    it('names a transport failure', function (Closure $fake) {
        Http::fake(['*' => $fake]);

        $result = (new AnafDetailsClient)->getCompanyData('RO18547290');

        expect($result->success)->toBeFalse()
            ->and($result->failureKind)->toBe(CompanyLookupFailureKind::Transport);
    })->with([
        'an ANAF error' => [fn () => Http::response('Server Error', 500)],
        'a 404 without an ANAF body' => [fn () => Http::response('Not Found', 404)],
        'a failure before sending' => [fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 6: Could not resolve host: webservicesp.anaf.ro',
            new Request('POST', $request->url()),
            null,
            ['errno' => 6, 'error' => 'Could not resolve host'],
        ))],
    ]);

    it('names an exception the request raised as transport', function () {
        Http::fake(['*' => Http::response(['found' => [], 'notFound' => [18547290]], 200)]);
        $client = new class extends AnafDetailsClient
        {
            protected function request(string $route, array|string $data = [], string $method = 'get', array $headers = [], array|Closure $context = [], ?string $contentType = null): Response
            {
                throw new UnexpectedValueException('unreadable');
            }
        };

        expect($client->getCompanyData('RO18547290')->failureKind)->toBe(CompanyLookupFailureKind::Transport);
    });

    it('names an unreadable answer', function (string $body) {
        Http::fake(['*' => Http::response($body, 200)]);

        expect((new AnafDetailsClient)->getCompanyData('RO18547290')->failureKind)
            ->toBe(CompanyLookupFailureKind::InvalidResponse);
    })->with([
        'not JSON' => ['<html>maintenance</html>'],
        'a bare scalar' => ['42'],
        'an unexpected object' => ['{"message":"ok"}'],
    ]);

    it('names no kind for an input the SDK refused before sending', function (array $codes) {
        $result = (new AnafDetailsClient)->batchGetCompanyData($codes);

        expect($result->success)->toBeFalse()
            ->and($result->failureKind)->toBeNull();
        Http::assertNothingSent();
    })->with([
        'no codes' => [[]],
        'only invalid codes' => [['abc', 'RO']],
        'too many codes' => [array_map(fn (int $i) => (string) (10000000 + $i), range(1, 101))],
    ]);

    it('round-trips through the DTO', function () {
        $result = CompanyLookupResultData::failure('down', [], CompanyLookupFailureKind::Transport);

        expect(CompanyLookupResultData::from($result->toArray())->failureKind)->toBe(CompanyLookupFailureKind::Transport);
    });
});
