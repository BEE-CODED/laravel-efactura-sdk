<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Exceptions;

/**
 * Exception for validation failures.
 * Thrown when input data fails validation or XML validation fails.
 */
class ValidationException extends EFacturaException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        int $code = 422,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
