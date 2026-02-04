<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

use Exception;

class EFacturaException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }
}
