<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Data\Invoice;

use BeeCoded\EFactura\Enums\StandardType;
use Spatie\LaravelData\Data;

/**
 * Options for uploading an invoice to ANAF e-Factura.
 *
 * Maps to TypeScript UploadOptions interface.
 */
class UploadOptionsData extends Data
{
    public function __construct(
        /** Document standard type (UBL, CN, CII, RASP) */
        public ?StandardType $standard = null,
        /** External invoice (B2B outside e-Factura system) */
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
