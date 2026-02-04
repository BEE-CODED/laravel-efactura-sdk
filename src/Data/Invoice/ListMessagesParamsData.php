<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\MessageFilter;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Data;

/**
 * Parameters for listing messages from ANAF e-Factura.
 *
 * Maps to TypeScript ListMessagesParams interface.
 */
class ListMessagesParamsData extends Data
{
    public function __construct(
        /** Company fiscal identifier (CIF/CUI without RO prefix) */
        public string $cif,
        /** Number of days to look back (1-60) */
        #[Between(1, 60)]
        public int $days,
        /** Filter by message type (optional) */
        public ?MessageFilter $filter = null,
    ) {}
}
