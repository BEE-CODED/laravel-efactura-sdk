<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use BeeCoded\EFacturaSdk\Support\AnafValue;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * Inactive status data from ANAF.
 *
 * Contains information about the company's inactive/deregistered status.
 */
class InactiveStatusData extends Data
{
    public function __construct(
        public bool $isInactive = false,
        public ?Carbon $inactiveDate = null,
        public ?Carbon $reactivationDate = null,
        public ?Carbon $publishDate = null,
        public ?Carbon $deregistrationDate = null,
    ) {}

    /**
     * Create InactiveStatusData from ANAF stare_inactiv response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF stare_inactiv data
     */
    public static function fromAnafResponse(array $data): self
    {
        // laravel-data sends ::from($dto->toArray()) here too: an array with none of ANAF's keys is this DTO's own shape.
        if (array_intersect_key($data, array_flip(['statusInactivi', 'dataInactivare', 'dataReactivare', 'dataPublicare', 'dataRadiere'])) === []) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        return new self(
            isInactive: AnafValue::flag($data['statusInactivi'] ?? null),
            inactiveDate: AnafValue::date($data['dataInactivare'] ?? null),
            reactivationDate: AnafValue::date($data['dataReactivare'] ?? null),
            publishDate: AnafValue::date($data['dataPublicare'] ?? null),
            deregistrationDate: AnafValue::date($data['dataRadiere'] ?? null),
        );
    }
}
