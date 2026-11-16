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
        return new self(
            isActive: AnafValue::flag($data['statusSplitTVA'] ?? null),
            startDate: self::parseDate($data['dataInceputSplitTVA'] ?? null),
            cancelDate: self::parseDate($data['dataAnulareSplitTVA'] ?? null),
        );
    }

    /**
     * Parse a date string from ANAF response.
     */
    private static function parseDate(mixed $date): ?Carbon
    {
        $date = AnafValue::text($date);

        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception) {
            return null;
        }
    }
}
