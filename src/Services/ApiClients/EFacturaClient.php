<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Contracts\EFacturaClientInterface;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Data\Response\DownloadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\ListMessagesResponseData;
use BeeCoded\EFacturaSdk\Data\Response\PaginatedMessagesResponseData;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Data\Response\UploadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\ValidationResultData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\XmlParser;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * ANAF e-Factura API client.
 *
 * Stateless client for interacting with the ANAF e-Factura system.
 * Tokens are passed in at construction time and auto-refreshed when expired.
 *
 * Usage:
 * ```php
 * $client = new EFacturaClient(
 *     vatNumber: '12345678',
 *     accessToken: $tokens->accessToken,
 *     refreshToken: $tokens->refreshToken,
 *     expiresAt: $tokens->expiresAt,
 * );
 *
 * // Or use the factory method
 * $client = EFacturaClient::fromTokens('12345678', $tokens);
 *
 * $result = $client->uploadDocument($xml);
 *
 * // Check if tokens were refreshed and need to be persisted
 * if ($client->wasTokenRefreshed()) {
 *     $newTokens = $client->getTokens();
 *     // Persist $newTokens to database
 * }
 * ```
 */
class EFacturaClient extends BaseApiClient implements EFacturaClientInterface
{
    /**
     * Buffer time in seconds before token expiration to trigger refresh.
     */
    private const int TOKEN_EXPIRY_BUFFER_SECONDS = 30;

    /**
     * Lock timeout in seconds for token refresh.
     */
    private const int TOKEN_REFRESH_LOCK_TIMEOUT = 10;

    /**
     * Maximum time to wait for lock acquisition in seconds.
     */
    private const int TOKEN_REFRESH_LOCK_WAIT = 15;

    /**
     * Maximum days for message listing.
     */
    private const int MAX_DAYS_MESSAGES = 60;

    /**
     * Minimum days for message listing.
     */
    private const int MIN_DAYS_MESSAGES = 1;

    /**
     * Current access token (may be refreshed).
     */
    private string $accessToken;

    /**
     * Current refresh token (may be updated after refresh).
     */
    private string $refreshToken;

    /**
     * Token expiration time (may be updated after refresh).
     */
    private ?Carbon $expiresAt;

    /**
     * Whether the token was refreshed during operations.
     */
    private bool $tokenRefreshed = false;

    /**
     * The authenticator for token refresh.
     */
    private readonly AnafAuthenticatorInterface $authenticator;

    /**
     * The rate limiter for API call throttling.
     */
    private readonly RateLimiter $rateLimiter;

    /**
     * Create a new EFacturaClient instance.
     *
     * @param  string  $vatNumber  The VAT number (CIF) for API operations
     * @param  string  $accessToken  The OAuth access token
     * @param  string  $refreshToken  The OAuth refresh token for auto-refresh
     * @param  Carbon|null  $expiresAt  Token expiration time
     */
    public function __construct(
        private readonly string $vatNumber,
        string $accessToken,
        string $refreshToken,
        ?Carbon $expiresAt = null,
    ) {
        parent::__construct();

        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt;
        $this->authenticator = app(AnafAuthenticatorInterface::class);
        $this->rateLimiter = app(RateLimiter::class);
    }

    /**
     * Create client from OAuthTokensData.
     *
     * @param  string  $vatNumber  The VAT number (CIF) for API operations
     * @param  OAuthTokensData  $tokens  The OAuth tokens
     */
    public static function fromTokens(
        string $vatNumber,
        OAuthTokensData $tokens,
    ): self {
        return new self(
            vatNumber: $vatNumber,
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function getBaseUrl(): string
    {
        return config('efactura-sdk.sandbox', true)
            ? config('efactura-sdk.endpoints.api.test')
            : config('efactura-sdk.endpoints.api.production');
    }

    /**
     * {@inheritdoc}
     */
    public static function getTimeoutDuration(): float|int
    {
        return config('efactura-sdk.http.timeout', 30);
    }

    /**
     * {@inheritdoc}
     */
    public static function getLogger(): LoggerInterface
    {
        return Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'));
    }

    /**
     * {@inheritdoc}
     */
    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/xml',
            'Accept' => 'application/json',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getRetryDelay(): int
    {
        return (int) config('efactura-sdk.http.retry_delay', 5);
    }

    /**
     * {@inheritdoc}
     */
    protected function getMaxTryCount(): int
    {
        return (int) config('efactura-sdk.http.retry_times', 3);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function uploadDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
    {
        $this->rateLimiter->checkGlobal();

        // Check RASP-specific rate limit when uploading RASP documents
        $standard = $options?->getStandard() ?? StandardType::UBL;
        if ($standard === StandardType::RASP) {
            $this->rateLimiter->checkRaspUpload($this->vatNumber);
        }

        $this->validateXmlContent($xml);

        $queryParams = $this->buildUploadQueryParams($options);
        $route = '/upload?'.http_build_query($queryParams);

        $response = $this->authenticatedXmlRequest($route, 'POST', $xml);

        return $this->parseUploadResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function uploadB2CDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
    {
        $this->rateLimiter->checkGlobal();

        // Check RASP-specific rate limit when uploading RASP documents
        $standard = $options?->getStandard() ?? StandardType::UBL;
        if ($standard === StandardType::RASP) {
            $this->rateLimiter->checkRaspUpload($this->vatNumber);
        }

        $this->validateXmlContent($xml);

        $queryParams = $this->buildUploadQueryParams($options);
        $route = '/uploadb2c?'.http_build_query($queryParams);

        $response = $this->authenticatedXmlRequest($route, 'POST', $xml);

        return $this->parseUploadResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getStatusMessage(string $uploadId): StatusResponseData
    {
        $this->rateLimiter->checkGlobal();
        $this->rateLimiter->checkStatusQuery($uploadId);
        $this->validateUploadId($uploadId);

        $queryParams = [
            'id_incarcare' => $uploadId,
        ];

        $route = '/stareMesaj?'.http_build_query($queryParams);

        $response = $this->authenticatedRequest($route, 'GET');

        return $this->parseStatusResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function downloadDocument(string $downloadId): DownloadResponseData
    {
        $this->rateLimiter->checkGlobal();
        $this->rateLimiter->checkDownload($downloadId);
        $this->validateDownloadId($downloadId);

        $queryParams = [
            'id' => $downloadId,
        ];

        $route = '/descarcare?'.http_build_query($queryParams);

        $response = $this->authenticatedRequest($route, 'GET', expectBinary: true);

        return DownloadResponseData::fromHttpResponse(
            $response->body(),
            $response->headers()
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getMessages(ListMessagesParamsData $params): ListMessagesResponseData
    {
        $this->rateLimiter->checkGlobal();
        $this->rateLimiter->checkSimpleList($params->cif);
        $this->validateDays($params->days);

        $queryParams = [
            'cif' => $params->cif,
            'zile' => $params->days,
        ];

        if ($params->filter !== null) {
            $queryParams['filtru'] = $params->filter->value;
        }

        $route = '/listaMesajeFactura?'.http_build_query($queryParams);

        $response = $this->authenticatedRequest($route, 'GET');

        return ListMessagesResponseData::fromAnafResponse($response->json() ?? []);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getMessagesPaginated(PaginatedMessagesParamsData $params): PaginatedMessagesResponseData
    {
        $this->rateLimiter->checkGlobal();
        $this->rateLimiter->checkPaginatedList($params->cif);
        $this->validateTimeRange($params->startTime, $params->endTime);
        $this->validatePage($params->page);

        $queryParams = [
            'cif' => $params->cif,
            'startTime' => $params->startTime,
            'endTime' => $params->endTime,
            'pagina' => $params->page,
        ];

        if ($params->filter !== null) {
            $queryParams['filtru'] = $params->filter->value;
        }

        $route = '/listaMesajePaginatieFactura?'.http_build_query($queryParams);

        $response = $this->authenticatedRequest($route, 'GET');

        return PaginatedMessagesResponseData::fromAnafResponse($response->json() ?? []);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function validateXml(string $xml, DocumentStandardType $standard): ValidationResultData
    {
        $this->rateLimiter->checkGlobal();
        $this->validateXmlContent($xml);

        $validateUrl = config('efactura-sdk.endpoints.services.validate');
        if (empty($validateUrl)) {
            throw new ValidationException('Missing configuration: efactura.endpoints.services.validate');
        }

        $url = $validateUrl.'/'.$standard->value;

        $response = $this->authenticatedXmlRequestToUrl($url, 'POST', $xml);

        return $this->parseValidationResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function verifySignature(string $xml): ValidationResultData
    {
        $this->rateLimiter->checkGlobal();
        $this->validateXmlContent($xml);

        $verifyUrl = config('efactura-sdk.endpoints.services.verify_signature');
        if (empty($verifyUrl)) {
            throw new ValidationException('Missing configuration: efactura.endpoints.services.verify_signature');
        }

        $response = $this->authenticatedXmlRequestToUrl($verifyUrl, 'POST', $xml);

        return $this->parseValidationResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function convertXmlToPdf(string $xml, DocumentStandardType $standard, bool $validate = false): string
    {
        $this->rateLimiter->checkGlobal();
        $this->validateXmlContent($xml);

        $transformUrl = config('efactura-sdk.endpoints.services.transform');
        if (empty($transformUrl)) {
            throw new ValidationException('Missing configuration: efactura.endpoints.services.transform');
        }
        $endpoint = $validate ? $standard->value : "{$standard->value}/DA";

        $url = $transformUrl.'/'.$endpoint;

        $response = $this->authenticatedXmlRequestToUrl($url, 'POST', $xml, expectBinary: true);

        // Check if response is actually an error (JSON response instead of PDF)
        $contentType = (string) $response->header('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $errorData = $response->json();
            throw new ApiException(
                $errorData['message'] ?? $errorData['eroare'] ?? 'PDF conversion failed',
                $response->status(),
                json_encode($errorData)
            );
        }

        return $response->body();
    }

    /**
     * {@inheritdoc}
     */
    public function wasTokenRefreshed(): bool
    {
        return $this->tokenRefreshed;
    }

    /**
     * {@inheritdoc}
     */
    public function getTokens(): OAuthTokensData
    {
        return new OAuthTokensData(
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            expiresAt: $this->expiresAt,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getVatNumber(): string
    {
        return $this->vatNumber;
    }

    /**
     * Get the rate limiter instance for quota checking.
     */
    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * Uses distributed locking to prevent race conditions when multiple
     * concurrent requests detect an expired token simultaneously.
     * ANAF uses rotating refresh tokens - once used, old tokens are invalidated.
     *
     * The lock ensures only one process refreshes the token at a time. Other processes
     * waiting on the lock will still use their existing tokens after lock timeout,
     * but the lock serializes refresh attempts to prevent multiple concurrent refreshes.
     *
     * @throws AuthenticationException When token refresh fails
     */
    private function getValidAccessToken(): string
    {
        if ($this->isTokenValid()) {
            return $this->accessToken;
        }

        // Use distributed lock to prevent concurrent token refresh attempts
        // This is critical because ANAF invalidates refresh tokens after use
        $lockKey = "efactura:token_refresh:{$this->vatNumber}";
        $lock = Cache::lock($lockKey, self::TOKEN_REFRESH_LOCK_TIMEOUT);

        try {
            // Wait for lock with timeout - block() throws LockTimeoutException on timeout
            $lock->block(self::TOKEN_REFRESH_LOCK_WAIT);
            $this->refreshTokens();

            return $this->accessToken;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Could not acquire lock within timeout
            // This process will use the existing token (may work if another process refreshed)
            $this->logger->warning('Could not acquire token refresh lock, using existing token', [
                'vatNumber' => $this->vatNumber,
            ]);

            return $this->accessToken;
        } finally {
            // Safe to call - Laravel's lock tracks ownership and won't release others' locks
            $lock->release();
        }
    }

    /**
     * Check if the current token is valid (not expired with buffer).
     */
    private function isTokenValid(): bool
    {
        if ($this->expiresAt === null) {
            // No expiry info, assume valid
            return true;
        }

        // Add buffer to catch tokens about to expire
        return $this->expiresAt->copy()->subSeconds(self::TOKEN_EXPIRY_BUFFER_SECONDS)->isFuture();
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @throws AuthenticationException When refresh fails
     */
    private function refreshTokens(): void
    {
        $this->logger->info('Refreshing ANAF access token', [
            'vatNumber' => $this->vatNumber,
            'expiresAt' => $this->expiresAt?->toIso8601String(),
        ]);

        try {
            $newTokens = $this->authenticator->refreshAccessToken($this->refreshToken);

            $this->accessToken = $newTokens->accessToken;
            $this->refreshToken = $newTokens->refreshToken;
            $this->expiresAt = $newTokens->expiresAt;
            $this->tokenRefreshed = true;

            $this->logger->info('ANAF access token refreshed successfully', [
                'vatNumber' => $this->vatNumber,
                'newExpiresAt' => $this->expiresAt?->toIso8601String(),
            ]);
        } catch (AuthenticationException $e) {
            $this->logger->error('Failed to refresh ANAF access token', [
                'vatNumber' => $this->vatNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Make an authenticated request to the API.
     *
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    private function authenticatedRequest(
        string $route,
        string $method,
        array $data = [],
        bool $expectBinary = false
    ): Response {
        $token = $this->getValidAccessToken();

        $headers = $this->getHeaders();
        $headers['Authorization'] = 'Bearer '.$token;

        if ($expectBinary) {
            $headers['Accept'] = 'application/octet-stream, application/zip, application/json';
        }

        try {
            $response = $this->call($route, $method, $data, $headers);
            $this->handleAuthenticationError($response);

            return $response;
        } catch (ApiException $e) {
            if ($e->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed. Token may be invalid or revoked.',
                    401,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Make an authenticated XML request to the API.
     *
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    private function authenticatedXmlRequest(
        string $route,
        string $method,
        string $xmlBody,
        bool $expectBinary = false
    ): Response {
        $token = $this->getValidAccessToken();

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'text/plain',
            'Accept' => $expectBinary ? 'application/octet-stream, application/zip, application/json' : 'application/json',
        ];

        try {
            $response = $this->callRaw($route, $method, $xmlBody, 'text/plain', $headers);
            $this->handleAuthenticationError($response);

            return $response;
        } catch (ApiException $e) {
            if ($e->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed. Token may be invalid or revoked.',
                    401,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Make an authenticated XML request to a full URL (for validation/transform services).
     *
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    private function authenticatedXmlRequestToUrl(
        string $fullUrl,
        string $method,
        string $xmlBody,
        bool $expectBinary = false
    ): Response {
        $token = $this->getValidAccessToken();

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'text/plain',
            'Accept' => $expectBinary ? 'application/pdf, application/json' : 'application/json',
        ];

        try {
            // Use full URL directly instead of base URL + route
            $response = $this->requestToFullUrl($fullUrl, $method, $xmlBody, 'text/plain', $headers);
            $this->handleAuthenticationError($response);

            return $response;
        } catch (ApiException $e) {
            if ($e->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed. Token may be invalid or revoked.',
                    401,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Make a raw request to a full URL (bypassing base URL).
     *
     * @param  array<string, string>  $headers
     *
     * @throws ApiException
     */
    private function requestToFullUrl(
        string $fullUrl,
        string $method,
        string $body,
        string $contentType,
        array $headers,
        int $tryCount = 0
    ): Response {
        $tryCount++;
        $context = fn () => [
            'url' => $fullUrl,
            'bodyLength' => strlen($body),
            'contentType' => $contentType,
            'duration' => $this->lastRequestDurationMilliseconds,
            'tryCount' => $tryCount,
        ];

        try {
            $startTime = Carbon::now();
            $request = \Illuminate\Support\Facades\Http::timeout(static::getTimeoutDuration());
            $request->withHeaders($headers);

            $response = $request->withBody($body, $contentType)->$method($fullUrl);

            $endTime = Carbon::now();
            $timeDiff = $startTime->diffInMilliseconds($endTime);
            $this->lastRequestDurationMilliseconds = $timeDiff;

            $this->logger->debug(
                sprintf(
                    '%s %s. Response %s. Duration: %d ms.',
                    strtoupper($method),
                    $fullUrl,
                    $response->status(),
                    $timeDiff
                ),
                $context()
            );
        } catch (\Exception $exception) {
            $this->logger->error(
                "Exception before response was received: {$exception->getMessage()}.",
                $context()
            );

            if ($tryCount < $this->getMaxTryCount()) {
                sleep($this->getRetryDelay());

                return $this->requestToFullUrl($fullUrl, $method, $body, $contentType, $headers, $tryCount);
            }

            throw new ApiException(
                $exception->getMessage(),
                500,
                null,
                $exception
            );
        }

        if (! $response->successful()) {
            if ($tryCount < $this->getMaxTryCount() && $this->isRetryable($response)) {
                sleep($this->getRetryDelay());

                return $this->requestToFullUrl($fullUrl, $method, $body, $contentType, $headers, $tryCount);
            }

            throw new ApiException(
                $response->json('message')
                    ?? $response->json('eroare')
                    ?? $response->json('error')
                    ?? 'No error message in API response',
                $response->status() >= 500 ? 502 : $response->status(),
                $response->body()
            );
        }

        return $response;
    }

    /**
     * Handle 401 authentication errors.
     *
     * @throws AuthenticationException When response indicates authentication failure
     */
    private function handleAuthenticationError(Response $response): void
    {
        if ($response->status() === 401) {
            throw new AuthenticationException(
                'Authentication failed. Token may be invalid or revoked.',
                401
            );
        }
    }

    /**
     * Build query parameters for upload requests.
     *
     * @return array<string, string|int>
     */
    private function buildUploadQueryParams(?UploadOptionsData $options): array
    {
        $params = [
            'standard' => ($options?->getStandard() ?? StandardType::UBL)->value,
            'cif' => $this->vatNumber,
        ];

        if ($options !== null) {
            if ($options->extern) {
                $params['extern'] = 'DA';
            }

            if ($options->selfBilled) {
                $params['autofactura'] = 'DA';
            }

            if ($options->executare) {
                $params['executare'] = 'DA';
            }
        }

        return $params;
    }

    /**
     * Parse upload response from ANAF.
     *
     * @throws ApiException When response cannot be parsed or indicates error
     */
    private function parseUploadResponse(Response $response): UploadResponseData
    {
        $body = $response->body();

        // Try to parse as XML first (ANAF returns XML for upload responses)
        if (str_starts_with(trim($body), '<?xml') || str_starts_with(trim($body), '<')) {
            try {
                $parsed = XmlParser::parseUploadResponse($body);

                return UploadResponseData::fromAnafResponse([
                    'ExecutionStatus' => $parsed['executionStatus'],
                    'index_incarcare' => $parsed['indexIncarcare'],
                    'dateResponse' => $parsed['dateResponse'],
                    'Errors' => $parsed['errors'],
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to parse XML upload response, trying JSON', [
                    'error' => $e->getMessage(),
                    'body' => substr($body, 0, 500),
                ]);
            }
        }

        // Fall back to JSON parsing
        $json = $response->json();
        if ($json !== null) {
            return UploadResponseData::fromAnafResponse($json);
        }

        throw new ApiException(
            'Unable to parse upload response',
            $response->status(),
            $body
        );
    }

    /**
     * Parse status response from ANAF.
     *
     * @throws ApiException When response cannot be parsed
     */
    private function parseStatusResponse(Response $response): StatusResponseData
    {
        $body = $response->body();

        // Try to parse as XML first
        if (str_starts_with(trim($body), '<?xml') || str_starts_with(trim($body), '<')) {
            try {
                $parsed = XmlParser::parseStatusResponse($body);

                return StatusResponseData::fromAnafResponse([
                    'stare' => $parsed['stare'],
                    'id_descarcare' => $parsed['idDescarcare'],
                    'Errors' => $parsed['errors'],
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to parse XML status response, trying JSON', [
                    'error' => $e->getMessage(),
                    'body' => substr($body, 0, 500),
                ]);
            }
        }

        // Fall back to JSON parsing
        $json = $response->json();
        if ($json !== null) {
            return StatusResponseData::fromAnafResponse($json);
        }

        throw new ApiException(
            'Unable to parse status response',
            $response->status(),
            $body
        );
    }

    /**
     * Parse validation response from ANAF.
     *
     * @throws ApiException When response cannot be parsed
     */
    private function parseValidationResponse(Response $response): ValidationResultData
    {
        $json = $response->json();

        if ($json === null) {
            throw new ApiException(
                'Unable to parse validation response',
                $response->status(),
                $response->body()
            );
        }

        // Handle ANAF validation response format
        // Success: {"valid": true} or {"stare": "ok"}
        // Error: {"valid": false, "mesaj": "..."} or {"eroare": "..."}
        $isValid = ($json['valid'] ?? false)
            || ($json['stare'] ?? '') === 'ok'
            || (isset($json['mesaj']) && stripos($json['mesaj'], 'valid') !== false);

        return new ValidationResultData(
            valid: $isValid,
            details: $json['mesaj'] ?? $json['detalii'] ?? $json['message'] ?? null,
            info: $json['info'] ?? null,
            errors: $json['Errors'] ?? (isset($json['eroare']) ? [$json['eroare']] : null),
        );
    }

    /**
     * Validate XML content is not empty.
     *
     * @throws ValidationException When XML is empty or whitespace only
     */
    private function validateXmlContent(string $xml): void
    {
        if (trim($xml) === '') {
            throw new ValidationException('XML content cannot be empty');
        }
    }

    /**
     * Validate upload ID format.
     *
     * @throws ValidationException When upload ID is invalid
     */
    private function validateUploadId(string $uploadId): void
    {
        if (trim($uploadId) === '') {
            throw new ValidationException('Upload ID cannot be empty');
        }

        if (! ctype_digit($uploadId)) {
            throw new ValidationException('Upload ID must be a numeric string');
        }
    }

    /**
     * Validate download ID format.
     *
     * @throws ValidationException When download ID is invalid
     */
    private function validateDownloadId(string $downloadId): void
    {
        if (trim($downloadId) === '') {
            throw new ValidationException('Download ID cannot be empty');
        }

        if (! ctype_digit($downloadId)) {
            throw new ValidationException('Download ID must be a numeric string');
        }
    }

    /**
     * Validate days parameter for message listing.
     *
     * @throws ValidationException When days is out of range
     */
    private function validateDays(int $days): void
    {
        if ($days < self::MIN_DAYS_MESSAGES || $days > self::MAX_DAYS_MESSAGES) {
            throw new ValidationException(
                sprintf('Days must be between %d and %d', self::MIN_DAYS_MESSAGES, self::MAX_DAYS_MESSAGES)
            );
        }
    }

    /**
     * Validate time range for paginated messages.
     *
     * @throws ValidationException When time range is invalid
     */
    private function validateTimeRange(int $startTime, int $endTime): void
    {
        if ($startTime <= 0) {
            throw new ValidationException('Start time must be a positive timestamp in milliseconds');
        }

        if ($endTime <= 0) {
            throw new ValidationException('End time must be a positive timestamp in milliseconds');
        }

        if ($startTime >= $endTime) {
            throw new ValidationException('Start time must be before end time');
        }

        // Validate time range is not too large (max 60 days)
        $maxRangeMs = self::MAX_DAYS_MESSAGES * 24 * 60 * 60 * 1000;
        if (($endTime - $startTime) > $maxRangeMs) {
            throw new ValidationException(
                sprintf('Time range cannot exceed %d days', self::MAX_DAYS_MESSAGES)
            );
        }
    }

    /**
     * Validate page number.
     *
     * @throws ValidationException When page is invalid
     */
    private function validatePage(int $page): void
    {
        if ($page < 1) {
            throw new ValidationException('Page number must be at least 1');
        }
    }
}
