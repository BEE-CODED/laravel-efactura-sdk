<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use Psr\Log\LoggerInterface;

interface HasLogger
{
    public static function getLogger(): LoggerInterface;
}
