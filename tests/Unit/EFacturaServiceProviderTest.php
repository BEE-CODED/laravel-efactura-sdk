<?php

declare(strict_types=1);

use Beecoded\EFactura\Builders\InvoiceBuilder;
use Beecoded\EFactura\Contracts\AnafDetailsClientInterface;
use Beecoded\EFactura\Contracts\UblBuilderInterface;
use Beecoded\EFactura\Services\ApiClients\AnafDetailsClient;
use Beecoded\EFactura\Services\RateLimiter;
use Beecoded\EFactura\Services\UblBuilder;

it('registers the anaf details client singleton', function () {
    $client = app(AnafDetailsClientInterface::class);

    expect($client)->toBeInstanceOf(AnafDetailsClient::class);
});

it('provides the anaf-details alias', function () {
    $client = app('anaf-details');

    expect($client)->toBeInstanceOf(AnafDetailsClient::class);
});

it('registers the ubl builder singleton', function () {
    $builder = app(UblBuilderInterface::class);

    expect($builder)->toBeInstanceOf(UblBuilder::class);
});

it('provides the efactura.ubl alias', function () {
    $builder = app('efactura.ubl');

    expect($builder)->toBeInstanceOf(UblBuilder::class);
});

it('registers the invoice builder singleton', function () {
    $builder = app(InvoiceBuilder::class);

    expect($builder)->toBeInstanceOf(InvoiceBuilder::class);
});

it('registers the rate limiter singleton', function () {
    $limiter = app(RateLimiter::class);

    expect($limiter)->toBeInstanceOf(RateLimiter::class);
});

it('merges package config', function () {
    expect(config('efactura.sandbox'))->toBeTrue();
    expect(config('efactura.oauth'))->toBeArray();
    expect(config('efactura.http.timeout'))->toBe(30);
});

it('does not register efactura client as singleton (stateless design)', function () {
    // EFacturaClient is intentionally NOT registered as a singleton
    // because it requires tokens per instantiation.
    // Users should create instances directly with their tokens.
    expect(app()->bound('efactura'))->toBeFalse();
});
