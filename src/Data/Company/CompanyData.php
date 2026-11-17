<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use BeeCoded\EFacturaSdk\Enums\RegistrationStatus;
use BeeCoded\EFacturaSdk\Support\AnafValue;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * Company data from ANAF lookup.
 *
 * Contains comprehensive company information including general details,
 * VAT status, addresses, and registration statuses. This is the clean
 * DTO representation of ANAF's AnafFoundCompany response.
 */
class CompanyData extends Data
{
    public function __construct(
        /**
         * Company fiscal identification code (CUI/CIF) without RO prefix.
         */
        public string $cui,

        /**
         * Company name (denumire).
         */
        public string $name,

        /**
         * Company address from date_generale.
         */
        public ?string $address = null,

        /**
         * Trade register number (nrRegCom), e.g., J40/1234/2020.
         */
        public ?string $registrationNumber = null,

        /**
         * Phone number.
         */
        public ?string $phone = null,

        /**
         * Fax number.
         */
        public ?string $fax = null,

        /**
         * Postal code from date_generale.
         */
        public ?string $postalCode = null,

        /**
         * Whether the company is a VAT payer (platitor TVA).
         */
        public bool $isVatPayer = false,

        /**
         * VAT registration date.
         */
        public ?Carbon $vatRegistrationDate = null,

        /**
         * VAT deregistration date.
         */
        public ?Carbon $vatDeregistrationDate = null,

        /**
         * Whether company uses Split VAT (plata defalcata TVA).
         */
        public bool $isSplitVat = false,

        /**
         * Split VAT start date.
         */
        public ?Carbon $splitVatStartDate = null,

        /**
         * Whether company uses TVA la incasare (RTVAI).
         */
        public bool $isRtvai = false,

        /**
         * RTVAI start date.
         */
        public ?Carbon $rtvaiStartDate = null,

        /**
         * Whether the company is inactive.
         */
        public bool $isInactive = false,

        /**
         * Date when company became inactive.
         */
        public ?Carbon $inactiveDate = null,

        /**
         * Whether the company has been deregistered (radiat).
         */
        public bool $isDeregistered = false,

        /**
         * Deregistration date.
         */
        public ?Carbon $deregistrationDate = null,

        /**
         * Raw trade-registry status string (date_generale.stare_inregistrare),
         * e.g. "INREGISTRAT din data 26.06.2019" / "RADIERE din data 29.03.2024".
         */
        public ?string $registrationStatusRaw = null,

        /**
         * Parsed trade-registry status. Unknown = no verdict (fail-open).
         */
        public RegistrationStatus $registrationStatus = RegistrationStatus::Unknown,

        /**
         * Date extracted from the stare_inregistrare "din data dd.mm.yyyy" or "yyyy-mm-dd" suffix;
         * null when it is no real date.
         */
        public ?Carbon $registrationStatusDate = null,

        /**
         * Fiscal registration date (date_generale.data_inregistrare).
         */
        public ?Carbon $registrationDate = null,

        /**
         * Whether the company is enrolled in the RO e-Factura registry
         * (date_generale.statusRO_e_Factura) at the queried date.
         */
        public bool $isRegisteredInEFactura = false,

        /**
         * RO e-Factura registry enrollment date (data_inreg_Reg_RO_e_Factura).
         */
        public ?Carbon $eFacturaRegistrationDate = null,

        /**
         * VAT registration periods (inregistrare_scop_Tva.perioade_TVA),
         * in ANAF response order.
         *
         * @var list<VatPeriodData>
         */
        public array $vatPeriods = [],

        /**
         * Headquarters address (sediu social).
         */
        public ?AddressData $headquartersAddress = null,

        /**
         * Fiscal domicile address (domiciliu fiscal).
         */
        public ?AddressData $fiscalDomicileAddress = null,

        /**
         * Detailed RTVAI registration data.
         */
        public ?VatRegistrationData $rtvaiDetails = null,

        /**
         * Detailed Split VAT registration data.
         */
        public ?SplitVatData $splitVatDetails = null,

        /**
         * Detailed inactive status data.
         */
        public ?InactiveStatusData $inactiveStatusDetails = null,
    ) {}

    /**
     * Create CompanyData from ANAF found company response.
     *
     * Values are normalised, never taken verbatim: a numeric cui, phone or postal
     * code becomes a string, non-scalar text becomes null, a date that is not an
     * exact Y-m-d or d.m.Y calendar date is null, a nested
     * section that is not an object is treated as absent, and a flag is true only
     * for true, 1, "1" or "true".
     *
     * @param  array<string, mixed>  $data  Raw ANAF response for a single found company
     */
    public static function fromAnafResponse(array $data): self
    {
        // laravel-data sends every array given to ::from() here, its own toArray() too.
        if (! array_key_exists('date_generale', $data) && array_key_exists('isVatPayer', $data)) {
            return self::factory()->withoutMagicalCreation()->from($data);
        }

        $generalData = self::section($data, 'date_generale');
        $vatScope = self::section($data, 'inregistrare_scop_Tva');
        $rtvai = self::section($data, 'inregistrare_RTVAI');
        $inactive = self::section($data, 'stare_inactiv');
        $splitVat = self::section($data, 'inregistrare_SplitTVA');
        $headquarters = self::section($data, 'adresa_sediu_social');
        $fiscalDomicile = self::section($data, 'adresa_domiciliu_fiscal');

        // Parse RTVAI details
        $rtvaiDetails = ! empty($rtvai) ? VatRegistrationData::fromAnafResponse($rtvai) : null;

        // Parse Split VAT details
        $splitVatDetails = ! empty($splitVat) ? SplitVatData::fromAnafResponse($splitVat) : null;

        // Parse inactive status details
        $inactiveStatusDetails = ! empty($inactive) ? InactiveStatusData::fromAnafResponse($inactive) : null;

        // Parse addresses
        $headquartersAddress = ! empty($headquarters) ? AddressData::fromHeadquartersResponse($headquarters) : null;
        $fiscalDomicileAddress = ! empty($fiscalDomicile) ? AddressData::fromFiscalDomicileResponse($fiscalDomicile) : null;

        // Parse trade-registry status (stare_inregistrare)
        $rawStatus = $generalData['stare_inregistrare'] ?? null;
        $registrationStatusRaw = is_string($rawStatus) && trim($rawStatus) !== '' ? $rawStatus : null;
        $registrationStatus = RegistrationStatus::fromAnafStatus($registrationStatusRaw);
        $registrationStatusDate = self::parseStatusDate($registrationStatusRaw);

        // Deregistered when the inactive registry says so (dataRadiere) OR the
        // trade-registry status is RADIERE. Date prefers the inactive registry
        // (pinned by existing tests), falling back to the status-string date.
        $inactiveRadiereDate = $inactiveStatusDetails?->deregistrationDate;
        $isDeregistered = $inactiveRadiereDate !== null
            || $registrationStatus === RegistrationStatus::Deregistered;
        $deregistrationDate = $inactiveRadiereDate
            ?? ($registrationStatus === RegistrationStatus::Deregistered ? $registrationStatusDate : null);

        // Parse VAT periods (v9 nests dates in perioade_TVA; flat keys are the legacy shape)
        $vatPeriods = [];
        foreach (self::section($vatScope, 'perioade_TVA') as $period) {
            if (is_array($period)) {
                $vatPeriods[] = VatPeriodData::fromAnafResponse($period);
            }
        }
        $latestVatPeriod = self::latestVatPeriod($vatPeriods);

        return new self(
            cui: AnafValue::id($generalData['cui'] ?? null) ?? '',
            name: AnafValue::text($generalData['denumire'] ?? null) ?? '',
            address: AnafValue::text($generalData['adresa'] ?? null),
            registrationNumber: AnafValue::text($generalData['nrRegCom'] ?? null),
            phone: AnafValue::text($generalData['telefon'] ?? null),
            fax: AnafValue::text($generalData['fax'] ?? null),
            postalCode: AnafValue::text($generalData['codPostal'] ?? null),
            isVatPayer: AnafValue::flag($vatScope['scpTVA'] ?? null),
            vatRegistrationDate: AnafValue::date($vatScope['data_inceput_ScpTVA'] ?? null) ?? $latestVatPeriod?->startDate,
            vatDeregistrationDate: AnafValue::date($vatScope['data_sfarsit_ScpTVA'] ?? null) ?? $latestVatPeriod?->endDate,
            isSplitVat: AnafValue::flag($splitVat['statusSplitTVA'] ?? null),
            splitVatStartDate: $splitVatDetails?->startDate,
            isRtvai: AnafValue::flag($rtvai['statusTvaIncasare'] ?? null),
            rtvaiStartDate: $rtvaiDetails?->startDate,
            isInactive: AnafValue::flag($inactive['statusInactivi'] ?? null),
            inactiveDate: $inactiveStatusDetails?->inactiveDate,
            isDeregistered: $isDeregistered,
            deregistrationDate: $deregistrationDate,
            registrationStatusRaw: $registrationStatusRaw,
            registrationStatus: $registrationStatus,
            registrationStatusDate: $registrationStatusDate,
            registrationDate: AnafValue::date($generalData['data_inregistrare'] ?? null),
            isRegisteredInEFactura: AnafValue::flag($generalData['statusRO_e_Factura'] ?? null),
            eFacturaRegistrationDate: AnafValue::date($generalData['data_inreg_Reg_RO_e_Factura'] ?? null),
            vatPeriods: $vatPeriods,
            headquartersAddress: $headquartersAddress,
            fiscalDomicileAddress: $fiscalDomicileAddress,
            rtvaiDetails: $rtvaiDetails,
            splitVatDetails: $splitVatDetails,
            inactiveStatusDetails: $inactiveStatusDetails,
        );
    }

    /**
     * Get the VAT number with RO prefix.
     */
    public function getVatNumber(): string
    {
        return 'RO'.$this->cui;
    }

    /**
     * Check if the company is active (not inactive and not deregistered).
     */
    public function isActive(): bool
    {
        return ! $this->isInactive && ! $this->isDeregistered;
    }

    /**
     * Whether the trade-registry status is a confirmed "INREGISTRAT".
     * Unknown status returns false — inspect registrationStatusRaw for details.
     */
    public function isRegistered(): bool
    {
        return $this->registrationStatus === RegistrationStatus::Registered;
    }

    /**
     * Get the primary address (headquarters or fiscal domicile).
     */
    public function getPrimaryAddress(): ?AddressData
    {
        return $this->headquartersAddress ?? $this->fiscalDomicileAddress;
    }

    /**
     * A nested ANAF object, or [] when it is missing or not an object.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function section(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * Extract the date from a stare_inregistrare value ("... din data dd.mm.yyyy" or "yyyy-mm-dd", any letter case).
     */
    private static function parseStatusDate(?string $status): ?Carbon
    {
        return $status !== null && preg_match('/din data\s+(\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2})/ui', $status, $m) === 1 ? AnafValue::date($m[1]) : null;
    }

    /**
     * The period with the most recent start date (no ordering assumption).
     *
     * @param  list<VatPeriodData>  $periods
     */
    private static function latestVatPeriod(array $periods): ?VatPeriodData
    {
        $latest = null;
        foreach ($periods as $period) {
            if ($latest === null
                || ($period->startDate?->getTimestamp() ?? PHP_INT_MIN) > ($latest->startDate?->getTimestamp() ?? PHP_INT_MIN)) {
                $latest = $period;
            }
        }

        return $latest;
    }
}
