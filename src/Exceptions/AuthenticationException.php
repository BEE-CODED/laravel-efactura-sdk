<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * Exception for authentication failures. getCode() says which: 400/401 = ANAF refused the grant or token
 * (reconnect the company); 0 = no answer (OAuth server unreachable, refresh-lock timeout, lock or token store
 * failure, missing OAuth configuration: retry later or fix the configuration); any other code = the
 * OAuth server's HTTP status. See README "Exception Handling".
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
