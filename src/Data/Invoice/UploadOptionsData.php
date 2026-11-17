<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\StandardType;
use Spatie\LaravelData\Data;

/**
 * Options for uploading an invoice to ANAF e-Factura.
 *
 * Maps to TypeScript UploadOptions interface.
 *
 * Build it with `new` and real bools, or validateAndCreate(): ::from() does not validate, and PHP
 * reads any non-empty string (including "false") as true, which sends extern/autofactura/executare=DA.
 */
class UploadOptionsData extends Data
{
    public function __construct(
        /** Document standard type (UBL, CN, CII, RASP) */
        public ?StandardType $standard = null,
        /** The buyer is not a Romanian entity (sends extern=DA) */
        public bool $extern = false,
        /** Self-billed invoice (autofactura) - invoice issued by buyer on behalf of supplier */
        public bool $selfBilled = false,
        /** Execution/enforcement invoice (executare silita) */
        public bool $executare = false,
    ) {}

    /**
     * Get the standard type, defaulting to UBL.
     */
    public function getStandard(): StandardType
    {
        return $this->standard ?? StandardType::UBL;
    }
}
