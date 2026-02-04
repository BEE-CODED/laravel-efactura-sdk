<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Data\Company;

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
        return new self(
            isInactive: (bool) ($data['statusInactivi'] ?? false),
            inactiveDate: self::parseDate($data['dataInactivare'] ?? null),
            reactivationDate: self::parseDate($data['dataReactivare'] ?? null),
            publishDate: self::parseDate($data['dataPublicare'] ?? null),
            deregistrationDate: self::parseDate($data['dataRadiere'] ?? null),
        );
    }

    /**
     * Parse a date string from ANAF response.
     */
    private static function parseDate(?string $date): ?Carbon
    {
        if ($date === null || $date === '' || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception) {
            return null;
        }
    }
}
