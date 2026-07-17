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
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
    private const int TOKEN_EXPIRY_BUFFER_SECONDS = 120;

    /**
     * Lock timeout in seconds for token refresh.
     */
    private const int TOKEN_REFRESH_LOCK_TIMEOUT = 10;

    /**
     * Default maximum time to wait for lock acquisition in seconds.
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
     * Whether a token refresh attempt has failed.
     *
     * Once set to true, subsequent API calls will fail fast instead of
     * repeatedly attempting to refresh an invalid token. This prevents
     * cascading failures in long-running processes.
     */
    private bool $tokenRefreshFailed = false;

    /**
     * The authenticator for token refresh (resolved lazily).
     */
    private ?AnafAuthenticatorInterface $authenticator = null;

    /**
     * Optional callback that re-reads the currently persisted tokens.
     *
     * @var (Closure(): ?OAuthTokensData)|null
     */
    private ?Closure $tokenReloader = null;

    /**
     * The rate limiter for API call throttling.
     */
    private readonly RateLimiter $rateLimiter;

    /**
     * The endpoint-specific bucket the in-flight logical call consumes, if any.
     *
     * Armed by meterCall() and replayed by onRetryAttempt() so that each extra HTTP
     * request a retry makes is metered against the same buckets as the first.
     *
     * @var (Closure(): void)|null
     */
    private ?Closure $retryQuotaConsumer = null;

    /**
     * Create a new EFacturaClient instance.
     *
     * @param  string  $vatNumber  The VAT number (CIF) for API operations
     * @param  string  $accessToken  The OAuth access token
     * @param  string  $refreshToken  The OAuth refresh token for auto-refresh
     * @param  CarbonInterface|null  $expiresAt  Token expiration time (normalised to a mutable Carbon)
     * @param  AnafAuthenticatorInterface|null  $authenticator  Optional authenticator (resolved lazily if not provided)
     * @param  (Closure(): ?OAuthTokensData)|null  $tokenReloader  Optional callback returning the tokens
     *                                                             currently in the caller's store. Consulted
     *                                                             only after acquiring the refresh lock, so a
     *                                                             worker that lost the race adopts the winner's
     *                                                             rotated tokens instead of spending its own
     *                                                             (now dead) refresh token. Strongly recommended
     *                                                             for multi-worker deployments.
     */
    public function __construct(
        private readonly string $vatNumber,
        string $accessToken,
        string $refreshToken,
        ?CarbonInterface $expiresAt = null,
        ?AnafAuthenticatorInterface $authenticator = null,
        ?Closure $tokenReloader = null,
    ) {
        parent::__construct();

        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = self::normaliseExpiry($expiresAt);
        $this->authenticator = $authenticator;
        $this->tokenReloader = $tokenReloader;
        $this->rateLimiter = app(RateLimiter::class);
    }

    /**
     * Normalise any CarbonInterface expiry to a mutable Carbon.
     *
     * Apps calling Date::use(CarbonImmutable::class) hydrate expires_at as a
     * CarbonImmutable, which is not a Carbon subclass.
     */
    private static function normaliseExpiry(?CarbonInterface $expiresAt): ?Carbon
    {
        return $expiresAt === null || $expiresAt instanceof Carbon
            ? $expiresAt
            : Carbon::instance($expiresAt);
    }

    /**
     * Get the authenticator, resolving it lazily if needed.
     *
     * @throws AuthenticationException If OAuth is not configured when token refresh is needed
     */
    private function getAuthenticator(): AnafAuthenticatorInterface
    {
        if ($this->authenticator === null) {
            $this->authenticator = app(AnafAuthenticatorInterface::class);
        }

        return $this->authenticator;
    }

    /**
     * Create client from OAuthTokensData.
     *
     * @param  string  $vatNumber  The VAT number (CIF) for API operations
     * @param  OAuthTokensData  $tokens  The OAuth tokens
     * @param  AnafAuthenticatorInterface|null  $authenticator  Optional authenticator for token refresh
     * @param  (Closure(): ?OAuthTokensData)|null  $tokenReloader  Optional callback re-reading persisted tokens
     */
    public static function fromTokens(
        string $vatNumber,
        OAuthTokensData $tokens,
        ?AnafAuthenticatorInterface $authenticator = null,
        ?Closure $tokenReloader = null,
    ): self {
        return new self(
            vatNumber: $vatNumber,
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
            authenticator: $authenticator,
            tokenReloader: $tokenReloader,
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
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getRetryDelay(): int
    {
        return (int) (config('efactura-sdk.http.retry_delay') ?? self::RETRY_DELAY);
    }

    /**
     * {@inheritdoc}
     */
    protected function getMaxTryCount(): int
    {
        return (int) (config('efactura-sdk.http.retry_times') ?? self::MAX_TRY_COUNT);
    }

    /**
     * Get the maximum time to wait for lock acquisition in seconds.
     * Can be overridden in tests for faster execution.
     */
    protected function getLockWaitSeconds(): int
    {
        return self::TOKEN_REFRESH_LOCK_WAIT;
    }

    /**
     * {@inheritdoc}
     *
     * ANAF meters every cap per HTTP request, but the public methods here check
     * once per logical call. Each retry fires another real request, so it must
     * consume another unit of EVERY bucket the call draws on -- the global cap and
     * the per-message/per-CUI one -- or those buckets under-count by the retry
     * factor. That is how ANAF comes to trip "limita de descarcare depasita" for
     * 24h while getRemainingQuota() still reports headroom.
     *
     * @throws RateLimitExceededException When the retry would exceed a cap
     */
    protected function onRetryAttempt(): void
    {
        $this->rateLimiter->checkGlobal();

        if ($this->retryQuotaConsumer !== null) {
            ($this->retryQuotaConsumer)();
        }
    }

    /**
     * Consume one quota unit for a logical call, and arm the retry hook to consume
     * the same buckets again for every extra HTTP request a retry makes.
     *
     * Always call this instead of hitting $rateLimiter directly: passing no
     * endpoint check disarms the hook, which is what keeps a previous call's
     * bucket from being charged during a later, unrelated call's retries.
     *
     * @param  (Closure(): void)|null  $perEndpoint  The endpoint-specific bucket this
     *                                               call draws on, if any. Endpoints
     *                                               metered only globally pass null.
     *
     * @throws RateLimitExceededException When a cap is already exhausted
     */
    private function meterCall(?Closure $perEndpoint = null): void
    {
        $this->rateLimiter->checkGlobal();

        if ($perEndpoint !== null) {
            $perEndpoint();
        }

        $this->retryQuotaConsumer = $perEndpoint;
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function uploadDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
    {
        // Validate first - fail fast without consuming rate limit quota
        $this->validateXmlContent($xml);

        // Check RASP-specific rate limit when uploading RASP documents. An upload is
        // never retried on a response, but a pre-send transport failure does retry
        // (see PRE_SEND_CURL_ERRORS), and that fires another real request.
        $standard = $options?->getStandard() ?? StandardType::UBL;
        $this->meterCall($standard === StandardType::RASP
            ? fn () => $this->rateLimiter->checkRaspUpload($this->vatNumber)
            : null);

        $queryParams = $this->buildUploadQueryParams($options);
        $route = '/upload?'.http_build_query($queryParams);

        // Not idempotent: ANAF has no idempotency key and issues a new
        // index_incarcare per accepted POST, so an auto-retry would file the
        // same invoice twice. See BaseApiClient::isRetryableException().
        $response = $this->authenticatedXmlRequest($route, 'POST', $xml, idempotent: false);

        return $this->parseUploadResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function uploadB2CDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
    {
        // Validate first - fail fast without consuming rate limit quota
        $this->validateXmlContent($xml);

        // See uploadDocument() for why the RASP bucket is armed for retries.
        $standard = $options?->getStandard() ?? StandardType::UBL;
        $this->meterCall($standard === StandardType::RASP
            ? fn () => $this->rateLimiter->checkRaspUpload($this->vatNumber)
            : null);

        $queryParams = $this->buildUploadQueryParams($options);
        $route = '/uploadb2c?'.http_build_query($queryParams);

        // Not idempotent - see uploadDocument().
        $response = $this->authenticatedXmlRequest($route, 'POST', $xml, idempotent: false);

        return $this->parseUploadResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getStatusMessage(string $uploadId): StatusResponseData
    {
        // Validate first - fail fast without consuming rate limit quota
        $this->validateUploadId($uploadId);

        $this->meterCall(fn () => $this->rateLimiter->checkStatusQuery($uploadId));

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
        // Validate first - fail fast without consuming rate limit quota
        $this->validateDownloadId($downloadId);

        $this->meterCall(fn () => $this->rateLimiter->checkDownload($downloadId));

        $queryParams = [
            'id' => $downloadId,
        ];

        $route = '/descarcare?'.http_build_query($queryParams);

        $response = $this->authenticatedRequest($route, 'GET', expectBinary: true);

        $this->guardDownloadBody($response);

        return DownloadResponseData::fromHttpResponse(
            $response->body(),
            $response->headers()
        );
    }

    /**
     * Reject a 2xx /descarcare body that is not actually a ZIP archive.
     *
     * A 2xx is not proof of success at ANAF: listaMesaje reports its errors as
     * 200 + {"eroare": "..."}, and /descarcare is not documented to behave
     * differently. Without this check a JSON error body would be handed back as a
     * "successful" download whose saveTo() writes JSON into a .zip file.
     *
     * CONTRACT -- the ApiException below carries ANAF's own status code, which for
     * this failure is normally 200. That is DELIBERATE, not a bug: the 2xx is the
     * payload here, encoding "ANAF said OK but the body wasn't a document". Callers
     * need that distinction. A wrapper package keys on statusCode === 200 to leave
     * the upload Completed -- ANAF accepted the invoice, only the receipt is missing
     * -- where a synthesised 4xx/5xx would read as "the filing failed" and could
     * trigger a re-send of a document ANAF already holds. Do not "fix" this to an
     * error code.
     *
     * Nothing inside this SDK misclassifies it: the only statusCode consumers are
     * the `=== 401` checks in authenticatedRequest()/authenticatedXmlRequest()/
     * authenticatedXmlRequestToUrl() -- and this throws from downloadDocument(),
     * outside those try/catch blocks anyway -- plus AnafDetailsClient's `=== 404`,
     * which is a different client on a different call stack. There is no `>= 400`
     * or `>= 500` classification of an ApiException anywhere in the SDK.
     *
     * @throws ApiException When the body is a JSON error or is not a ZIP.
     *                      Its statusCode is ANAF's, typically 200 -- see above.
     */
    private function guardDownloadBody(Response $response): void
    {
        $body = $response->body();
        $trimmed = ltrim($body);
        $contentType = (string) $response->header('Content-Type');

        // Prefer the JSON path: it carries ANAF's actual error message.
        if (str_contains($contentType, 'application/json') || str_starts_with($trimmed, '{')) {
            $json = $response->json();

            if (is_array($json)) {
                throw new ApiException(
                    $this->extractErrorMessage($response),
                    $response->status(),
                    $body
                );
            }
        }

        // Every ZIP begins with a "PK" signature: PK\x03\x04 for a normal archive,
        // PK\x05\x06 for an empty one. Anything else (HTML maintenance page, plain
        // text) is not a document we can hand back as a download.
        if (! str_starts_with($body, 'PK')) {
            throw new ApiException(
                sprintf(
                    'ANAF did not return a ZIP archive for download ID (content-type: %s).',
                    $contentType === '' ? 'unknown' : $contentType
                ),
                $response->status(),
                substr($body, 0, 500)
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getMessages(ListMessagesParamsData $params): ListMessagesResponseData
    {
        // Validate first - fail fast without consuming rate limit quota
        $this->validateDays($params->days);

        $this->meterCall(fn () => $this->rateLimiter->checkSimpleList($params->cif));

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
        // Validate first - fail fast without consuming rate limit quota
        $this->validateTimeRange($params->startTime, $params->endTime);
        $this->validatePage($params->page);

        $this->meterCall(fn () => $this->rateLimiter->checkPaginatedList($params->cif));

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
        // Validate first - fail fast without consuming rate limit quota
        $this->validateXmlContent($xml);

        // Metered globally only, so this also disarms any previous call's bucket.
        $this->meterCall();

        $validateUrl = config('efactura-sdk.endpoints.services.validate');
        if (empty($validateUrl)) {
            throw new ValidationException('Missing configuration: efactura-sdk.endpoints.services.validate');
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
        // Validate first - fail fast without consuming rate limit quota
        $this->validateXmlContent($xml);

        // Metered globally only - see validateXml().
        $this->meterCall();

        $verifyUrl = config('efactura-sdk.endpoints.services.verify_signature');
        if (empty($verifyUrl)) {
            throw new ValidationException('Missing configuration: efactura-sdk.endpoints.services.verify_signature');
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
        // Validate first - fail fast without consuming rate limit quota
        $this->validateXmlContent($xml);

        // Metered globally only - see validateXml().
        $this->meterCall();

        $transformUrl = config('efactura-sdk.endpoints.services.transform');
        if (empty($transformUrl)) {
            throw new ValidationException('Missing configuration: efactura-sdk.endpoints.services.transform');
        }
        $endpoint = $validate ? $standard->value : "{$standard->value}/DA";

        $url = $transformUrl.'/'.$endpoint;

        $response = $this->authenticatedXmlRequestToUrl($url, 'POST', $xml, expectBinary: true);

        // Check if response is actually an error (JSON response instead of PDF)
        $contentType = (string) $response->header('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $errorData = $response->json();
            if ($errorData === null) {
                throw new ApiException(
                    'PDF conversion failed with invalid JSON response',
                    $response->status(),
                    $response->body()
                );
            }
            // Same shape as the validare service: the real reason lives in
            // Messages[].message, so prefer it over the generic fallback.
            $messages = self::extractValidationErrors($errorData);

            throw new ApiException(
                $errorData['message']
                    ?? $errorData['eroare']
                    ?? ($messages !== null ? implode('; ', $messages) : null)
                    ?? 'PDF conversion failed',
                $response->status(),
                json_encode($errorData) ?: null
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
        // Fail fast if a previous refresh attempt failed - prevents cascading failures
        // in long-running processes. Create a new client instance with fresh credentials.
        if ($this->tokenRefreshFailed) {
            throw new AuthenticationException(
                'Token refresh previously failed. Create a new client instance with valid credentials.'
            );
        }

        if ($this->isTokenValid()) {
            return $this->accessToken;
        }

        // Use distributed lock to prevent concurrent token refresh attempts
        // This is critical because ANAF invalidates refresh tokens after use
        $lockKey = "efactura:token_refresh:{$this->vatNumber}";
        $lock = Cache::lock($lockKey, self::TOKEN_REFRESH_LOCK_TIMEOUT);

        try {
            // Wait for lock with timeout - block() throws LockTimeoutException on timeout
            $lock->block($this->getLockWaitSeconds());

            // Re-read the persisted tokens now that we hold the lock. Whoever held it
            // before us may have refreshed and rotated them, which would leave our
            // in-memory refreshToken already spent. Without this the re-check below
            // is a no-op: nothing mutates our own properties while we block.
            $this->reloadTokens();

            if ($this->isTokenValid()) {
                return $this->accessToken;
            }

            $this->refreshTokens();

            return $this->accessToken;
        } catch (LockTimeoutException $e) {
            // Could not acquire lock within timeout
            // Another process is likely refreshing the token - fail fast rather than use stale token
            // ANAF uses rotating refresh tokens, so the old token is invalidated after refresh
            $this->logger->error('Could not acquire token refresh lock, token may be stale', [
                'vatNumber' => $this->vatNumber,
            ]);

            throw new AuthenticationException(
                'Token refresh lock timeout. Another process may be refreshing the token. Please retry.',
                0,
                $e
            );
        } finally {
            // Safe to call - Laravel's lock tracks ownership and won't release others' locks
            $lock->release();
        }
    }

    /**
     * Re-read the caller's persisted tokens, if a reloader was supplied.
     *
     * Adopts the store's tokens only when they are at least as fresh as our own.
     * ANAF rotates refresh tokens, so the loser of a refresh race must take the
     * winner's rotated pair rather than spend its own (now dead) refresh token.
     *
     * The freshness test is what keeps that from cutting the other way. Once this
     * client has refreshed for itself, the store is NOT automatically the fresher
     * side: the caller persists only after the call returns (see the class
     * docblock's `wasTokenRefreshed()` pattern), so between our refresh and their
     * write the store still holds the pair we just spent. Adopting it there would
     * re-spend a dead refresh token, fail, and latch $tokenRefreshFailed -- killing
     * every later call on this client. Comparing expiry keeps both cases right:
     * a worker that rotated after us has a strictly later expiry and still wins.
     *
     * Never throws: a store failure must not take down the API call. We fall back
     * to our own tokens, which is exactly the pre-existing behaviour.
     */
    private function reloadTokens(): void
    {
        if ($this->tokenReloader === null) {
            return;
        }

        try {
            $tokens = ($this->tokenReloader)();
        } catch (\Throwable $e) {
            $this->logger->warning('Token reloader failed, falling back to in-memory tokens', [
                'vatNumber' => $this->vatNumber,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $tokens instanceof OAuthTokensData) {
            return;
        }

        if (! $this->shouldAdoptReloadedTokens($tokens)) {
            $this->logger->debug('Keeping self-minted tokens over the store copy', [
                'vatNumber' => $this->vatNumber,
                'ownExpiresAt' => $this->expiresAt?->toIso8601String(),
                'storeExpiresAt' => $tokens->expiresAt?->toIso8601String(),
            ]);

            return;
        }

        $this->accessToken = $tokens->accessToken;
        $this->refreshToken = $tokens->refreshToken;
        $this->expiresAt = self::normaliseExpiry($tokens->expiresAt);

        // Deliberately NOT setting $tokenRefreshed: these tokens came out of the
        // caller's store, so there is nothing new to write back.
    }

    /**
     * Decide whether the store's tokens should replace the ones held in memory.
     *
     * Until this client mints a pair of its own, the store is always authoritative:
     * our in-memory copy came from it, so anything it holds now is at least as new.
     * After we have refreshed, only a strictly later expiry proves the store moved
     * on past us -- anything else is either our own pending write coming back, or a
     * stale copy whose refresh token we have already spent.
     */
    private function shouldAdoptReloadedTokens(OAuthTokensData $tokens): bool
    {
        if (! $this->tokenRefreshed) {
            return true;
        }

        if ($tokens->expiresAt === null || $this->expiresAt === null) {
            return false;
        }

        return $tokens->expiresAt->greaterThan($this->expiresAt);
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
            $newTokens = $this->getAuthenticator()->refreshAccessToken($this->refreshToken);

            $this->accessToken = $newTokens->accessToken;
            $this->refreshToken = $newTokens->refreshToken;
            $this->expiresAt = $newTokens->expiresAt;
            $this->tokenRefreshed = true;

            $this->logger->info('ANAF access token refreshed successfully', [
                'vatNumber' => $this->vatNumber,
                'newExpiresAt' => $this->expiresAt?->toIso8601String(),
            ]);
        } catch (AuthenticationException $e) {
            // Mark refresh as failed to prevent cascading failures on subsequent calls
            // The client must be recreated with fresh credentials to recover
            $this->tokenRefreshFailed = true;

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
     * @param  array<string, mixed>  $data
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

        // GET requests should not include Content-Type (no request body)
        $headers = strtolower($method) === 'get'
            ? ['Authorization' => 'Bearer '.$token]
            : array_merge($this->getHeaders(), ['Authorization' => 'Bearer '.$token]);

        if ($expectBinary) {
            $headers['Accept'] = 'application/octet-stream, application/zip, application/json';
        }

        try {
            return $this->call($route, $method, $data, $headers);
        } catch (ApiException $e) {
            // The only 401 gate. call() throws on every non-2xx, so a 401 arrives here
            // as an ApiException and never as a returned Response.
            if ($e->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed. Token may be invalid or revoked.',
                    401,
                    $e,
                    $e->context
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
        bool $expectBinary = false,
        bool $idempotent = true
    ): Response {
        $token = $this->getValidAccessToken();

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/xml',
        ];

        if ($expectBinary) {
            $headers['Accept'] = 'application/octet-stream, application/zip, application/json';
        }

        try {
            return $this->callRaw($route, $method, $xmlBody, 'application/xml', $headers, $idempotent);
        } catch (ApiException $e) {
            // The only 401 gate - see authenticatedRequest().
            if ($e->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed. Token may be invalid or revoked.',
                    401,
                    $e,
                    $e->context
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
            return $this->requestToFullUrl($fullUrl, $method, $xmlBody, 'text/plain', $headers);
        } catch (ApiException $e) {
            // The only 401 gate - see authenticatedRequest().
            if ($e->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed. Token may be invalid or revoked.',
                    401,
                    $e,
                    $e->context
                );
            }
            throw $e;
        }
    }

    /**
     * Make a raw request to a full URL (bypassing base URL).
     *
     * Retries unconditionally, unlike call()/callRaw(): the only callers are the
     * validate, verify-signature and transform services, which are pure
     * stateless functions of the posted XML. Nothing is filed, so re-sending
     * cannot duplicate anything.
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
        $startTime = Carbon::now();
        $context = fn () => [
            'url' => $fullUrl,
            'bodyLength' => strlen($body),
            'contentType' => $contentType,
            'duration' => $this->lastRequestDurationMilliseconds,
            'tryCount' => $tryCount,
        ];

        try {
            $request = Http::timeout(static::getTimeoutDuration());
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
            // Calculate duration for failed requests so logging is accurate
            $this->lastRequestDurationMilliseconds = $startTime->diffInMilliseconds(Carbon::now());

            $this->logger->error(
                "Exception before response was received: {$exception->getMessage()}.",
                $context()
            );

            if ($tryCount < $this->getMaxTryCount()) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt();

                return $this->requestToFullUrl($fullUrl, $method, $body, $contentType, $headers, $tryCount);
            }

            throw new ApiException(
                $exception->getMessage(),
                500,
                null,
                $exception,
                $context()
            );
        }

        if (! $response->successful()) {
            if ($tryCount < $this->getMaxTryCount() && $this->isRetryable($response)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt();

                return $this->requestToFullUrl($fullUrl, $method, $body, $contentType, $headers, $tryCount);
            }

            throw new ApiException(
                $this->extractErrorMessage($response),
                $response->status() >= 500 ? 502 : $response->status(),
                $response->body(),
                null,
                $context()
            );
        }

        return $response;
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
        // Success: {"stare": "ok", "trace_id": ...} or {"valid": true}
        // Error:   {"stare": "nok", "Messages": [{"message": "E: ..."}], "trace_id": ...}
        // Legacy:  {"valid": false, "mesaj": "..."} or {"eroare": "..."}
        // Note: Do NOT use stripos heuristics as it matches "Invalid", "not valid", etc.
        $isValid = ($json['valid'] ?? false)
            || ($json['stare'] ?? '') === 'ok';

        return new ValidationResultData(
            valid: $isValid,
            details: $json['mesaj'] ?? $json['detalii'] ?? $json['message'] ?? null,
            info: $json['info'] ?? self::extractTraceId($json),
            errors: self::extractValidationErrors($json),
        );
    }

    /**
     * Collect the human-readable reasons a validation failed.
     *
     * ANAF's validare/verificare services report these in Messages[].message.
     * Without them the caller learns the document is invalid but never why, which
     * makes a rejected filing undiagnosable. Legacy Errors/eroare keys are still
     * honoured, and plain-string Messages entries are accepted defensively.
     *
     * @param  array<string, mixed>  $json
     * @return string[]|null Null when the response carries no error detail at all
     */
    private static function extractValidationErrors(array $json): ?array
    {
        $errors = [];

        if (isset($json['Messages']) && is_array($json['Messages'])) {
            foreach ($json['Messages'] as $entry) {
                if (is_array($entry) && isset($entry['message']) && is_scalar($entry['message'])) {
                    $errors[] = (string) $entry['message'];
                } elseif (is_string($entry)) {
                    $errors[] = $entry;
                }
            }
        }

        if (isset($json['Errors']) && is_array($json['Errors'])) {
            foreach ($json['Errors'] as $entry) {
                if (is_array($entry) && isset($entry['errorMessage']) && is_scalar($entry['errorMessage'])) {
                    $errors[] = (string) $entry['errorMessage'];
                } elseif (is_scalar($entry)) {
                    $errors[] = (string) $entry;
                }
            }
        }

        if (isset($json['eroare']) && is_scalar($json['eroare'])) {
            $errors[] = (string) $json['eroare'];
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * ANAF returns trace_id both quoted and bare; normalise to the DTO's string.
     *
     * @param  array<string, mixed>  $json
     */
    private static function extractTraceId(array $json): ?string
    {
        return isset($json['trace_id']) && is_scalar($json['trace_id'])
            ? (string) $json['trace_id']
            : null;
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
