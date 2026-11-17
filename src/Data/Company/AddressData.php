<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use BeeCoded\EFacturaSdk\Support\AnafValue;
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
        $parts = array_filter(
            [
                $this->street,
                $this->streetNumber !== null && $this->streetNumber !== '' ? "nr. {$this->streetNumber}" : null,
                $this->details,
                $this->city,
                $this->county,
                $this->postalCode,
                $this->country,
            ],
            // Explicit filter to avoid removing '0' values (PHP's array_filter treats '0' as falsy)
            fn ($value) => $value !== null && $value !== ''
        );

        return implode(', ', $parts);
    }

    /**
     * Create AddressData from ANAF headquarters address response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF adresa_sediu_social data
     */
    public static function fromHeadquartersResponse(array $data): self
    {
        // laravel-data sends ::from($dto->toArray()) here too: an array with none of ANAF's keys is this DTO's own shape.
        if (array_intersect_key($data, array_flip(['sdenumire_Strada', 'snumar_Strada', 'sdenumire_Localitate', 'scod_Localitate', 'sdenumire_Judet', 'scod_Judet', 'scod_JudetAuto', 'stara', 'scod_Postal', 'sdetalii_Adresa'])) === []) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        return new self(
            street: AnafValue::text($data['sdenumire_Strada'] ?? null),
            streetNumber: AnafValue::text($data['snumar_Strada'] ?? null),
            city: AnafValue::text($data['sdenumire_Localitate'] ?? null),
            cityCode: AnafValue::text($data['scod_Localitate'] ?? null),
            county: AnafValue::text($data['sdenumire_Judet'] ?? null),
            countyCode: AnafValue::text($data['scod_Judet'] ?? null),
            countyAutoCode: AnafValue::text($data['scod_JudetAuto'] ?? null),
            country: AnafValue::text($data['stara'] ?? null),
            postalCode: AnafValue::text($data['scod_Postal'] ?? null),
            details: AnafValue::text($data['sdetalii_Adresa'] ?? null),
        );
    }

    /**
     * Create AddressData from ANAF fiscal domicile address response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF adresa_domiciliu_fiscal data
     */
    public static function fromFiscalDomicileResponse(array $data): self
    {
        // laravel-data sends ::from($dto->toArray()) here too: an array with none of ANAF's keys is this DTO's own shape.
        if (array_intersect_key($data, array_flip(['ddenumire_Strada', 'dnumar_Strada', 'ddenumire_Localitate', 'dcod_Localitate', 'ddenumire_Judet', 'dcod_Judet', 'dcod_JudetAuto', 'dtara', 'dcod_Postal', 'ddetalii_Adresa'])) === []) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        return new self(
            street: AnafValue::text($data['ddenumire_Strada'] ?? null),
            streetNumber: AnafValue::text($data['dnumar_Strada'] ?? null),
            city: AnafValue::text($data['ddenumire_Localitate'] ?? null),
            cityCode: AnafValue::text($data['dcod_Localitate'] ?? null),
            county: AnafValue::text($data['ddenumire_Judet'] ?? null),
            countyCode: AnafValue::text($data['dcod_Judet'] ?? null),
            countyAutoCode: AnafValue::text($data['dcod_JudetAuto'] ?? null),
            country: AnafValue::text($data['dtara'] ?? null),
            postalCode: AnafValue::text($data['dcod_Postal'] ?? null),
            details: AnafValue::text($data['ddetalii_Adresa'] ?? null),
        );
    }
}
