<?php

declare(strict_types=1);

namespace Beecoded\EFactura;

use Beecoded\EFactura\Contracts\EFacturaClientInterface;
use Beecoded\EFactura\Services\EFacturaClient;
use Illuminate\Support\ServiceProvider;

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

        $this->app->singleton(EFacturaClientInterface::class, function ($app) {
            return new EFacturaClient(
                config: $app['config']['efactura']
            );
        });

        $this->app->alias(EFacturaClientInterface::class, 'efactura');
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

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'efactura-migrations');
        }
    }
}
