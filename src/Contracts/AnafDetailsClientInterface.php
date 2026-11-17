<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Contracts;

use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;

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
     *
     * @throws RateLimitExceededException When the SDK's lookup limit refuses the call, or ANAF answers 429 (fromProvider)
     */
    public function getCompanyData(string $vatCode): CompanyLookupResultData;

    /**
     * Get company data for multiple VAT codes in a single request.
     *
     * ANAF allows batch lookups for efficiency. Codes without a CUI or CNP shape
     * (VatNumberValidator::canonical(); the control digit is not checked) are reported
     * in invalidCodes and never sent; CUIs ANAF does not know are in notFound.
     *
     * @param  array<int|string>  $vatCodes  Array of Romanian VAT codes (CUI/CIF)
     * @return CompanyLookupResultData Result containing found companies and not found codes
     *
     * @throws RateLimitExceededException When the SDK's lookup limit refuses the call, or ANAF answers 429 (fromProvider)
     */
    public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData;

    /**
     * Check a VAT code's format and checksum (VatNumberValidator::isValid()), without calling ANAF.
     *
     * @param  string  $vatCode  The VAT code to validate
     * @return bool True if the format and checksum are valid
     */
    public function isValidVatCode(string $vatCode): bool;
}
