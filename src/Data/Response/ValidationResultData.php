<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Data\Response;

use Spatie\LaravelData\Data;

/**
 * Response from XML validation operation.
 * Maps to TypeScript ValidationResponse interface.
 */
class ValidationResultData extends Data
{
    public function __construct(
        /** Whether the document is valid */
        public bool $valid,

        /** Validation details/messages */
        public ?string $details = null,

        /** Additional info */
        public ?string $info = null,

        /** Error messages array */
        /** @var string[]|null */
        public ?array $errors = null,
    ) {}

    /**
     * Create from ANAF API response.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        return new self(
            valid: (bool) ($response['valid'] ?? false),
            details: $response['details'] ?? null,
            info: $response['info'] ?? null,
            errors: $response['Errors'] ?? null,
        );
    }

    /**
     * Create a successful validation result.
     */
    public static function success(?string $details = null): self
    {
        return new self(
            valid: true,
            details: $details,
        );
    }

    /**
     * Create a failed validation result.
     *
     * @param  string[]|null  $errors
     */
    public static function failure(?string $details = null, ?array $errors = null): self
    {
        return new self(
            valid: false,
            details: $details,
            errors: $errors,
        );
    }
}
