<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Enums\StatusVerdict;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app()->instance(AnafAuthenticatorInterface::class, Mockery::mock(AnafAuthenticatorInterface::class));
    $rateLimiter = Mockery::mock(RateLimiter::class);
    $rateLimiter->shouldIgnoreMissing();
    app()->instance(RateLimiter::class, $rateLimiter);
});

it('reads one verdict from each status answer', function (array|string $body, StatusVerdict $verdict) {
    Http::fake(['*' => Http::response($body)]);

    $client = new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour());

    expect($client->getStatusMessage('5001234567')->verdict())->toBe($verdict);
})->with([
    'in prelucrare' => [['stare' => 'in prelucrare'], StatusVerdict::InProgress],
    'ok with a download id' => [['stare' => 'ok', 'id_descarcare' => '3001234567'], StatusVerdict::Ready],
    'ok with an int download id' => [['stare' => 'ok', 'id_descarcare' => 3001234567], StatusVerdict::Ready],
    'nok with a download id' => [['stare' => 'nok', 'id_descarcare' => '3001234567'], StatusVerdict::Rejected],
    'ok without a download id' => [['stare' => 'ok'], StatusVerdict::ReadyWithoutDownloadId],
    'ok with a non-numeric download id' => [['stare' => 'ok', 'id_descarcare' => '30012x'], StatusVerdict::ReadyWithoutDownloadId],
    'ok with a 65-digit download id' => [['stare' => 'ok', 'id_descarcare' => str_repeat('9', 65)], StatusVerdict::ReadyWithoutDownloadId],
    'nok without a download id' => [['stare' => 'nok', 'Errors' => 'Nu aveti dreptul'], StatusVerdict::RejectedWithoutDownloadId],
    'not taken in' => [['stare' => 'XML cu erori nepreluat de sistem'], StatusVerdict::NotProcessed],
    'JSON eroare' => [['eroare' => 'Id incarcare inexistent'], StatusVerdict::AnafError],
    'XML header with only Errors' => ['<?xml version="1.0"?><header><Errors errorMessage="Nu exista factura"/></header>', StatusVerdict::AnafError],
    'an unknown stare' => [['stare' => 'altceva', 'id_descarcare' => '3001234567'], StatusVerdict::Unknown],
    'an empty answer' => [['foo' => 'bar'], StatusVerdict::Unknown],
]);

it('reads the same verdicts from the XML header ANAF answers with', function (string $attributes, string $children, StatusVerdict $verdict) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1" '.$attributes.'>'.$children.'</header>';
    Http::fake(['*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);

    $client = new EFacturaClient('12345678', 'token', 'refresh', Carbon::now()->addHour());

    expect($client->getStatusMessage('5001234567')->verdict())->toBe($verdict);
})->with([
    'in prelucrare' => ['stare="in prelucrare"', '', StatusVerdict::InProgress],
    'ok with a download id' => ['stare="ok" id_descarcare="3001234567"', '', StatusVerdict::Ready],
    'nok with a download id' => ['stare="nok" id_descarcare="3001234567"', '', StatusVerdict::Rejected],
    'ok without a download id' => ['stare="ok"', '', StatusVerdict::ReadyWithoutDownloadId],
    'ok with a non-numeric download id' => ['stare="ok" id_descarcare="30012x"', '', StatusVerdict::ReadyWithoutDownloadId],
    'nok without a download id' => ['stare="nok"', '<Errors errorMessage="Nu aveti dreptul"/>', StatusVerdict::RejectedWithoutDownloadId],
    'not taken in' => ['stare="XML cu erori nepreluat de sistem"', '', StatusVerdict::NotProcessed],
    'only Errors' => ['', '<Errors errorMessage="Nu exista factura cu id_incarcare= 5001234567"/>', StatusVerdict::AnafError],
    'an unknown stare' => ['stare="altceva" id_descarcare="3001234567"', '', StatusVerdict::Unknown],
]);
