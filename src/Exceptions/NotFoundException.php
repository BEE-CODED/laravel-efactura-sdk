<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * Exception for resource not found scenarios.
 * Thrown when a document, message, or other resource cannot be found.
 */
class NotFoundException extends EFacturaException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Resource not found.',
        int $code = 404,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
