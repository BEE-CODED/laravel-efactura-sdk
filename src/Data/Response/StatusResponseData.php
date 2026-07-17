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

        /** Download ID (present for both ok and nok responses) */
        public ?string $idDescarcare = null,

        /** Error messages */
        /** @var string[]|null */
        public ?array $errors = null,
    ) {}

    /**
     * Create from ANAF API response.
     *
     * Handles ANAF's "200 OK but actually an error" shape. A stareMesaj query for an
     * id_incarcare ANAF does not recognise comes back HTTP 200 with {"eroare": "..."}
     * and no stare at all -- the same convention /descarcare is guarded against in
     * EFacturaClient::guardDownloadBody(). The message is lifted into $errors so it
     * reaches the caller instead of being dropped on the floor.
     *
     * $stare is deliberately left null in that case. An "eroare" body means ANAF told
     * us nothing about the document, which is NOT ANAF rejecting it on its merits
     * (stare=nok): the upload may well have been filed. Mapping it to Failed would
     * mark a possibly-filed invoice as validation-failed, so callers must keep
     * treating it as indeterminate. See hasAnafError().
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        $stare = null;
        if (isset($response['stare'])) {
            $stare = UploadStatusValue::tryFrom($response['stare']);
        }

        $errors = $response['Errors'] ?? null;

        // Only when ANAF sent no structured Errors list: a real one always wins.
        if ($errors === null && isset($response['eroare']) && is_string($response['eroare'])) {
            $errors = [$response['eroare']];
        }

        return new self(
            stare: $stare,
            idDescarcare: $response['id_descarcare'] ?? null,
            errors: $errors,
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

    /**
     * Whether ANAF answered with an error instead of a status.
     *
     * True exactly when there is no recognisable stare but ANAF did say something --
     * the 200 + {"eroare"} shape. This is NOT a verdict on the document: it means the
     * upload's state could not be determined, so a caller should park it for a human
     * rather than complete it, fail it, or keep polling. isReady(), isFailed() and
     * isInProgress() are all false here by design.
     */
    public function hasAnafError(): bool
    {
        return $this->stare === null && ! empty($this->errors);
    }
}
