<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Response;

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
        /** Array of messages */
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
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        $mesaje = null;
        if (isset($response['mesaje']) && is_array($response['mesaje'])) {
            // Filter to ensure all elements are arrays before mapping
            $validItems = array_filter($response['mesaje'], 'is_array');
            if (! empty($validItems)) {
                $mesaje = array_map(
                    fn (array $item) => MessageDetailsData::fromAnafResponse($item),
                    $validItems
                );
            }
        }

        return new self(
            mesaje: $mesaje,
            serial: $response['serial'] ?? null,
            cui: $response['cui'] ?? null,
            titlu: $response['titlu'] ?? null,
            info: $response['info'] ?? null,
            error: $response['eroare'] ?? null,
            downloadError: $response['eroare_descarcare'] ?? null,
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
