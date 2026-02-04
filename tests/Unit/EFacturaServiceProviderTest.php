<?php

declare(strict_types=1);

use Beecoded\EFactura\Contracts\EFacturaClientInterface;
use Beecoded\EFactura\Services\EFacturaClient;

it('registers the efactura singleton', function () {
    $client = app(EFacturaClientInterface::class);

    expect($client)->toBeInstanceOf(EFacturaClient::class);
});

it('provides the efactura alias', function () {
    $client = app('efactura');

    expect($client)->toBeInstanceOf(EFacturaClient::class);
});

it('merges package config', function () {
    expect(config('efactura.sandbox'))->toBeTrue();
    expect(config('efactura.oauth'))->toBeArray();
    expect(config('efactura.http.timeout'))->toBe(30);
});
