<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Data\Response;

use Beecoded\EFactura\Enums\ExecutionStatus;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Response from document upload operation.
 * Maps to TypeScript UploadInvoiceResponseBody interface.
 */
#[MapInputName(SnakeCaseMapper::class)]
class UploadResponseData extends Data
{
    public function __construct(
        /** Execution status (0 = success, 1 = error) */
        public ExecutionStatus $executionStatus,

        /** ANAF response timestamp */
        public ?string $dateResponse = null,

        /** Upload ID (only present on success) */
        public ?string $indexIncarcare = null,

        /** Error messages (only present on error) */
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
        // Validate ExecutionStatus exists and use tryFrom for safe enum parsing
        $statusValue = $response['ExecutionStatus'] ?? null;
        if ($statusValue === null) {
            // Default to error if ExecutionStatus is missing
            $executionStatus = ExecutionStatus::Error;
        } else {
            // Use tryFrom to safely parse enum, default to Error on invalid value
            $executionStatus = ExecutionStatus::tryFrom((int) $statusValue) ?? ExecutionStatus::Error;
        }

        return new self(
            executionStatus: $executionStatus,
            dateResponse: $response['dateResponse'] ?? null,
            indexIncarcare: $response['index_incarcare'] ?? null,
            errors: $response['Errors'] ?? null,
        );
    }

    /**
     * Check if the upload was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->executionStatus === ExecutionStatus::Success;
    }

    /**
     * Check if the upload failed.
     */
    public function isFailed(): bool
    {
        return $this->executionStatus === ExecutionStatus::Error;
    }
}
