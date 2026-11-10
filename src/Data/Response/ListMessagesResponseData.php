<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

use BeeCoded\EFacturaSdk\Support\AnafValue;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Response from list messages operation.
 * Maps to TypeScript ListMessagesResponse interface.
 */
class ListMessagesResponseData extends Data
{
    public function __construct(
        /** @var array<int, MessageDetailsData>|null Array of messages */
        #[DataCollectionOf(MessageDetailsData::class)]
        public ?array $mesaje = null,

        /** Serial number */
        public ?string $serial = null,

        /** CIF number */
        public ?string $cui = null,

        /** Response title */
        public ?string $titlu = null,

        /** Additional info */
        public ?string $info = null,

        /** Error message */
        #[MapInputName('eroare')]
        public ?string $error = null,

        /** Download error message */
        #[MapInputName('eroare_descarcare')]
        public ?string $downloadError = null,
    ) {}

    /**
     * Create from ANAF API response.
     *
     * Values are normalised, never taken verbatim: a numeric cui or serial becomes
     * a string, text fields that are not scalars become null, counts accept ints
     * and digit strings, and messages without an id keep a null id.
     *
     * laravel-data treats any static from*() taking an array as a magic creation
     * method, so ::from() hands this method EVERY array, including this DTO's own
     * toArray() output. A downloadError key without ANAF's
     * eroare_descarcare picks that shape (error for eroare); each message picks its own.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        $own = array_key_exists('downloadError', $response) && ! array_key_exists('eroare_descarcare', $response);

        $mesaje = null;
        if (isset($response['mesaje']) && is_array($response['mesaje'])) {
            // Filter to ensure all elements are arrays before mapping; re-index so the list stays a list
            $validItems = array_values(array_filter($response['mesaje'], 'is_array'));
            if (! empty($validItems)) {
                $mesaje = array_map(
                    fn (array $item) => MessageDetailsData::fromAnafResponse($item),
                    $validItems
                );
            }
        }

        return new self(
            mesaje: $mesaje,
            serial: AnafValue::id($response['serial'] ?? null),
            cui: AnafValue::id($response['cui'] ?? null),
            titlu: AnafValue::text($response['titlu'] ?? null),
            info: AnafValue::text($response['info'] ?? null),
            error: AnafValue::text($response[$own ? 'error' : 'eroare'] ?? null),
            downloadError: AnafValue::text($response[$own ? 'downloadError' : 'eroare_descarcare'] ?? null),
        );
    }

    /**
     * Check if there are any messages.
     */
    public function hasMessages(): bool
    {
        return ! empty($this->mesaje);
    }

    /**
     * Get the count of messages.
     */
    public function getMessageCount(): int
    {
        return $this->mesaje ? count($this->mesaje) : 0;
    }

    /**
     * Check if there was an error.
     */
    public function hasError(): bool
    {
        return $this->error !== null || $this->downloadError !== null;
    }
}
