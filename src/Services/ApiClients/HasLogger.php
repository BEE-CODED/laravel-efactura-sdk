<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Services\ApiClients;

use Psr\Log\LoggerInterface;

interface HasLogger
{
    public static function getLogger(): LoggerInterface;
}
