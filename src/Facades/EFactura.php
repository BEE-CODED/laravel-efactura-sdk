<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Beecoded\EFactura\Services\EFacturaClient
 */
final class EFactura extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'efactura';
    }
}
