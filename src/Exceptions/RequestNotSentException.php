<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * A transport failure that provably happened before the request reached ANAF.
 *
 * Thrown instead of a plain ApiException(500) when cURL reports a failure that
 * precedes sending (proxy/DNS resolution, connect refused, TLS handshake, or a
 * connect-phase timeout with nothing transferred), after the client's retries.
 * ANAF never saw the request, so an upload can be sent again without filing it
 * twice. Every other transport failure stays a plain ApiException(500): the
 * request may have been delivered.
 *
 * Also thrown, before the first attempt and without retries, when the cache store
 * behind the SDK's rate limiter fails during metering (getPrevious() is the store error).
 */
class RequestNotSentException extends ApiException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, 500, null, $previous, $context);
    }
}
