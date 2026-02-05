<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Company;

use Spatie\LaravelData\Data;

/**
 * Result wrapper for company lookup operations.
 *
 * Contains the lookup results including found companies,
 * not found CUIs, and any error information.
 */
class CompanyLookupResultData extends Data
{
    /**
     * @param  bool  $success  Whether the lookup was successful
     * @param  array<CompanyData>  $companies  Array of found companies
     * @param  array<int>  $notFound  Array of CUIs that were not found
     * @param  array<string>  $invalidCodes  Array of VAT codes that failed validation
     * @param  string|null  $error  Error message if lookup failed
     */
    public function __construct(
        public bool $success,
        public array $companies = [],
        public array $notFound = [],
        public array $invalidCodes = [],
        public ?string $error = null,
    ) {}

    /**
     * Create a successful result with company data.
     *
     * @param  array<CompanyData>  $companies
     * @param  array<int>  $notFound
     * @param  array<string>  $invalidCodes
     */
    public static function success(
        array $companies,
        array $notFound = [],
        array $invalidCodes = []
    ): self {
        return new self(
            success: true,
            companies: $companies,
            notFound: $notFound,
            invalidCodes: $invalidCodes,
        );
    }

    /**
     * Create a failed result with error message.
     *
     * @param  array<string>  $invalidCodes
     */
    public static function failure(string $error, array $invalidCodes = []): self
    {
        return new self(
            success: false,
            error: $error,
            invalidCodes: $invalidCodes,
        );
    }

    /**
     * Get the first company from results.
     */
    public function first(): ?CompanyData
    {
        return $this->companies[0] ?? null;
    }

    /**
     * Check if any companies were found.
     */
    public function hasCompanies(): bool
    {
        return count($this->companies) > 0;
    }

    /**
     * Check if any CUIs were not found.
     */
    public function hasNotFound(): bool
    {
        return count($this->notFound) > 0;
    }

    /**
     * Check if any VAT codes were invalid.
     */
    public function hasInvalidCodes(): bool
    {
        return count($this->invalidCodes) > 0;
    }

    /**
     * Get company by CUI.
     */
    public function getByCui(string $cui): ?CompanyData
    {
        // Remove RO prefix case-insensitively (proper prefix removal, not character set)
        if (str_starts_with(strtoupper($cui), 'RO')) {
            $cui = substr($cui, 2);
        }

        // Guard against empty CUI after prefix removal (e.g., input was just "RO")
        if ($cui === '') {
            return null;
        }

        foreach ($this->companies as $company) {
            if ($company->cui === $cui) {
                return $company;
            }
        }

        return null;
    }
}
