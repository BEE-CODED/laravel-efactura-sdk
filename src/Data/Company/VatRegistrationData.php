<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Data\Company;

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
        return new self(
            isActive: (bool) ($data['statusTvaIncasare'] ?? false),
            startDate: self::parseDate($data['dataInceputTvaInc'] ?? null),
            endDate: self::parseDate($data['dataSfarsitTvaInc'] ?? null),
            updateDate: self::parseDate($data['dataActualizareTvaInc'] ?? null),
            publishDate: self::parseDate($data['dataPublicareTvaInc'] ?? null),
            actType: $data['tipActTvaInc'] ?? null,
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
