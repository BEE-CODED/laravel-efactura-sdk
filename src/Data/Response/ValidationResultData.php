<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use BeeCoded\EFacturaSdk\Support\AnafValue;
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
     * The one parser for ANAF's validare/verificare answers, used by
     * EFacturaClient::validateXml(), verifySignature() and convertXmlToPdf():
     * - valid: an explicit yes in "valid" (see AnafValue::flag()) or "stare": "ok";
     * - details: mesaj, then detalii, details, message;
     * - info: info, then trace_id;
     * - errors: Messages[].message, then Errors, then eroare, as one list (null when empty).
     *
     * laravel-data treats any static from*() taking an array as a magic creation
     * method, so ::from() hands this method EVERY array, including this DTO's own
     * toArray() output. Its errors key (ANAF sends Errors) is read
     * too, so that shape keeps its errors; valid, details and info share their keys.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        // Messages carries {message} objects (or plain strings); AnafValue::errors() reads errorMessage.
        $messages = $response['Messages'] ?? null;
        if (is_array($messages)) {
            $messages = array_map(
                fn (mixed $entry): mixed => is_array($entry) ? ($entry['message'] ?? null) : $entry,
                array_is_list($messages) ? $messages : [$messages],
            );
        }

        $errors = array_merge(
            AnafValue::errors($messages) ?? [],
            AnafValue::errors($response['Errors'] ?? null) ?? [],
            AnafValue::errors($response['eroare'] ?? null) ?? [],
            AnafValue::errors($response['errors'] ?? null) ?? [],
        );

        return new self(
            valid: AnafValue::flag($response['valid'] ?? null) || ($response['stare'] ?? null) === 'ok',
            details: AnafValue::text($response['mesaj'] ?? null)
                ?? AnafValue::text($response['detalii'] ?? null)
                ?? AnafValue::text($response['details'] ?? null)
                ?? AnafValue::text($response['message'] ?? null),
            info: AnafValue::text($response['info'] ?? null) ?? AnafValue::text($response['trace_id'] ?? null),
            errors: $errors === [] ? null : $errors,
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
