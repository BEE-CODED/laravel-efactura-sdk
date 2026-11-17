<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use BeeCoded\EFacturaSdk\Support\AnafValue;
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
        // laravel-data sends ::from($dto->toArray()) here too: hand its own shape to the normal pipeline.
        if (! array_key_exists('data_inceput_ScpTVA', $data) && array_key_exists('startDate', $data)) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        $message = $data['mesaj_ScpTVA'] ?? null;

        return new self(
            startDate: AnafValue::date($data['data_inceput_ScpTVA'] ?? null),
            endDate: AnafValue::date($data['data_sfarsit_ScpTVA'] ?? null),
            cancellationDate: AnafValue::date($data['data_anul_imp_ScpTVA'] ?? null),
            message: is_string($message) && trim($message) !== '' ? $message : null,
        );
    }
}
