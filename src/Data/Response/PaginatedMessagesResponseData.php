<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Data\Response;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Response from paginated list messages operation.
 * Maps to TypeScript PaginatedListMessagesResponse interface.
 */
class PaginatedMessagesResponseData extends Data
{
    public function __construct(
        /** Array of messages */
        #[DataCollectionOf(MessageDetailsData::class)]
        public ?array $mesaje = null,

        /** Number of records in current page */
        #[MapInputName('numar_inregistrari_in_pagina')]
        public ?int $recordsInPage = null,

        /** Total number of records per page (page size limit) */
        #[MapInputName('numar_total_inregistrari_per_pagina')]
        public ?int $recordsPerPage = null,

        /** Total number of records across all pages */
        #[MapInputName('numar_total_inregistrari')]
        public ?int $totalRecords = null,

        /** Total number of pages */
        #[MapInputName('numar_total_pagini')]
        public ?int $totalPages = null,

        /** Current page index */
        #[MapInputName('index_pagina_curenta')]
        public ?int $currentPage = null,

        /** Serial number */
        public ?string $serial = null,

        /** CIF number */
        public ?string $cui = null,

        /** Response title */
        public ?string $titlu = null,

        /** Error message */
        #[MapInputName('eroare')]
        public ?string $error = null,
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
            recordsInPage: isset($response['numar_inregistrari_in_pagina']) ? (int) $response['numar_inregistrari_in_pagina'] : null,
            recordsPerPage: isset($response['numar_total_inregistrari_per_pagina']) ? (int) $response['numar_total_inregistrari_per_pagina'] : null,
            totalRecords: isset($response['numar_total_inregistrari']) ? (int) $response['numar_total_inregistrari'] : null,
            totalPages: isset($response['numar_total_pagini']) ? (int) $response['numar_total_pagini'] : null,
            currentPage: isset($response['index_pagina_curenta']) ? (int) $response['index_pagina_curenta'] : null,
            serial: $response['serial'] ?? null,
            cui: $response['cui'] ?? null,
            titlu: $response['titlu'] ?? null,
            error: $response['eroare'] ?? null,
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
     * Get the count of messages in current page.
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
        return $this->error !== null;
    }

    /**
     * Check if there is a next page.
     * Uses 1-based indexing (page 1 = first page).
     */
    public function hasNextPage(): bool
    {
        if ($this->currentPage === null || $this->totalPages === null) {
            return false;
        }

        return $this->currentPage < $this->totalPages;
    }

    /**
     * Check if there is a previous page.
     * Uses 1-based indexing (page 1 = first page).
     */
    public function hasPreviousPage(): bool
    {
        if ($this->currentPage === null) {
            return false;
        }

        return $this->currentPage > 1;
    }

    /**
     * Check if this is the first page.
     * Uses 1-based indexing (page 1 = first page).
     */
    public function isFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    /**
     * Check if this is the last page.
     * Uses 1-based indexing (page 1 = first page).
     */
    public function isLastPage(): bool
    {
        if ($this->currentPage === null || $this->totalPages === null) {
            return true;
        }

        return $this->currentPage >= $this->totalPages;
    }
}
