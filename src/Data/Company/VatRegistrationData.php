<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use BeeCoded\EFacturaSdk\Support\AnafValue;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * VAT registration data (RTVAI - TVA la incasare) from ANAF.
 *
 * Contains information about the company's cash-based VAT registration status.
 */
class VatRegistrationData extends Data
{
    public function __construct(
        public bool $isActive = false,
        public ?Carbon $startDate = null,
        public ?Carbon $endDate = null,
        public ?Carbon $updateDate = null,
        public ?Carbon $publishDate = null,
        public ?string $actType = null,
    ) {}

    /**
     * Create VatRegistrationData from ANAF inregistrare_RTVAI response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF inregistrare_RTVAI data
     */
    public static function fromAnafResponse(array $data): self
    {
        // laravel-data sends ::from($dto->toArray()) here too: an array with none of ANAF's keys is this DTO's own shape.
        if (array_intersect_key($data, array_flip(['statusTvaIncasare', 'dataInceputTvaInc', 'dataSfarsitTvaInc', 'dataActualizareTvaInc', 'dataPublicareTvaInc', 'tipActTvaInc'])) === []) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        return new self(
            isActive: AnafValue::flag($data['statusTvaIncasare'] ?? null),
            startDate: AnafValue::date($data['dataInceputTvaInc'] ?? null),
            endDate: AnafValue::date($data['dataSfarsitTvaInc'] ?? null),
            updateDate: AnafValue::date($data['dataActualizareTvaInc'] ?? null),
            publishDate: AnafValue::date($data['dataPublicareTvaInc'] ?? null),
            actType: AnafValue::text($data['tipActTvaInc'] ?? null),
        );
    }
}
