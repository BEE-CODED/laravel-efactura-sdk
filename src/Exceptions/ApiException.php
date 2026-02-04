<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * Exception for API call failures.
 * Contains HTTP status code and optional response details.
 */
class ApiException extends EFacturaException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $details = null,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $statusCode, $previous, $context);
    }
}
