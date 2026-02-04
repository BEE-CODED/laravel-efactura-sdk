<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Execution status for upload operations.
 * 0 indicates success, 1 indicates error.
 */
enum ExecutionStatus: int
{
    case Success = 0;
    case Error = 1;
}
