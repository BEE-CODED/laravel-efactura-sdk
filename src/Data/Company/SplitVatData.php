<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use BeeCoded\EFacturaSdk\Support\AnafValue;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * Split VAT registration data from ANAF.
 *
 * Contains information about the company's split VAT payment status.
 */
class SplitVatData extends Data
{
    public function __construct(
        public bool $isActive = false,
        public ?Carbon $startDate = null,
        public ?Carbon $cancelDate = null,
    ) {}

    /**
     * Create SplitVatData from ANAF inregistrare_SplitTVA response.
     *
     * @param  array<string, mixed>  $data  Raw ANAF inregistrare_SplitTVA data
     */
    public static function fromAnafResponse(array $data): self
    {
        // laravel-data sends ::from($dto->toArray()) here too: hand its own shape to the normal pipeline.
        if (! array_key_exists('statusSplitTVA', $data) && array_key_exists('cancelDate', $data)) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        return new self(
            isActive: AnafValue::flag($data['statusSplitTVA'] ?? null),
            startDate: AnafValue::date($data['dataInceputSplitTVA'] ?? null),
            cancelDate: AnafValue::date($data['dataAnulareSplitTVA'] ?? null),
        );
    }
}
