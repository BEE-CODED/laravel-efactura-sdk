<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\AnafAuthenticator;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;

/**
 * ANAF does not keep its scalar types stable. Every shape below reached a typed
 * DTO verbatim before and was a TypeError (index lost, sync crashed) or a wrong
 * value. Each goes through the real client and parser with only HTTP faked.
 */
beforeEach(function () {
    app()->instance(AnafAuthenticatorInterface::class, Mockery::mock(AnafAuthenticatorInterface::class));

    $rateLimiter = Mockery::mock(RateLimiter::class);
    $rateLimiter->shouldIgnoreMissing();
    app()->instance(RateLimiter::class, $rateLimiter);

    config()->set('efactura-sdk.http.retry_times', 1);

    $this->client = fn (): EFacturaClient => new EFacturaClient(
        vatNumber: '12345678',
        accessToken: 'token',
        refreshToken: 'refresh',
        expiresAt: Carbon::now()->addHour(),
    );
    $this->json = fn (string $body, int $status = 200) => Http::response($body, $status, ['Content-Type' => 'application/json']);
});

describe('upload', function () {
    it('keeps an int index and dateResponse as strings', function () {
        Http::fake(['*' => Http::response(['ExecutionStatus' => 0, 'index_incarcare' => 5001234567, 'dateResponse' => 202311091200])]);

        $response = ($this->client)()->uploadDocument('<Invoice/>');

        expect($response->indexIncarcare)->toBe('5001234567')
            ->and($response->dateResponse)->toBe('202311091200')
            ->and($response->isSuccessful())->toBeTrue();
    });

    it('keeps a big integer index exactly', function () {
        Http::fake(['*' => ($this->json)('{"ExecutionStatus":0,"index_incarcare":123456789012345678901234}')]);

        expect(($this->client)()->uploadDocument('<Invoice/>')->indexIncarcare)->toBe('123456789012345678901234');
    });

    it('accepts Errors as a string', function () {
        Http::fake(['*' => Http::response(['ExecutionStatus' => 1, 'Errors' => 'Fisier invalid'])]);

        $response = ($this->client)()->uploadDocument('<Invoice/>');

        expect($response->errors)->toBe(['Fisier invalid'])->and($response->isFailed())->toBeTrue();
    });

    it('accepts Errors as a single errorMessage object', function () {
        Http::fake(['*' => Http::response(['ExecutionStatus' => 1, 'Errors' => ['errorMessage' => 'CIF invalid']])]);

        expect(($this->client)()->uploadDocument('<Invoice/>')->errors)->toBe(['CIF invalid']);
    });

    it('rejects a bare-number JSON body as unparseable', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->client)()->uploadDocument('<Invoice/>');
    })->throws(ApiException::class, 'Unable to parse upload response');
});

describe('status', function () {
    it('keeps an int id_descarcare as a string', function () {
        Http::fake(['*' => Http::response(['stare' => 'ok', 'id_descarcare' => 3001234567])]);

        $response = ($this->client)()->getStatusMessage('5001234567');

        expect($response->idDescarcare)->toBe('3001234567')->and($response->isReady())->toBeTrue();
    });

    it('keeps a big integer id_descarcare exactly', function () {
        Http::fake(['*' => ($this->json)('{"stare":"ok","id_descarcare":123456789012345678901234}')]);

        expect(($this->client)()->getStatusMessage('5001234567')->idDescarcare)->toBe('123456789012345678901234');
    });

    it('treats an int stare as unknown instead of crashing', function () {
        Http::fake(['*' => Http::response(['stare' => 1, 'id_descarcare' => 42])]);

        $response = ($this->client)()->getStatusMessage('5001234567');

        expect($response->stare)->toBeNull()
            ->and($response->isFailed())->toBeFalse()
            ->and($response->isReady())->toBeFalse()
            ->and($response->idDescarcare)->toBe('42');
    });

    it('accepts Errors as a single errorMessage object', function () {
        Http::fake(['*' => Http::response(['stare' => 'nok', 'Errors' => ['errorMessage' => 'Nu aveti dreptul']])]);

        $response = ($this->client)()->getStatusMessage('5001234567');

        expect($response->errors)->toBe(['Nu aveti dreptul'])->and($response->idDescarcare)->toBeNull();
    });

    it('lifts eroare when Errors carries nothing usable', function () {
        Http::fake(['*' => Http::response(['Errors' => [], 'eroare' => 'Id incarcare inexistent'])]);

        $response = ($this->client)()->getStatusMessage('5001234567');

        expect($response->errors)->toBe(['Id incarcare inexistent'])->and($response->hasAnafError())->toBeTrue();
    });

    it('reports an XML header carrying only Errors as an ANAF error, not a rejection', function () {
        // Same answer as JSON {"eroare": ...}: ANAF said nothing about the document.
        Http::fake(['*' => Http::response('<?xml version="1.0"?><header><Errors errorMessage="Nu exista factura cu id_incarcare= 5001234567"/></header>')]);

        $response = ($this->client)()->getStatusMessage('5001234567');

        expect($response->hasAnafError())->toBeTrue()
            ->and($response->isFailed())->toBeFalse()
            ->and($response->stare)->toBeNull()
            ->and($response->errors)->toBe(['Nu exista factura cu id_incarcare= 5001234567']);
    });

    it('reports an XML Errors element with an empty message as an ANAF error', function () {
        Http::fake(['*' => Http::response('<?xml version="1.0"?><header><Errors errorMessage=""/></header>')]);

        $response = ($this->client)()->getStatusMessage('5001234567');

        expect($response->hasAnafError())->toBeTrue()
            ->and($response->errors)->toBe(['Operation failed']);
    });

    it('rejects a bare-number JSON body as unparseable', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->client)()->getStatusMessage('5001234567');
    })->throws(ApiException::class, 'Unable to parse status response');

    it('reports the errorMessage of an XML error body', function () {
        Http::fake(['*' => Http::response('<?xml version="1.0"?><header><Errors errorMessage="Id incarcare invalid"/></header>', 400, ['Content-Type' => 'application/xml'])]);

        ($this->client)()->getStatusMessage('5001234567');
    })->throws(ApiException::class, 'Id incarcare invalid');

    it('reports a scalar eroare when the error body message is not a string', function () {
        Http::fake(['*' => Http::response(['message' => ['nested'], 'eroare' => 'Cerere invalida'], 400)]);

        ($this->client)()->getStatusMessage('5001234567');
    })->throws(ApiException::class, 'Cerere invalida');
});

describe('message lists', function () {
    it('normalises the simple list and keeps a message without an id apart', function () {
        Http::fake(['*' => Http::response([
            'mesaje' => [
                ['id' => 3001234567, 'cif' => 12345678, 'data_creare' => 202311091200, 'tip' => 'FACTURA PRIMITA', 'detalii' => ['x'], 'id_solicitare' => 5001234567],
                ['cif' => '12345678', 'tip' => 'FACTURA PRIMITA'],
                ['id' => '', 'tip' => 'FACTURA PRIMITA'],
            ],
            'serial' => 1234,
            'cui' => 12345678,
            'titlu' => ['Lista'],
            'info' => 5,
            'eroare_descarcare' => ['x'],
        ])]);

        $response = ($this->client)()->getMessages(new ListMessagesParamsData(cif: '12345678', days: 5));

        expect($response->serial)->toBe('1234')
            ->and($response->cui)->toBe('12345678')
            ->and($response->titlu)->toBeNull()
            ->and($response->info)->toBe('5')
            ->and($response->downloadError)->toBeNull()
            ->and($response->getMessageCount())->toBe(3);

        [$first, $noId, $emptyId] = array_values($response->mesaje);

        expect($first->id)->toBe('3001234567')
            ->and($first->cif)->toBe('12345678')
            ->and($first->dataCreare)->toBe('202311091200')
            ->and($first->detalii)->toBe('')
            ->and($first->idSolicitare)->toBe('5001234567')
            ->and($noId->id)->toBeNull()
            ->and($emptyId->id)->toBeNull();
    });

    it('rejects a bare-number simple list body as unparseable', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->client)()->getMessages(new ListMessagesParamsData(cif: '12345678', days: 5));
    })->throws(ApiException::class, 'Unable to parse message list response');

    it('normalises the paginated list counts and ids', function () {
        Http::fake(['*' => Http::response([
            'mesaje' => [['id' => 3001234567, 'tip' => 'FACTURA TRIMISA']],
            'numar_inregistrari_in_pagina' => '1',
            'numar_total_inregistrari_per_pagina' => 500,
            'numar_total_inregistrari' => '1',
            'numar_total_pagini' => 1,
            'index_pagina_curenta' => 'abc',
            'serial' => 99,
            'cui' => 12345678,
            'titlu' => ['x'],
            'eroare' => 7,
        ])]);

        $response = ($this->client)()->getMessagesPaginated(new PaginatedMessagesParamsData(
            cif: '12345678',
            startTime: 1_700_000_000_000,
            endTime: 1_700_000_100_000,
        ));

        expect($response->recordsInPage)->toBe(1)
            ->and($response->recordsPerPage)->toBe(500)
            ->and($response->totalRecords)->toBe(1)
            ->and($response->totalPages)->toBe(1)
            ->and($response->currentPage)->toBeNull()
            ->and($response->serial)->toBe('99')
            ->and($response->cui)->toBe('12345678')
            ->and($response->titlu)->toBeNull()
            ->and($response->error)->toBe('7')
            ->and(array_values($response->mesaje)[0]->id)->toBe('3001234567');
    });

    it('rejects a bare-number paginated list body as unparseable', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->client)()->getMessagesPaginated(new PaginatedMessagesParamsData(
            cif: '12345678',
            startTime: 1_700_000_000_000,
            endTime: 1_700_000_100_000,
        ));
    })->throws(ApiException::class, 'Unable to parse paginated message list response');
});

describe('validation and PDF services', function () {
    beforeEach(function () {
        config()->set('efactura-sdk.endpoints.services.validate', 'https://api.example.com/validare');
        config()->set('efactura-sdk.endpoints.services.transform', 'https://api.example.com/transformare');
    });

    it('reads a validation answer of valid "false" as invalid', function (mixed $valid, bool $expected) {
        Http::fake(['*' => Http::response(['valid' => $valid])]);

        expect(($this->client)()->validateXml('<Invoice/>', DocumentStandardType::FACT1)->valid)->toBe($expected);
    })->with([
        'string false' => ['false', false],
        'string 0' => ['0', false],
        'bool true' => [true, true],
        'string true' => ['true', true],
    ]);

    it('drops non-scalar details and info from a validation answer', function () {
        Http::fake(['*' => Http::response(['stare' => 'nok', 'mesaj' => ['x'], 'info' => ['y'], 'trace_id' => 77, 'Messages' => [['message' => 'E: bad']]])]);

        $result = ($this->client)()->validateXml('<Invoice/>', DocumentStandardType::FACT1);

        expect($result->valid)->toBeFalse()
            ->and($result->details)->toBeNull()
            ->and($result->info)->toBe('77')
            ->and($result->errors)->toBe(['E: bad']);
    });

    it('rejects a bare-number validation body as unparseable', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->client)()->validateXml('<Invoice/>', DocumentStandardType::FACT1);
    })->throws(ApiException::class, 'Unable to parse validation response');

    it('rejects a bare-number PDF error body as invalid JSON', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->client)()->convertXmlToPdf('<Invoice/>', DocumentStandardType::FACT1);
    })->throws(ApiException::class, 'PDF conversion failed with invalid JSON response');

    it('reports eroare when a PDF error message is not a string', function () {
        Http::fake(['*' => Http::response(['message' => ['x'], 'eroare' => 'XML invalid'], 200, ['Content-Type' => 'application/json'])]);

        ($this->client)()->convertXmlToPdf('<Invoice/>', DocumentStandardType::FACT1);
    })->throws(ApiException::class, 'XML invalid');
});

describe('company lookup', function () {
    it('normalises company fields and skips entries that are not objects', function () {
        Http::fake(['*' => Http::response([
            'found' => [
                'junk',
                [
                    'date_generale' => [
                        'cui' => 18547290,
                        'denumire' => ['x'],
                        'telefon' => 212345678,
                        'codPostal' => 12345,
                        'data_inregistrare' => ['x'],
                    ],
                    'inregistrare_scop_Tva' => 'not-an-object',
                    'inregistrare_RTVAI' => ['statusTvaIncasare' => true, 'tipActTvaInc' => 5, 'dataInceputTvaInc' => ['x']],
                    'adresa_sediu_social' => ['sdenumire_Strada' => 'Str. Exemplu', 'scod_Localitate' => 179, 'snumar_Strada' => ['x']],
                ],
            ],
            'notFound' => [['cui' => ['x']], '123', ['cui' => 456]],
        ])]);

        $result = (new AnafDetailsClient)->getCompanyData('RO18547290');
        $company = $result->first();

        expect($result->companies)->toHaveCount(1)
            ->and($company->cui)->toBe('18547290')
            ->and($company->name)->toBe('')
            ->and($company->phone)->toBe('212345678')
            ->and($company->postalCode)->toBe('12345')
            ->and($company->registrationDate)->toBeNull()
            ->and($company->isVatPayer)->toBeFalse()
            ->and($company->rtvaiDetails->actType)->toBe('5')
            ->and($company->rtvaiDetails->startDate)->toBeNull()
            ->and($company->headquartersAddress->cityCode)->toBe('179')
            ->and($company->headquartersAddress->streetNumber)->toBeNull()
            ->and($result->notFound)->toBe([123, 456]);
    });

    it('fails a bare-number lookup body instead of crashing', function () {
        Http::fake(['*' => ($this->json)('5')]);

        $result = (new AnafDetailsClient)->getCompanyData('RO18547290');

        expect($result->success)->toBeFalse()->and($result->error)->toContain('Invalid or malformed JSON');
    });
});

describe('OAuth tokens', function () {
    beforeEach(function () {
        $this->authenticator = fn (): AnafAuthenticator => new AnafAuthenticator(
            http: Http::getFacadeRoot(),
            config: [
                'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.com/callback'],
                'endpoints' => ['oauth' => ['authorize' => 'https://example.com/authorize', 'token' => 'https://example.com/token']],
                'http' => ['timeout' => 30],
            ],
        );
    });

    it('normalises expires_in and token_type', function () {
        Http::fake(['*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => '3600', 'token_type' => ['x']])]);

        $tokens = ($this->authenticator)()->exchangeCodeForToken('code');

        expect($tokens->expiresIn)->toBe(3600)
            ->and($tokens->tokenType)->toBe('Bearer')
            ->and($tokens->expiresAt)->not->toBeNull();
    });

    it('rejects a non-string access token', function () {
        Http::fake(['*' => Http::response(['access_token' => ['a'], 'refresh_token' => 'r'])]);

        ($this->authenticator)()->exchangeCodeForToken('code');
    })->throws(AuthenticationException::class, 'did not contain an access token');

    it('rejects a bare-number token body as non-JSON', function () {
        Http::fake(['*' => ($this->json)('5')]);

        ($this->authenticator)()->exchangeCodeForToken('code');
    })->throws(AuthenticationException::class, 'non-JSON response');

    it('reports eroare when the OAuth error is not a string', function () {
        Http::fake(['*' => Http::response(['error' => ['x'], 'eroare' => 'Cod expirat'], 400)]);

        ($this->authenticator)()->exchangeCodeForToken('code');
    })->throws(AuthenticationException::class, 'Cod expirat');
});

describe('rate limit scope through the real client', function () {
    beforeEach(function () {
        app()->instance(RateLimiter::class, new RateLimiter);
        LaravelRateLimiter::clear('efactura-sdk:minute:global');
        LaravelRateLimiter::clear('efactura-sdk:daily:status:5001234567');
        Http::fake(['*' => Http::response(['stare' => 'in prelucrare'])]);
    });

    afterEach(function () {
        LaravelRateLimiter::clear('efactura-sdk:minute:global');
        LaravelRateLimiter::clear('efactura-sdk:daily:status:5001234567');
    });

    it('names the per-message status limit', function () {
        config()->set('efactura-sdk.rate_limits.status_per_day_message', 1);
        app()->instance(RateLimiter::class, new RateLimiter);
        $client = ($this->client)();
        $client->getStatusMessage('5001234567');

        try {
            $client->getStatusMessage('5001234567');
            $this->fail('Expected the status limit to trip.');
        } catch (RateLimitExceededException $e) {
            expect($e->scope)->toBe(RateLimitScope::Status);
        }
    });

    it('spends no global unit on a call its per-message limit refuses', function () {
        config()->set('efactura-sdk.rate_limits.status_per_day_message', 1);
        app()->instance(RateLimiter::class, $limiter = new RateLimiter);
        $client = ($this->client)();
        $client->getStatusMessage('5001234567');
        $globalBefore = $limiter->getRemainingQuota('global')['remaining'];

        expect(fn () => $client->getStatusMessage('5001234567'))->toThrow(RateLimitExceededException::class)
            ->and($limiter->getRemainingQuota('global')['remaining'])->toBe($globalBefore);
    });

    it('spends no per-message unit on a call the global limit refuses', function () {
        config()->set('efactura-sdk.rate_limits.global_per_minute', 1);
        app()->instance(RateLimiter::class, $limiter = new RateLimiter);
        $client = ($this->client)();
        $client->uploadDocument('<Invoice/>');

        try {
            $client->getStatusMessage('5001234567');
            $this->fail('Expected the global limit to trip.');
        } catch (RateLimitExceededException $e) {
            expect($e->scope)->toBe(RateLimitScope::Global)
                ->and($limiter->getRemainingQuota('status', '5001234567')['remaining'])->toBe(50);
        }
    });

    it('counts one unit of both limits for an allowed call, at either status limit', function () {
        config()->set('efactura-sdk.rate_limits.status_per_day_message', 1);
        config()->set('efactura-sdk.rate_limits.status_per_day_message_provider', 2);
        app()->instance(RateLimiter::class, $limiter = new RateLimiter);
        $client = ($this->client)();
        $globalBefore = $limiter->getRemainingQuota('global')['remaining'];

        $client->getStatusMessage('5001234567');

        expect($limiter->getRemainingQuota('global')['remaining'])->toBe($globalBefore - 1)
            ->and($limiter->getRemainingQuota('status', '5001234567')['remaining'])->toBe(0);

        // The default limit is spent; the provider limit still has room on the same counter.
        expect(fn () => $client->getStatusMessage('5001234567'))->toThrow(RateLimitExceededException::class);
        $client->getStatusMessage('5001234567', useProviderLimit: true);

        expect($limiter->getRemainingQuota('global')['remaining'])->toBe($globalBefore - 2)
            ->and($limiter->getRemainingQuota('status', '5001234567', true)['remaining'])->toBe(0);
    });

    it('names the global limit', function () {
        config()->set('efactura-sdk.rate_limits.global_per_minute', 1);
        app()->instance(RateLimiter::class, new RateLimiter);
        $client = ($this->client)();
        $client->getStatusMessage('5001234567');

        try {
            $client->uploadDocument('<Invoice/>');
            $this->fail('Expected the global limit to trip.');
        } catch (RateLimitExceededException $e) {
            expect($e->scope)->toBe(RateLimitScope::Global);
        }
    });
});
