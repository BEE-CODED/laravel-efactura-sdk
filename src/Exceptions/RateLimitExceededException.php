<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Exceptions;

/**
 * Exception thrown when API rate limit is exceeded.
 */
class RateLimitExceededException extends EFacturaException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly int $remaining = 0,
        public readonly int $retryAfterSeconds = 60,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 429, $previous, $context);
    }
}
