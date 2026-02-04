<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Contracts\UblBuilderInterface;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Services\UblBuilder;

it('registers the anaf details client singleton', function () {
    $client = app(AnafDetailsClientInterface::class);

    expect($client)->toBeInstanceOf(AnafDetailsClient::class);
});

it('provides the efactura-sdk.anaf-details alias', function () {
    $client = app('efactura-sdk.anaf-details');

    expect($client)->toBeInstanceOf(AnafDetailsClient::class);
});

it('registers the ubl builder singleton', function () {
    $builder = app(UblBuilderInterface::class);

    expect($builder)->toBeInstanceOf(UblBuilder::class);
});

it('provides the efactura-sdk.ubl alias', function () {
    $builder = app('efactura-sdk.ubl');

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
    expect(config('efactura-sdk.sandbox'))->toBeTrue();
    expect(config('efactura-sdk.oauth'))->toBeArray();
    expect(config('efactura-sdk.http.timeout'))->toBe(30);
});

it('does not register efactura client as singleton (stateless design)', function () {
    // EFacturaClient is intentionally NOT registered as a singleton
    // because it requires tokens per instantiation.
    // Users should create instances directly with their tokens.
    expect(app()->bound('efactura-sdk'))->toBeFalse();
});
