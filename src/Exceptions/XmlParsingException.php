<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Exceptions;

/**
 * Exception for XML parsing failures.
 * Stores the raw response for debugging purposes.
 */
class XmlParsingException extends EFacturaException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly ?string $rawResponse = null,
        int $code = 500,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
