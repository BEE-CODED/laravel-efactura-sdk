<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Data\Company;

use Spatie\LaravelData\Data;

/**
 * Address data from ANAF company lookup.
 *
 * Contains address information for company headquarters (sediu social)
 * or fiscal domicile (domiciliu fiscal).
 */
class AddressData extends Data
{
    public function __construct(
        public ?string $street = null,
        public ?string $streetNumber = null,
        public ?string $city = null,
        public ?string $cityCode = null,
        public ?string $county = null,
        public ?string $countyCode = null,
        public ?string $countyAutoCode = null,
        public ?string $country = null,
        public ?string $postalCode = null,
        public ?string $details = null,
    ) {}

    /**
     * Get the full formatted address.
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->street,
            $this->streetNumber ? "nr. {$this->streetNumber}" : null,
            $this->details,
            $this->city,
            $this->county,
            $this->postalCode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Create AddressData from ANAF headquarters address response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF adresa_sediu_social data
     */
    public static function fromHeadquartersResponse(array $data): self
    {
        return new self(
            street: $data['sdenumire_Strada'] ?? null,
            streetNumber: $data['snumar_Strada'] ?? null,
            city: $data['sdenumire_Localitate'] ?? null,
            cityCode: $data['scod_Localitate'] ?? null,
            county: $data['sdenumire_Judet'] ?? null,
            countyCode: $data['scod_Judet'] ?? null,
            countyAutoCode: $data['scod_JudetAuto'] ?? null,
            country: $data['stara'] ?? null,
            postalCode: $data['scod_Postal'] ?? null,
            details: $data['sdetalii_Adresa'] ?? null,
        );
    }

    /**
     * Create AddressData from ANAF fiscal domicile address response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF adresa_domiciliu_fiscal data
     */
    public static function fromFiscalDomicileResponse(array $data): self
    {
        return new self(
            street: $data['ddenumire_Strada'] ?? null,
            streetNumber: $data['dnumar_Strada'] ?? null,
            city: $data['ddenumire_Localitate'] ?? null,
            cityCode: $data['dcod_Localitate'] ?? null,
            county: $data['ddenumire_Judet'] ?? null,
            countyCode: $data['dcod_Judet'] ?? null,
            countyAutoCode: $data['dcod_JudetAuto'] ?? null,
            country: $data['dtara'] ?? null,
            postalCode: $data['dcod_Postal'] ?? null,
            details: $data['ddetalii_Adresa'] ?? null,
        );
    }
}
