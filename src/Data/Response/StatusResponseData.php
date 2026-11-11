<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use BeeCoded\EFacturaSdk\Enums\StatusVerdict;
use BeeCoded\EFacturaSdk\Enums\UploadStatusValue;
use BeeCoded\EFacturaSdk\Support\AnafValue;
use Spatie\LaravelData\Data;

/**
 * Response from status check operation.
 * Maps to TypeScript StatusInvoiceResponse interface.
 */
class StatusResponseData extends Data
{
    public function __construct(
        /** Processing status (ok, nok, in prelucrare, XML cu erori nepreluat de sistem) */
        public ?UploadStatusValue $stare = null,

        /**
         * Download ID. Normally present for ok and nok, but not guaranteed: a nok
         * without one is not a verdict on the document, so callers should not treat
         * it as a validation failure. An XML <header> carrying only <Errors/>
         * (unknown upload id, "Nu aveti dreptul") has no stare at all: see hasAnafError().
         */
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
     * Values are normalised, never taken verbatim: an int id_descarcare becomes a
     * string, a stare that is not a string (or not a known status) is null, and
     * Errors / eroare may be a string, an {errorMessage} object or a list of
     * either -- empty messages are dropped and an empty list is null.
     *
     * laravel-data treats any static from*() taking an array as a magic creation
     * method, so ::from() hands this method EVERY array, including this DTO's own
     * toArray() output. An idDescarcare key without ANAF's
     * id_descarcare picks that shape, read with the same normalisation.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        $stare = $response['stare'] ?? null;
        $stare = $stare instanceof UploadStatusValue ? $stare->value : $stare;

        if (array_key_exists('idDescarcare', $response) && ! array_key_exists('id_descarcare', $response)) {
            return new self(
                stare: is_string($stare) ? UploadStatusValue::tryFrom($stare) : null,
                idDescarcare: AnafValue::id($response['idDescarcare']),
                errors: AnafValue::errors($response['errors'] ?? null),
            );
        }

        return new self(
            // A non-string stare (e.g. a number) is not a status ANAF defines: unknown, so null.
            stare: is_string($stare) ? UploadStatusValue::tryFrom($stare) : null,
            idDescarcare: AnafValue::id($response['id_descarcare'] ?? null),
            // Only when ANAF sent no usable Errors: a real one always wins over eroare.
            errors: AnafValue::errors($response['Errors'] ?? null)
                ?? AnafValue::errors($response['eroare'] ?? null),
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
     * Check if ANAF did not take in the uploaded file ("XML cu erori nepreluat de sistem").
     *
     * A final answer for the upload index, but not isFailed(): ANAF names no download id
     * (no response archive) for it.
     */
    public function isNotProcessed(): bool
    {
        return $this->stare === UploadStatusValue::NotProcessed;
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
     * the 200 + {"eroare"} shape, or an XML <header> carrying only <Errors/>. This is NOT a verdict on the document: it means the
     * upload's state could not be determined, so a caller should park it for a human
     * rather than complete it, fail it, or keep polling. isReady(), isFailed() and
     * isInProgress() are all false here by design.
     */
    public function hasAnafError(): bool
    {
        return $this->stare === null && ! empty($this->errors);
    }

    /**
     * What this answer means for the document. Ready and Rejected require a download id
     * of 1 to 64 digits; with any other id (or none) they are ...WithoutDownloadId, which
     * is no verdict on the document.
     */
    public function verdict(): StatusVerdict
    {
        $downloadId = $this->idDescarcare !== null && preg_match('/\A[0-9]{1,64}\z/', $this->idDescarcare) === 1;

        return match (true) {
            $this->isNotProcessed() => StatusVerdict::NotProcessed,
            $this->isInProgress() => StatusVerdict::InProgress,
            $this->isReady() => $downloadId ? StatusVerdict::Ready : StatusVerdict::ReadyWithoutDownloadId,
            $this->isFailed() => $downloadId ? StatusVerdict::Rejected : StatusVerdict::RejectedWithoutDownloadId,
            $this->hasAnafError() => StatusVerdict::AnafError,
            default => StatusVerdict::Unknown,
        };
    }
}
