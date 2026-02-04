<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Contracts;

use BeeCoded\EFactura\Data\Company\CompanyLookupResultData;

/**
 * Interface for ANAF company details lookup.
 *
 * This client interacts with ANAF's public company information API
 * to retrieve company details including VAT status, registration info,
 * and addresses.
 */
interface AnafDetailsClientInterface
{
    /**
     * Get company data for a single VAT code.
     *
     * @param  string  $vatCode  Romanian VAT code (CUI/CIF), with or without RO prefix
     * @return CompanyLookupResultData Result containing company data or error information
     */
    public function getCompanyData(string $vatCode): CompanyLookupResultData;

    /**
     * Get company data for multiple VAT codes in a single request.
     *
     * ANAF allows batch lookups for efficiency. Invalid VAT codes
     * in the batch will be reported in the notFound array.
     *
     * @param  array<string>  $vatCodes  Array of Romanian VAT codes (CUI/CIF)
     * @return CompanyLookupResultData Result containing found companies and not found codes
     */
    public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData;

    /**
     * Check if a VAT code has a valid format.
     *
     * This performs format validation only, not actual verification
     * against ANAF database.
     *
     * @param  string  $vatCode  The VAT code to validate
     * @return bool True if the format is valid
     */
    public function isValidVatCode(string $vatCode): bool;
}
