<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

use BeeCoded\EFacturaSdk\Enums\RateLimitScope;

/**
 * Exception thrown when API rate limit is exceeded.
 *
 * $scope names the limit that was hit, so a batch can tell a per-message limit
 * (skip that message, carry on) from a global one (stop the batch). The SDK's
 * RateLimiter always sets it; it is null only when other code throws this
 * exception without one.
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
        array $context = [],
        public readonly ?RateLimitScope $scope = null,
    ) {
        parent::__construct($message, 429, $previous, $context);
    }
}
