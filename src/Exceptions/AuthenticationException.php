<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Exceptions;

/**
 * Exception for authentication failures.
 * Thrown when OAuth tokens are invalid, expired, or credentials are incorrect.
 */
class AuthenticationException extends EFacturaException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Authentication failed. Check your credentials or token.',
        int $code = 401,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
