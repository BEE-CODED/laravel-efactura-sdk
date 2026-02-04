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

        // Authenticator for OAuth flow (stateless - returns tokens to caller)
        // Note: This will throw AuthenticationException if OAuth credentials are not configured.
        // Only resolve this service when you need OAuth functionality.
        $this->app->singleton(AnafAuthenticatorInterface::class, function ($app) {
            $config = $app['config']['efactura-sdk'];

            // Check if OAuth is configured before attempting to create authenticator
            if (empty($config['oauth']['client_id'] ?? null)) {
                throw new \BeeCoded\EFacturaSdk\Exceptions\AuthenticationException(
                    'OAuth credentials not configured. Set EFACTURA_CLIENT_ID, EFACTURA_CLIENT_SECRET, and EFACTURA_REDIRECT_URI in your environment, or resolve this service only when OAuth is needed.'
                );
            }

            return new AnafAuthenticator(
                http: $app->make(HttpFactory::class),
                config: $config
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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/efactura-sdk.php' => config_path('efactura-sdk.php'),
            ], 'efactura-sdk-config');
        }
    }
}
