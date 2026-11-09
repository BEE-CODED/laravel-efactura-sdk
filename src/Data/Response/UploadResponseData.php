<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use BeeCoded\EFacturaSdk\Enums\ExecutionStatus;
use BeeCoded\EFacturaSdk\Support\AnafValue;
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
        /**
         * Execution status (0 = success, 1 = error). Null when ANAF's status was
         * missing or not exactly 0/1: the status was not recognised, so the outcome is unknown.
         */
        public ?ExecutionStatus $executionStatus,

        /** ANAF response timestamp */
        public ?string $dateResponse = null,

        /** Upload index: present whenever ANAF took the document in, whatever the status says */
        public ?string $indexIncarcare = null,

        /** Error messages, when the status is not success (error or unrecognised) */
        /** @var string[]|null */
        public ?array $errors = null,
    ) {}

    /**
     * Create from ANAF API response.
     *
     * Values are normalised, never taken verbatim: an int index or dateResponse
     * becomes a string (a float or an array becomes null), and Errors may be a
     * string, an {errorMessage} object or a list of either -- empty messages are
     * dropped and an empty list is null.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        // Only an exact 0/1 (int or numeric string) is a recognised status; anything else is unknown.
        $executionStatus = match ($response['ExecutionStatus'] ?? null) {
            0, '0' => ExecutionStatus::Success,
            1, '1' => ExecutionStatus::Error,
            default => null,
        };

        return new self(
            executionStatus: $executionStatus,
            dateResponse: AnafValue::id($response['dateResponse'] ?? null),
            indexIncarcare: AnafValue::id($response['index_incarcare'] ?? null),
            errors: AnafValue::errors($response['Errors'] ?? null),
        );
    }

    /**
     * Check if the upload was successful (ANAF explicitly answered 0).
     *
     * False does not mean failed: see isFailed(); both are false when the status is unknown.
     */
    public function isSuccessful(): bool
    {
        return $this->executionStatus === ExecutionStatus::Success;
    }

    /**
     * Check if the upload failed (ANAF explicitly answered 1).
     *
     * False does not mean successful: both are false when the status is unknown.
     */
    public function isFailed(): bool
    {
        return $this->executionStatus === ExecutionStatus::Error;
    }
}
