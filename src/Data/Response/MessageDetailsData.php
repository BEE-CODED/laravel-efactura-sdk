<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use BeeCoded\EFacturaSdk\Support\AnafValue;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Single message details in message list.
 * Maps to TypeScript MessageDetails interface.
 */
class MessageDetailsData extends Data
{
    public function __construct(
        /**
         * Download ID. Null when ANAF sent no usable value (missing, blank, or not a
         * string/integer); any other string is kept trimmed, so check ctype_digit
         * before downloading or keying on it.
         */
        public ?string $id,

        /** CIF number */
        public string $cif,

        /** Creation date */
        #[MapInputName('data_creare')]
        public string $dataCreare,

        /** Message type */
        public string $tip,

        /** Message details */
        public string $detalii,

        /** Request ID */
        #[MapInputName('id_solicitare')]
        public string $idSolicitare,
    ) {}

    /**
     * Create from ANAF API response item.
     *
     * Values are normalised, never cast blindly: a numeric id becomes a string, a
     * missing or empty id is null, and other fields that ANAF omits or sends as a
     * non-scalar are ''.
     *
     * laravel-data treats any static from*() taking an array as a magic creation
     * method, so ::from() hands this method EVERY array, including this DTO's own
     * toArray() output. Its own keys are read wherever ANAF's are absent, with the same normalisation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAnafResponse(array $data): self
    {
        return new self(
            id: AnafValue::id($data['id'] ?? null),
            cif: AnafValue::id($data['cif'] ?? null) ?? '',
            dataCreare: AnafValue::text($data['data_creare'] ?? $data['dataCreare'] ?? null) ?? '',
            tip: AnafValue::text($data['tip'] ?? null) ?? '',
            detalii: AnafValue::text($data['detalii'] ?? null) ?? '',
            idSolicitare: AnafValue::id($data['id_solicitare'] ?? $data['idSolicitare'] ?? null) ?? '',
        );
    }
}
