<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Facades;

use Beecoded\EFactura\Contracts\AnafDetailsClientInterface;
use Beecoded\EFactura\Data\Company\CompanyLookupResultData;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for ANAF company details lookup.
 *
 * This is a public API that doesn't require authentication.
 *
 * @method static CompanyLookupResultData getCompanyData(string $vatCode) Get company data for a single VAT code
 * @method static CompanyLookupResultData batchGetCompanyData(array $vatCodes) Get company data for multiple VAT codes
 * @method static bool isValidVatCode(string $vatCode) Check if VAT code format is valid
 *
 * @see \Beecoded\EFactura\Services\ApiClients\AnafDetailsClient
 */
final class AnafDetails extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AnafDetailsClientInterface::class;
    }
}
