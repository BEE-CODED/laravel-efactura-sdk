<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * A single VAT registration period from ANAF's
 * inregistrare_scop_Tva.perioade_TVA array (v9 response shape).
 */
class VatPeriodData extends Data
{
    public function __construct(
        /**
         * Period start date (data_inceput_ScpTVA).
         */
        public ?Carbon $startDate = null,

        /**
         * Period end date (data_sfarsit_ScpTVA); null for an open period.
         */
        public ?Carbon $endDate = null,

        /**
         * VAT registration cancellation date (data_anul_imp_ScpTVA).
         */
        public ?Carbon $cancellationDate = null,

        /**
         * ANAF's explanatory message for the period (mesaj_ScpTVA).
         */
        public ?string $message = null,
    ) {}

    /**
     * Create VatPeriodData from a single perioade_TVA entry.
     *
     * @param  array<string, mixed>  $data  Raw ANAF perioade_TVA entry
     */
    public static function fromAnafResponse(array $data): self
    {
        $message = $data['mesaj_ScpTVA'] ?? null;

        return new self(
            startDate: self::parseDate($data['data_inceput_ScpTVA'] ?? null),
            endDate: self::parseDate($data['data_sfarsit_ScpTVA'] ?? null),
            cancellationDate: self::parseDate($data['data_anul_imp_ScpTVA'] ?? null),
            message: is_string($message) && trim($message) !== '' ? $message : null,
        );
    }

    /**
     * Parse a date string from ANAF response.
     */
    private static function parseDate(?string $date): ?Carbon
    {
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
