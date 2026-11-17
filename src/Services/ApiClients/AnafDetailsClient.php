<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use BeeCoded\EFacturaSdk\Data\Company\CompanyData;
use BeeCoded\EFacturaSdk\Data\Company\CompanyLookupResultData;
use BeeCoded\EFacturaSdk\Enums\CompanyLookupFailureKind;
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\AnafValue;
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

    /**
     * @param  bool  $strict  Fail closed unless ANAF's answer confirms the request: every requested
     *                        CUI answered exactly once (found or notFound, both lists, CUIs as
     *                        integers), today's date echoed, a non-empty name, a known registration
     *                        state, a boolean statusInactivi, dataRadiere present and valid dates.
     *                        A 404 counts only as not-found evidence. Anything else is a failure
     *                        with failureKind InvalidResponse, never a found company.
     * @param  int|null  $tries  HTTP attempts per lookup (default: efactura-sdk.http.retry_times)
     * @param  int|float|null  $timeoutSeconds  Request timeout (default: efactura-sdk.http.timeout)
     */
    public function __construct(
        private readonly bool $strict = false,
        private readonly ?int $tries = null,
        private readonly int|float|null $timeoutSeconds = null,
    ) {
        if (($tries !== null && $tries < 1) || ($timeoutSeconds !== null && $timeoutSeconds <= 0)) {
            throw new \InvalidArgumentException('A company lookup needs at least one try and a positive timeout.');
        }

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
        // env() yields a string; an empty or zero value would be Guzzle's "no timeout".
        $timeout = (float) config('efactura-sdk.http.timeout', 30);

        return $timeout > 0 ? $timeout : 30;
    }

    /**
     * {@inheritdoc}
     */
    protected function getMaxTryCount(): int
    {
        return $this->tries ?? parent::getMaxTryCount();
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequestTimeout(): float|int
    {
        return $this->timeoutSeconds ?? static::getTimeoutDuration();
    }

    /**
     * {@inheritdoc}
     *
     * The lookup cap is metered per HTTP request, so every retry must consume a
     * unit of its own. This bucket is less forgiving than the e-Factura ones: it
     * is 100% of ANAF's cap rather than the usual 50% margin (a margin is
     * unrepresentable as an integer over a 1-second window -- see RateLimiter),
     * and the window is two seconds. With retry_delay=0 an ANAF 5xx storm fires
     * every attempt inside that same window, so counting the retries as a single
     * lookup puts the SDK over a hard cap it believes it is respecting.
     *
     * Every retry is charged, sent or not: this bucket is ANAF's full cap with no margin, so it stays conservative.
     *
     * @throws RateLimitExceededException When the retry would exceed the 1 req/sec cap
     */
    protected function onRetryAttempt(bool $previousAttemptDelivered): void
    {
        $this->rateLimiter->checkCompanyLookup();
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
     * @param  array<mixed>  $vatCodes
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
            $cui = is_int($vatCode) || is_string($vatCode) ? VatNumberValidator::canonical($vatCode) : null;

            if ($cui === null) {
                $invalidCodes[] = is_int($vatCode) || is_string($vatCode) ? (string) $vatCode : get_debug_type($vatCode);

                continue;
            }

            // Each CUI once, deduped on its canonical form: ANAF answers per entry, and the
            // strict check needs every requested CUI answered exactly once. canonical() keeps
            // the length at 10 digits or a CNP shape, so the int cast is exact.
            if (! in_array((int) $cui, array_column($validatedPayload, 'cui'), true)) {
                $validatedPayload[] = [
                    'cui' => (int) $cui,
                    'data' => $requestDate,
                ];
            }
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

        try {
            // Enforce ANAF's 1 req/sec limit.
            $this->rateLimiter->checkCompanyLookup();

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
            $data = static::decodeJson($response);

            // Distinguish between:
            // - not an array: JSON parsing failed, or a bare scalar (invalid response)
            // - []: Valid but unexpected empty response (handled by transformResponse)
            if (! is_array($data)) {
                return CompanyLookupResultData::failure(
                    'Invalid or malformed JSON response from ANAF API.',
                    $invalidCodes,
                    CompanyLookupFailureKind::InvalidResponse
                );
            }

            $this->logger->info('ANAF API successful response for batch request');

            return $this->resultFrom($data, array_column($validatedPayload, 'cui'), $requestDate, $invalidCodes, false);
        } catch (RateLimitExceededException $e) {
            // The limiter runs inside this try (first attempt and retries alike): a refusal is
            // rethrown so it always surfaces as a rate limit; a failing store falls to the
            // catch-all as a Transport failure, nothing sent.
            throw $e;
        } catch (ApiException $e) {
            // ANAF's own overload refusal: a rate limit, not a lookup failure. ANAF sends no
            // usable Retry-After here, so 2 s is the SDK's estimate (one lookup window).
            if ($e->statusCode === 429) {
                throw new RateLimitExceededException(
                    'ANAF refused the company lookup (rate limit).',
                    retryAfterSeconds: 2,
                    previous: $e,
                    scope: RateLimitScope::CompanyLookup,
                    fromProvider: true,
                );
            }

            // ANAF returns HTTP 404 with a {found, notFound} body when NONE of the
            // queried CUIs exist. That is a documented "not found" response, not an
            // error — parse the preserved body and surface the notFound CUIs.
            if ($e->statusCode === 404 && $e->details !== null) {
                $decoded = AnafValue::json($e->details);
                if (is_array($decoded)
                    && (isset($decoded['found']) || isset($decoded['notFound']) || isset($decoded['notfound']))) {
                    $this->logger->info('ANAF API 404 with not-found body for batch request');

                    return $this->resultFrom($decoded, array_column($validatedPayload, 'cui'), $requestDate, $invalidCodes, true);
                }
            }

            $this->logger->error(sprintf(
                'ANAF Details API error: %s (status: %d)',
                $e->getMessage(),
                $e->statusCode
            ));

            return CompanyLookupResultData::failure(
                'An error occurred while contacting the ANAF service: '.$e->getMessage(),
                $invalidCodes,
                CompanyLookupFailureKind::Transport
            );
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in ANAF Details lookup: '.$e->getMessage());

            return CompanyLookupResultData::failure(
                'An unexpected error occurred while contacting the ANAF service.',
                $invalidCodes,
                CompanyLookupFailureKind::Transport
            );
        }
    }

    /**
     * Turn a decoded {found, notFound} answer into a result, checking the evidence first
     * in strict mode. A strict failure is returned, not thrown, so no catch-all can
     * relabel it as a transport failure.
     *
     * @param  array<mixed>  $body
     * @param  list<int>  $cuis  The CUIs sent, each once
     * @param  array<string>  $invalidCodes
     */
    private function resultFrom(array $body, array $cuis, string $date, array $invalidCodes, bool $notFoundStatus): CompanyLookupResultData
    {
        if (! $this->strict) {
            return $this->transformResponse($body, $invalidCodes);
        }

        $result = $this->confirmsRequest($body, $cuis, $date, $notFoundStatus)
            ? $this->transformResponse($body, $invalidCodes)
            : null;

        // Every confirmed company must also parse; a skipped entry would read as absent.
        if ($result === null || ! $result->success || count($result->companies) !== count($body['found'])) {
            $this->logger->warning('ANAF lookup answer does not confirm the requested companies', ['cuis' => $cuis]);

            return CompanyLookupResultData::failure(
                'ANAF response does not confirm the requested companies.',
                $invalidCodes,
                CompanyLookupFailureKind::InvalidResponse
            );
        }

        return $result;
    }

    /**
     * Whether an answer is evidence for exactly the request: see the $strict option.
     *
     * @param  array<mixed>  $body
     * @param  list<int>  $cuis
     */
    private function confirmsRequest(array $body, array $cuis, string $date, bool $notFoundStatus): bool
    {
        $found = $body['found'] ?? null;
        $answered = $body['notFound'] ?? null;

        if (array_key_exists('notfound', $body) || ! is_array($found) || ! array_is_list($found)
            || ! is_array($answered) || ! array_is_list($answered) || ($notFoundStatus && $found !== [])) {
            return false;
        }

        foreach ($found as $entry) {
            $general = is_array($entry) ? ($entry['date_generale'] ?? null) : null;
            $inactive = is_array($entry) ? ($entry['stare_inactiv'] ?? null) : null;

            if (! is_array($general) || ! is_array($inactive)
                || ($general['data'] ?? null) !== $date
                || ! is_string($general['denumire'] ?? null) || trim($general['denumire']) === ''
                || ! is_string($general['stare_inregistrare'] ?? null) || ! self::isKnownRegistrationState($general['stare_inregistrare'])
                || ! is_bool($inactive['statusInactivi'] ?? null) || ! array_key_exists('dataRadiere', $inactive)) {
                return false;
            }

            foreach (['dataInactivare', 'dataReactivare', 'dataPublicare', 'dataRadiere'] as $field) {
                $value = $inactive[$field] ?? null;

                if ($value !== null && $value !== '' && AnafValue::date($value) === null) {
                    return false;
                }
            }

            $answered[] = $general['cui'] ?? null;
        }

        foreach ($answered as $cui) {
            if (! is_int($cui)) {
                return false;
            }
        }

        sort($answered);
        sort($cuis);

        return $answered === $cuis;
    }

    /**
     * A registration state ANAF is known to send. Activity itself is decided from
     * statusInactivi, dataRadiere and the RADIERE/RADIAT prefix by the caller.
     */
    private static function isKnownRegistrationState(string $state): bool
    {
        if (preg_match('/\A(?:INREGISTRAT|ÎNREGISTRAT|RADIERE|RADIAT|TRANSFER\((?:SOSIRE|PLECARE)\))(?: din data (\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2}))?\z/ui', trim($state), $matches) !== 1) {
            return false;
        }

        return ! isset($matches[1]) || AnafValue::date($matches[1]) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function isValidVatCode(string $vatCode): bool
    {
        return VatNumberValidator::isValid($vatCode);
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
                if (! is_array($companyData)) {
                    $this->logger->warning('Skipping a found company entry that is not an object');

                    continue;
                }

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
                $cui = AnafValue::int(is_array($item) ? ($item['cui'] ?? null) : $item);
                if ($cui !== null) {
                    $notFound[] = $cui;
                }
            }
        }

        // Handle case where nothing was found and nothing in notfound
        if (empty($companies) && empty($notFound)) {
            // Check if response has unexpected structure
            if (! isset($response['found']) && ! isset($response['notFound']) && ! isset($response['notfound'])) {
                return CompanyLookupResultData::failure(
                    'Unexpected response structure from ANAF API: '.json_encode($response),
                    $invalidCodes,
                    CompanyLookupFailureKind::InvalidResponse
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
