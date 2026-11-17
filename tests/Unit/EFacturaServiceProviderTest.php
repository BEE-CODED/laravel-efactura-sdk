<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Contracts\EFacturaClientInterface;
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
    expect(app()->bound(EFacturaClient::class))->toBeFalse()
        ->and(app()->bound(EFacturaClientInterface::class))->toBeFalse();
});

describe('the env normaliser', function () {
    // Testbench registers the provider before any env setup, so each test sets the raw value and registers again.
    $register = fn () => (new EFacturaServiceProvider(app()))->register();

    it('keeps the sandbox unless the value is false or 0', function (mixed $raw, bool $sandbox) use ($register) {
        config()->set('efactura-sdk.sandbox', $raw);

        $register();

        expect(EFacturaClient::getBaseUrl())->toBe($sandbox ? 'https://api.anaf.ro/test/FCTEL/rest' : 'https://api.anaf.ro/prod/FCTEL/rest')
            ->and(config('efactura-sdk.sandbox'))->toBe($sandbox);
    })->with([
        'blank' => ['', true],
        'null' => [null, true],
        'int 0' => [0, false],
        "'0'" => ['0', false],
        'false' => [false, false],
    ]);

    it('keeps rate limiting on unless the value is false or 0', function (mixed $raw, bool $enabled) use ($register) {
        config()->set('efactura-sdk.rate_limits.enabled', $raw);

        $register();

        expect(app(RateLimiter::class)->isEnabled())->toBe($enabled);
    })->with([
        'blank' => ['', true],
        "'0'" => ['0', false],
    ]);

    it('gives a blank or non-integer rate limit its default', function (mixed $raw) use ($register) {
        config()->set('efactura-sdk.rate_limits.global_per_minute', $raw);

        $register();

        expect(app(RateLimiter::class)->getRemainingQuota('global')['limit'])->toBe(500);
    })->with([
        'blank' => [''],
        'a letter O for a zero' => ['5O0'],
    ]);

    it('still refuses a zero rate limit when the limiter is built', function () use ($register) {
        config()->set('efactura-sdk.rate_limits.global_per_minute', 0);

        $register();

        expect(fn () => app(RateLimiter::class))
            ->toThrow(InvalidArgumentException::class, "Rate limit 'global_per_minute' must be a positive integer, got 0");
    });
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

    it('logs a blank channel name to the host default', function () use ($boot) {
        config()->set('efactura-sdk.logging.channel', '');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->level.': '.$event->message;
        });

        (new EFacturaServiceProvider(app()))->register();
        $boot();
        EFacturaClient::getLogger()->info('probe');

        expect($logged)->toBe(['info: probe'])
            ->and(config('efactura-sdk.logging.channel'))->toBeNull();
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
