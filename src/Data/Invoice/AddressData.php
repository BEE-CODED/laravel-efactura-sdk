<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use Spatie\LaravelData\Data;

/**
 * Address information for a party (supplier or customer).
 *
 * Maps to TypeScript Address interface.
 */
class AddressData extends Data
{
    public function __construct(
        public string $street,
        public string $city,
        public ?string $postalZone = null,
        public ?string $county = null,
        public string $countryCode = 'RO',
    ) {}
}
