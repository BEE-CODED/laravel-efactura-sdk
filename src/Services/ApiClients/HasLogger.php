<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Services\ApiClients;

use Psr\Log\LoggerInterface;

interface HasLogger
{
    public static function getLogger(): LoggerInterface;
}
