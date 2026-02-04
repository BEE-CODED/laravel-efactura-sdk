<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Tests;

use BeeCoded\EFacturaSdk\EFacturaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            EFacturaServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'EFactura' => \BeeCoded\EFacturaSdk\Facades\EFactura::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('efactura.sandbox', true);
        $app['config']->set('efactura.cif', '12345678');
    }
}
