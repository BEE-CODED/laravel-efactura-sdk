<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use Spatie\LaravelData\Data;

/**
 * Party information (supplier or customer) for an invoice.
 *
 * Maps to TypeScript Party interface.
 */
class PartyData extends Data
{
    public function __construct(
        /** The legal name of the party as registered */
        public string $registrationName,
        /** CIF/CUI with "RO" prefix for VAT payers (e.g., "RO12345678") */
        public string $companyId,
        /** Address of the party */
        public AddressData $address,
        /** ONRC identifier / registration number (e.g., "J40/1234/2020") */
        public ?string $registrationNumber = null,
        /** Whether the party is a VAT payer */
        public bool $isVatPayer = false,
    ) {}
}
