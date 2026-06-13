<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Data\Company\CompanyData;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\DateHelper;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Client for ANAF company details lookup API.
 *
 * This is a public API that doesn't require authentication.
 * It provides company information including VAT status, registration details,
 * and addresses based on CUI/CIF lookup.
 *
 * @see https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva
 */
class AnafDetailsClient extends BaseApiClient implements AnafDetailsClientInterface
{
    /**
     * Maximum number of CUIs that can be queried in a single batch request.
     * ANAF v9 documents a hard limit of 100 CUIs per request (1 request/second).
     */
    private const MAX_BATCH_SIZE = 100;

    private readonly RateLimiter $rateLimiter;

    public function __construct()
    {
        parent::__construct();
        $this->rateLimiter = app(RateLimiter::class);
    }

    /**
     * Get the base URL for the company lookup API.
     */
    public static function getBaseUrl(): string
    {
        return config('efactura-sdk.endpoints.company_lookup', 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva');
    }

    /**
     * Get the timeout duration for API requests.
     */
    public static function getTimeoutDuration(): float|int
    {
        return config('efactura-sdk.http.timeout', 30);
    }

    /**
     * Get the logger instance.
     */
    public static function getLogger(): LoggerInterface
    {
        return Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'));
    }

    /**
     * Get the default headers for API requests.
     *
     * @return array<string, string>
     */
    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When the 1 req/sec lookup limit is exceeded
     */
    public function getCompanyData(string $vatCode): CompanyLookupResultData
    {
        return $this->batchGetCompanyData([$vatCode]);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When the 1 req/sec lookup limit is exceeded
     */
    public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData
    {
        if (empty($vatCodes)) {
            return CompanyLookupResultData::failure('No VAT codes provided.');
        }

        // Validate and prepare the request payload
        $requestDate = DateHelper::getCurrentDateForAnaf();
        $validatedPayload = [];
        $invalidCodes = [];

        foreach ($vatCodes as $vatCode) {
            if (empty($vatCode) || strlen(trim($vatCode)) < 2) {
                $invalidCodes[] = $vatCode;

                continue;
            }

            $cuiNumber = $this->extractCuiNumber($vatCode);
            if ($cuiNumber === null) {
                $invalidCodes[] = $vatCode;

                continue;
            }

            $validatedPayload[] = [
                'cui' => $cuiNumber,
                'data' => $requestDate,
            ];
        }

        // Check if we have any valid CUIs to query
        if (empty($validatedPayload)) {
            return CompanyLookupResultData::failure(
                'All provided VAT codes are invalid: '.implode(', ', $invalidCodes),
                $invalidCodes
            );
        }

        // Check batch size limit
        if (count($validatedPayload) > self::MAX_BATCH_SIZE) {
            return CompanyLookupResultData::failure(
                sprintf(
                    'Batch size exceeds maximum allowed (%d). Please split your request.',
                    self::MAX_BATCH_SIZE
                )
            );
        }

        $this->logger->info(sprintf(
            'ANAF Details: Calling ANAF API for %d CUIs, Date: %s',
            count($validatedPayload),
            $requestDate
        ));

        // Enforce ANAF's 1 req/sec limit. Outside the try/catch below so a breach
        // propagates as RateLimitExceededException (consistent with EFacturaClient),
        // rather than being swallowed into a failure() result.
        $this->rateLimiter->checkCompanyLookup();

        try {
            // Make the API call - the endpoint URL is the base URL itself
            // Use callRaw because the ANAF API expects a JSON array body, not key-value pairs
            $jsonBody = json_encode($validatedPayload);
            if ($jsonBody === false) {
                return CompanyLookupResultData::failure(
                    'Failed to encode request payload.',
                    $invalidCodes
                );
            }

            $response = $this->callRaw('', 'post', $jsonBody, 'application/json');
            $data = $response->json();

            // Use strict null check to distinguish between:
            // - null: JSON parsing failed (invalid response)
            // - []: Valid but unexpected empty response (handled by transformResponse)
            if ($data === null) {
                return CompanyLookupResultData::failure(
                    'Invalid or malformed JSON response from ANAF API.',
                    $invalidCodes
                );
            }

            $this->logger->info('ANAF API successful response for batch request');

            return $this->transformResponse($data, $invalidCodes);
        } catch (ApiException $e) {
            // ANAF returns HTTP 404 with a {found, notFound} body when NONE of the
            // queried CUIs exist. That is a documented "not found" response, not an
            // error — parse the preserved body and surface the notFound CUIs.
            if ($e->statusCode === 404 && $e->details !== null) {
                $decoded = json_decode($e->details, true);
                if (is_array($decoded)
                    && (isset($decoded['found']) || isset($decoded['notFound']) || isset($decoded['notfound']))) {
                    $this->logger->info('ANAF API 404 with not-found body for batch request');

                    return $this->transformResponse($decoded, $invalidCodes);
                }
            }

            $this->logger->error(sprintf(
                'ANAF Details API error: %s (status: %d)',
                $e->getMessage(),
                $e->statusCode
            ));

            // Handle specific error cases
            if (str_contains(strtolower($e->getMessage()), 'network') ||
                str_contains(strtolower($e->getMessage()), 'connection')) {
                return CompanyLookupResultData::failure(
                    'Network error: Could not connect to ANAF service.',
                    $invalidCodes
                );
            }

            return CompanyLookupResultData::failure(
                'An error occurred while contacting the ANAF service: '.$e->getMessage(),
                $invalidCodes
            );
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in ANAF Details lookup: '.$e->getMessage());

            return CompanyLookupResultData::failure(
                'An unexpected error occurred while contacting the ANAF service.',
                $invalidCodes
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isValidVatCode(string $vatCode): bool
    {
        return VatNumberValidator::isValid($vatCode);
    }

    /**
     * Extract the numeric CUI from a VAT code string.
     *
     * Handles both formats:
     * - With RO prefix: "RO12345678"
     * - Without prefix: "12345678"
     *
     * @param  string  $vatCode  The VAT code to extract CUI from
     * @return int|null The numeric CUI or null if invalid
     */
    private function extractCuiNumber(string $vatCode): ?int
    {
        $vatCode = trim($vatCode);

        if ($vatCode === '') {
            return null;
        }

        // Use the validator to strip the RO prefix if present
        $cuiString = VatNumberValidator::stripPrefix($vatCode);

        // Validate the remaining string is numeric
        if (! ctype_digit($cuiString)) {
            return null;
        }

        // Special case: ANAF allows all-zeros CNP (0000000000000) as valid identifier
        // This is used for anonymous/placeholder entries in e-Factura
        if ($cuiString === '0000000000000') {
            return 0;
        }

        $cuiNumber = (int) $cuiString;

        // CUI must be positive (except for the ANAF zero CNP handled above)
        if ($cuiNumber <= 0) {
            return null;
        }

        return $cuiNumber;
    }

    /**
     * Transform the ANAF API response into a CompanyLookupResultData.
     *
     * @param  array<string, mixed>  $response  The raw API response
     * @param  array<string>  $invalidCodes  VAT codes that failed validation
     */
    private function transformResponse(array $response, array $invalidCodes): CompanyLookupResultData
    {
        $companies = [];
        $notFound = [];

        // Process found companies
        if (isset($response['found']) && is_array($response['found'])) {
            foreach ($response['found'] as $companyData) {
                try {
                    $companies[] = CompanyData::fromAnafResponse($companyData);
                } catch (\Exception $e) {
                    $this->logger->warning(sprintf(
                        'Failed to parse company data: %s',
                        $e->getMessage()
                    ));
                }
            }
        }

        // Process not found CUIs.
        // Live v9 returns "notFound" with plain integer entries; older shapes
        // used lowercase "notfound" with {cui: int} objects. Accept both.
        $notFoundRaw = $response['notFound'] ?? $response['notfound'] ?? null;
        if (is_array($notFoundRaw)) {
            foreach ($notFoundRaw as $item) {
                if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                    $notFound[] = (int) $item;
                } elseif (is_array($item) && isset($item['cui'])) {
                    $notFound[] = (int) $item['cui'];
                }
            }
        }

        // Handle case where nothing was found and nothing in notfound
        if (empty($companies) && empty($notFound) && empty($invalidCodes)) {
            // Check if response has unexpected structure
            if (! isset($response['found']) && ! isset($response['notFound']) && ! isset($response['notfound'])) {
                return CompanyLookupResultData::failure(
                    'Unexpected response structure from ANAF API: '.json_encode($response),
                    $invalidCodes
                );
            }
        }

        // notFound CUIs surface on a success result (via hasNotFound()); consumers
        // distinguish outcomes by hasCompanies()/hasNotFound(), not the success flag.
        return CompanyLookupResultData::success($companies, $notFound, $invalidCodes);
    }

    /**
     * Override to use empty route since base URL is the full endpoint.
     */
    public function getRequestUrl(string $route): string
    {
        $baseUrl = rtrim(static::getBaseUrl(), '/');

        if ($route === '') {
            return $baseUrl;
        }

        return $baseUrl.'/'.ltrim($route, '/');
    }
}
