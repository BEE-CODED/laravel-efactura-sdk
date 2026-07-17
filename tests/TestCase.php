<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Tests;

use BeeCoded\EFacturaSdk\EFacturaServiceProvider;
use BeeCoded\EFacturaSdk\Facades\EFacturaSdkAuth;
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
            'EFacturaSdkAuth' => EFacturaSdkAuth::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // This SDK merges its config under 'efactura-sdk' and reads only
        // config('efactura-sdk.*'). These previously set 'efactura.sandbox' and
        // 'efactura.cif' -- a copy-paste from the wrapper package's namespace that
        // nothing in this SDK has ever read.
        //
        // Pinning sandbox is worth keeping once it points at the real key: it stops a
        // developer's EFACTURA_SANDBOX=false leaking in and pointing the suite's URL
        // assertions at the production endpoints. There is no 'cif' key to correct --
        // the SDK takes the VAT number per client at construction, not from config.
        $app['config']->set('efactura-sdk.sandbox', true);
    }
}
