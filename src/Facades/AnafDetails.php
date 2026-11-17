<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Facades;

use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Services\ApiClients\AnafDetailsClient;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for ANAF company details lookup.
 *
 * This is a public API that doesn't require authentication.
 *
 * @method static CompanyLookupResultData getCompanyData(string $vatCode) Get company data for a single VAT code
 * @method static CompanyLookupResultData batchGetCompanyData(array<int|string> $vatCodes) Get company data for multiple VAT codes
 * @method static bool isValidVatCode(string $vatCode) Check a VAT code's format and checksum (no ANAF call)
 *
 * @see AnafDetailsClient
 */
final class AnafDetails extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AnafDetailsClientInterface::class;
    }
}
