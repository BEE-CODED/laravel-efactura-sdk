<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Tests;

use BeeCoded\EFacturaSdk\EFacturaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            EFacturaServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'EFacturaSdkAuth' => \BeeCoded\EFacturaSdk\Facades\EFacturaSdkAuth::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('efactura.sandbox', true);
        $app['config']->set('efactura.cif', '12345678');
    }
}
