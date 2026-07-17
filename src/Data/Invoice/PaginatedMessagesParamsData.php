<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\MessageFilter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Parameters for paginated message listing from ANAF e-Factura.
 *
 * Maps to TypeScript PaginatedMessagesParams interface.
 */
class PaginatedMessagesParamsData extends Data
{
    public function __construct(
        /** Company fiscal identifier (CIF/CUI without RO prefix) */
        public string $cif,
        /** Start timestamp in milliseconds */
        public int $startTime,
        /** End timestamp in milliseconds */
        public int $endTime,
        /** Page number (1-indexed) */
        #[Min(1)]
        public int $page = 1,
        /** Filter by message type (optional) */
        public ?MessageFilter $filter = null,
    ) {}

    /**
     * Create from a Carbon date range.
     *
     * Accepts any CarbonInterface implementation, so immutable dates from
     * Date::use(CarbonImmutable::class) can be passed directly.
     */
    public static function fromDateRange(
        string $cif,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $page = 1,
        ?MessageFilter $filter = null,
    ): self {
        return new self(
            cif: $cif,
            startTime: $startDate->getTimestampMs(),
            endTime: $endDate->getTimestampMs(),
            page: $page,
            filter: $filter,
        );
    }

    /**
     * Get the start time as a Carbon instance.
     */
    public function getStartTimeAsCarbon(): Carbon
    {
        return Carbon::createFromTimestampMs($this->startTime);
    }

    /**
     * Get the end time as a Carbon instance.
     */
    public function getEndTimeAsCarbon(): Carbon
    {
        return Carbon::createFromTimestampMs($this->endTime);
    }
}
