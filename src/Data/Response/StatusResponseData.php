<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use BeeCoded\EFacturaSdk\Enums\UploadStatusValue;
use Spatie\LaravelData\Data;

/**
 * Response from status check operation.
 * Maps to TypeScript StatusInvoiceResponse interface.
 */
class StatusResponseData extends Data
{
    public function __construct(
        /** Processing status (ok, nok, in prelucrare) */
        public ?UploadStatusValue $stare = null,

        /** Download ID (only present when stare = ok) */
        public ?string $idDescarcare = null,

        /** Error messages */
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
        $stare = null;
        if (isset($response['stare'])) {
            $stare = UploadStatusValue::tryFrom($response['stare']);
        }

        return new self(
            stare: $stare,
            idDescarcare: $response['id_descarcare'] ?? null,
            errors: $response['Errors'] ?? null,
        );
    }

    /**
     * Check if processing is complete and successful.
     */
    public function isReady(): bool
    {
        return $this->stare === UploadStatusValue::Ok;
    }

    /**
     * Check if processing failed.
     */
    public function isFailed(): bool
    {
        return $this->stare === UploadStatusValue::Failed;
    }

    /**
     * Check if still being processed.
     */
    public function isInProgress(): bool
    {
        return $this->stare === UploadStatusValue::InProgress;
    }
}
