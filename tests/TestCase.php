<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Tests;

use Beecoded\EFactura\EFacturaServiceProvider;
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
            'EFactura' => \Beecoded\EFactura\Facades\EFactura::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('efactura.sandbox', true);
        $app['config']->set('efactura.cif', '12345678');
    }
}
