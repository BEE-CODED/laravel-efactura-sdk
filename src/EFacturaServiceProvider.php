<?php

declare(strict_types=1);

namespace BeeCoded\EFactura;

use BeeCoded\EFactura\Builders\InvoiceBuilder;
use BeeCoded\EFactura\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFactura\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFactura\Contracts\UblBuilderInterface;
use BeeCoded\EFactura\Services\AnafAuthenticator;
use BeeCoded\EFactura\Services\ApiClients\AnafDetailsClient;
use BeeCoded\EFactura\Services\RateLimiter;
use BeeCoded\EFactura\Services\UblBuilder;
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
 * use BeeCoded\EFactura\Services\ApiClients\EFacturaClient;
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
            __DIR__.'/../config/efactura.php',
            'efactura'
        );

        // Authenticator for OAuth flow (stateless - returns tokens to caller)
        // Note: This will throw AuthenticationException if OAuth credentials are not configured.
        // Only resolve this service when you need OAuth functionality.
        $this->app->singleton(AnafAuthenticatorInterface::class, function ($app) {
            $config = $app['config']['efactura'];

            // Check if OAuth is configured before attempting to create authenticator
            if (empty($config['oauth']['client_id'] ?? null)) {
                throw new \BeeCoded\EFactura\Exceptions\AuthenticationException(
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
        $this->app->alias(AnafAuthenticatorInterface::class, 'efactura.auth');
        $this->app->alias(AnafDetailsClientInterface::class, 'anaf-details');
        $this->app->alias(UblBuilderInterface::class, 'efactura.ubl');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/efactura.php' => config_path('efactura.php'),
            ], 'efactura-config');
        }
    }
}
