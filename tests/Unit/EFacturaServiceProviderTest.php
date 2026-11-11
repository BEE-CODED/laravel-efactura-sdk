<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Contracts\UblBuilderInterface;
use BeeCoded\EFacturaSdk\EFacturaServiceProvider;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Services\UblBuilder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

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

describe('the SDK log channel', function () {
    beforeEach(function () {
        // Keep the emergency logger (the symptom) out of the shared Testbench log.
        config()->set('logging.channels.emergency.path', sys_get_temp_dir().'/efactura-sdk-emergency-test.log');
        config()->set('logging.default', 'null');
        config()->set('logging.channels.efactura-sdk', null);
        config()->set('efactura-sdk.logging.channel', 'efactura-sdk');
    });

    $boot = fn () => (new EFacturaServiceProvider(app()))->boot();

    it('is defined over the host default when the host did not define it', function () use ($boot) {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->level.': '.$event->message;
        });

        $boot();
        EFacturaClient::getLogger()->info('probe');

        expect($logged)->toBe(['info: probe'])
            ->and(config('logging.channels.efactura-sdk'))->toBe(['driver' => 'stack', 'channels' => ['null']]);
    });

    it('leaves a channel the host defined alone', function () use ($boot) {
        config()->set('logging.channels.efactura-sdk', ['driver' => 'single', 'path' => '/dev/null']);

        $boot();

        expect(config('logging.channels.efactura-sdk'))->toBe(['driver' => 'single', 'path' => '/dev/null']);
    });

    it('is not defined as a stack over itself', function () use ($boot) {
        config()->set('logging.default', 'efactura-sdk');

        $boot();

        expect(config('logging.channels.efactura-sdk'))->toBeNull();
    });
});
