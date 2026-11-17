<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk;

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Contracts\UblBuilderInterface;
use BeeCoded\EFacturaSdk\Services\AnafAuthenticator;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Services\UblBuilder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel e-Factura SDK Service Provider.
 *
 * This is a STATELESS SDK - no database storage is included.
 * OAuth tokens are managed by the consuming application.
 *
 * Registered Services:
 * - AnafAuthenticatorInterface: For OAuth flow (get auth URL, exchange code, refresh tokens)
 * - AnafDetailsClientInterface: For company lookup (no auth required)
 * - UblBuilderInterface: For generating UBL 2.1 XML invoices
 * - InvoiceBuilder: Low-level invoice XML builder
 *
 * NOTE: EFacturaClient is NOT registered as a singleton because it requires
 * tokens per instantiation. Create it directly:
 *
 * ```php
 * use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
 *
 * $client = new EFacturaClient(
 *     vatNumber: '12345678',
 *     accessToken: $tokens->accessToken,
 *     refreshToken: $tokens->refreshToken,
 *     expiresAt: $tokens->expiresAt,
 *     authenticator: app(AnafAuthenticatorInterface::class)
 * );
 * ```
 */
final class EFacturaServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/efactura-sdk.php',
            'efactura-sdk'
        );

        $this->normaliseConfig();

        // Authenticator for OAuth flow (stateless - returns tokens to caller)
        // Resolving it never throws: the OAuth credentials (client_id, client_secret,
        // redirect_uri) and endpoints are checked on first use, so it can be injected anywhere.
        $this->app->singleton(AnafAuthenticatorInterface::class, function ($app) {
            return new AnafAuthenticator(
                http: $app->make(HttpFactory::class),
                config: $app['config']['efactura-sdk']
            );
        });

        // Company lookup client (no auth required - public API)
        $this->app->singleton(AnafDetailsClientInterface::class, function () {
            return new AnafDetailsClient;
        });

        // Invoice XML builder
        $this->app->singleton(InvoiceBuilder::class, function () {
            return new InvoiceBuilder;
        });

        // UBL Builder wrapper
        $this->app->singleton(UblBuilderInterface::class, function ($app) {
            return new UblBuilder(
                invoiceBuilder: $app->make(InvoiceBuilder::class)
            );
        });

        // Rate limiter for API call throttling
        $this->app->singleton(RateLimiter::class, function () {
            return new RateLimiter;
        });

        // Aliases for convenience
        $this->app->alias(AnafAuthenticatorInterface::class, 'efactura-sdk.auth');
        $this->app->alias(AnafDetailsClientInterface::class, 'efactura-sdk.anaf-details');
        $this->app->alias(UblBuilderInterface::class, 'efactura-sdk.ubl');
    }

    /**
     * Turn the raw env() values into what the readers expect, once at register (also with a
     * cached config, which skips mergeConfigFrom). Idempotent. A missing key falls back to its
     * reader's own default, so a blank or malformed value is removed rather than defaulted here.
     * A config()->set() after register() is not normalised: only the retry floors and the limit checks guard it,
     * so set sandbox and rate_limits.enabled as real booleans (a blank then reads as false).
     */
    private function normaliseConfig(): void
    {
        $config = $this->app->make('config');
        $sdk = (array) $config->get('efactura-sdk', []);

        // A blank env line must not select production or turn limiting off: only false or 0 does.
        foreach (['sandbox', 'rate_limits.enabled'] as $key) {
            Arr::set($sdk, $key, ! in_array(Arr::get($sdk, $key), [false, 0, '0'], true));
        }

        // A blank retry_times used to mean one attempt; a negative retry_delay made sleep() throw.
        foreach (['http.retry_times' => 1, 'http.retry_delay' => 0] as $key => $min) {
            // filter_var reads an env true as 1.
            $raw = Arr::get($sdk, $key);
            $value = is_bool($raw) ? null : filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                Arr::forget($sdk, $key);
            } else {
                Arr::set($sdk, $key, max($min, $value));
            }
        }

        // Only a blank, boolean or non-integer limit is removed; 0 and negatives stay so the limiter refuses them.
        foreach ((array) Arr::get($sdk, 'rate_limits', []) as $name => $limit) {
            if ($name !== 'enabled' && (is_bool($limit) || filter_var($limit, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) === null)) {
                Arr::forget($sdk, "rate_limits.{$name}");
            }
        }

        // Log::channel() of a blank, whitespace or false channel builds the emergency logger on every call;
        // null is the host's default channel. An absent key keeps the readers' 'efactura-sdk' default.
        $channel = Arr::get($sdk, 'logging.channel');
        if ($channel !== null && (! is_string($channel) || trim($channel) === '')) {
            Arr::set($sdk, 'logging.channel', null);
        }

        $config->set('efactura-sdk', $sdk);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'efactura-sdk');

        $this->defineLogChannel();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/efactura-sdk.php' => config_path('efactura-sdk.php'),
            ], 'efactura-sdk-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/efactura-sdk'),
            ], 'efactura-sdk-translations');
        }
    }

    /**
     * Define the SDK's log channel as a stack over the host's default when the host
     * did not define it.
     *
     * An undefined channel makes Laravel build a fresh emergency logger, and log an
     * emergency entry, on every Log::channel() call. Runs at boot, so it also holds
     * with a cached config.
     */
    private function defineLogChannel(): void
    {
        $config = $this->app->make('config');
        $channel = $config->get('efactura-sdk.logging.channel', 'efactura-sdk');
        $default = $config->get('logging.default');

        // A stack over itself would recurse; a host whose default is this undefined name is broken anyway.
        if (! is_string($channel) || $channel === '' || ! is_string($default) || $default === $channel
            || $config->get("logging.channels.{$channel}") !== null) {
            return;
        }

        $config->set("logging.channels.{$channel}", ['driver' => 'stack', 'channels' => [$default]]);
    }
}
