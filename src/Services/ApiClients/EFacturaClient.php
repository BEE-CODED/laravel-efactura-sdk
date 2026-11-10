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
use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use BeeCoded\EFacturaSdk\Support\AnafValue;
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
 *
 * // Multi-worker deployments: re-read the store under the refresh lock, and write
 * // a rotated pair before the lock is released, so no other worker can spend it.
 * $client = EFacturaClient::fromTokens(
 *     '12345678',
 *     $tokens,
 *     tokenReloader: fn () => $store->tokensFor('12345678'),
 *     tokenPersister: fn (OAuthTokensData $new) => $store->save('12345678', $new),
 * );
 * ```
 */
class EFacturaClient extends BaseApiClient implements EFacturaClientInterface
{
    /**
     * Buffer time in seconds before token expiration to trigger refresh.
     */
    private const int TOKEN_EXPIRY_BUFFER_SECONDS = 120;

    /**
     * Seconds the refresh lock outlives the HTTP timeout of the refresh request,
     * covering the reloader and persister that also run under it.
     */
    private const int TOKEN_REFRESH_LOCK_MARGIN_SECONDS = 30;

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
     * Optional callback that writes freshly refreshed tokens to the caller's store.
     *
     * @var (Closure(OAuthTokensData): mixed)|null
     */
    private ?Closure $tokenPersister = null;

    /**
     * The rate limiter for API call throttling.
     */
    private readonly RateLimiter $rateLimiter;

    /**
     * The metering of the in-flight logical call: its global unit and its
     * endpoint-specific bucket, if any.
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
     * @param  (Closure(OAuthTokensData): mixed)|null  $tokenPersister  Optional callback that stores the
     *                                                                  tokens a refresh just minted. Called
     *                                                                  inside the refresh lock, right after a
     *                                                                  successful refresh and before release,
     *                                                                  so no other worker can spend the rotated
     *                                                                  refresh token first. An exception it throws
     *                                                                  reaches the caller; the client keeps the
     *                                                                  new tokens and wasTokenRefreshed() is true.
     */
    public function __construct(
        private readonly string $vatNumber,
        string $accessToken,
        string $refreshToken,
        ?CarbonInterface $expiresAt = null,
        ?AnafAuthenticatorInterface $authenticator = null,
        ?Closure $tokenReloader = null,
        ?Closure $tokenPersister = null,
    ) {
        parent::__construct();

        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = self::normaliseExpiry($expiresAt);
        $this->authenticator = $authenticator;
        $this->tokenReloader = $tokenReloader;
        $this->tokenPersister = $tokenPersister;
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
     * @param  (Closure(OAuthTokensData): mixed)|null  $tokenPersister  Optional callback storing refreshed tokens under the lock
     */
    public static function fromTokens(
        string $vatNumber,
        OAuthTokensData $tokens,
        ?AnafAuthenticatorInterface $authenticator = null,
        ?Closure $tokenReloader = null,
        ?Closure $tokenPersister = null,
    ): self {
        return new self(
            vatNumber: $vatNumber,
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
            authenticator: $authenticator,
            tokenReloader: $tokenReloader,
            tokenPersister: $tokenPersister,
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
        // env() yields a string; an empty or zero value would be Guzzle's "no timeout".
        $timeout = (float) config('efactura-sdk.http.timeout', 30);

        return $timeout > 0 ? $timeout : 30;
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
        if ($this->retryQuotaConsumer === null) {
            $this->rateLimiter->checkGlobal();

            return;
        }

        ($this->retryQuotaConsumer)();
    }

    /**
     * Consume one quota unit for a logical call, and arm the retry hook to consume
     * the same buckets again for every extra HTTP request a retry makes.
     *
     * A refused call or retry spends nothing: the endpoint bucket is peeked first,
     * and the global check refuses before the endpoint bucket is counted.
     *
     * Always call this instead of hitting $rateLimiter directly: passing no
     * endpoint check disarms the hook, which is what keeps a previous call's
     * bucket from being charged during a later, unrelated call's retries.
     *
     * @param  (Closure(): void)|null  $perEndpoint  Counts the endpoint-specific bucket this
     *                                               call draws on, if any. Endpoints
     *                                               metered only globally pass null.
     * @param  (Closure(): void)|null  $peek  Refuses, counting nothing, when that bucket
     *                                        has no room (RateLimiter::ensureAvailable())
     *
     * @throws RateLimitExceededException When a cap is already exhausted
     */
    private function meterCall(?Closure $perEndpoint = null, ?Closure $peek = null): void
    {
        $meter = function () use ($perEndpoint, $peek): void {
            if ($peek !== null) {
                $peek();
            }

            $this->rateLimiter->checkGlobal();

            // shortcut: a concurrent call can take the bucket's last unit between the peek
            // and here, spending this call's global unit; closing it needs a lock across buckets.
            if ($perEndpoint !== null) {
                $perEndpoint();
            }
        };

        $meter();

        $this->retryQuotaConsumer = $meter;
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
        if ($standard === StandardType::RASP) {
            $this->meterCall(
                fn () => $this->rateLimiter->checkRaspUpload($this->vatNumber),
                fn () => $this->rateLimiter->ensureAvailable(RateLimitScope::RaspUpload, $this->vatNumber),
            );
        } else {
            $this->meterCall();
        }

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
        if ($standard === StandardType::RASP) {
            $this->meterCall(
                fn () => $this->rateLimiter->checkRaspUpload($this->vatNumber),
                fn () => $this->rateLimiter->ensureAvailable(RateLimitScope::RaspUpload, $this->vatNumber),
            );
        } else {
            $this->meterCall();
        }

        $queryParams = $this->buildUploadQueryParams($options);
        $route = '/uploadb2c?'.http_build_query($queryParams);

        // Not idempotent - see uploadDocument().
        $response = $this->authenticatedXmlRequest($route, 'POST', $xml, idempotent: false);

        return $this->parseUploadResponse($response);
    }

    /**
     * {@inheritdoc}
     *
     * @param  bool  $useProviderLimit  Count this read, and each of its retries, against ANAF's own
     *                                  status ceiling instead of the SDK's lower limit (same
     *                                  per-message counter). Concrete class only, not on the interface.
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getStatusMessage(string $uploadId, bool $useProviderLimit = false): StatusResponseData
    {
        // Validate first - fail fast without consuming rate limit quota
        $this->validateUploadId($uploadId);

        // A default read keeps v3.1.1's exact call shape, so consumers' argument-matching mocks still match.
        $this->meterCall(
            fn () => $useProviderLimit
                ? $this->rateLimiter->checkStatusQuery($uploadId, true)
                : $this->rateLimiter->checkStatusQuery($uploadId),
            fn () => $this->rateLimiter->ensureAvailable(RateLimitScope::Status, $uploadId, $useProviderLimit),
        );

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

        $this->meterCall(
            fn () => $this->rateLimiter->checkDownload($downloadId),
            fn () => $this->rateLimiter->ensureAvailable(RateLimitScope::Download, $downloadId),
        );

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
        $trimmed = ltrim(AnafValue::withoutBom($body));
        $contentType = (string) $response->header('Content-Type');

        // Prefer the JSON path: it carries ANAF's actual error message.
        if (str_contains($contentType, 'application/json') || str_starts_with($trimmed, '{')) {
            $json = static::decodeJson($response);

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
        $this->validateCif($params->cif);
        $this->validateDays($params->days);

        $this->meterCall(
            fn () => $this->rateLimiter->checkSimpleList($params->cif),
            fn () => $this->rateLimiter->ensureAvailable(RateLimitScope::SimpleList, $params->cif),
        );

        $queryParams = [
            'cif' => $params->cif,
            'zile' => $params->days,
        ];

        if ($params->filter !== null) {
            $queryParams['filtru'] = $params->filter->value;
        }

        $route = '/listaMesajeFactura?'.http_build_query($queryParams);

        $response = $this->authenticatedRequest($route, 'GET');

        return ListMessagesResponseData::fromAnafResponse(
            $this->decodeJsonObject($response, 'Unable to parse message list response')
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateLimitExceededException When rate limit is exceeded
     */
    public function getMessagesPaginated(PaginatedMessagesParamsData $params): PaginatedMessagesResponseData
    {
        // Validate first - fail fast without consuming rate limit quota
        $this->validateCif($params->cif);
        $this->validateTimeRange($params->startTime, $params->endTime);
        $this->validatePage($params->page);

        $this->meterCall(
            fn () => $this->rateLimiter->checkPaginatedList($params->cif),
            fn () => $this->rateLimiter->ensureAvailable(RateLimitScope::PaginatedList, $params->cif),
        );

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

        return PaginatedMessagesResponseData::fromAnafResponse(
            $this->decodeJsonObject($response, 'Unable to parse paginated message list response')
        );
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

        // ANAF can answer 2xx with a JSON error instead of a PDF, sometimes without a JSON content type.
        $contentType = (string) $response->header('Content-Type');
        $body = $response->body();
        if (str_contains($contentType, 'json') || str_starts_with(ltrim(AnafValue::withoutBom($body)), '{')) {
            $errorData = $this->decodeJsonObject($response, 'PDF conversion failed with invalid JSON response');
            // Same shape as the validare service: the real reason lives in
            // Messages[].message, so prefer it over the generic fallback.
            $messages = ValidationResultData::fromAnafResponse($errorData)->errors;

            throw new ApiException(
                AnafValue::text($errorData['message'] ?? null)
                    ?? AnafValue::text($errorData['eroare'] ?? null)
                    ?? ($messages !== null ? implode('; ', $messages) : null)
                    ?? 'PDF conversion failed',
                $response->status(),
                json_encode($errorData) ?: null
            );
        }

        // Anything else that is not a PDF (an HTML maintenance page, plain text) is not a document.
        if (! str_starts_with($body, '%PDF')) {
            throw new ApiException(
                sprintf(
                    'ANAF did not return a PDF (content-type: %s).',
                    $contentType === '' ? 'unknown' : $contentType
                ),
                $response->status(),
                substr($body, 0, 500)
            );
        }

        return $body;
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
     * The lock ensures only one process refreshes the token at a time. A process that
     * cannot get the lock within the wait time throws AuthenticationException (code 0)
     * instead of using its old token.
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
        // The lock must outlive the refresh request: one that expires mid-request
        // lets another worker spend the same rotating refresh token.
        $lock = Cache::lock($lockKey, (int) ceil(static::getTimeoutDuration()) + self::TOKEN_REFRESH_LOCK_MARGIN_SECONDS);

        try {
            try {
                $lock->block($this->getLockWaitSeconds());
            } catch (LockTimeoutException $e) {
                // Only our own lock wait: a persister's LockTimeoutException must reach
                // the caller as itself. Another process is likely refreshing - fail fast
                // rather than use a stale token (ANAF rotates refresh tokens).
                $this->logger->error('Could not acquire token refresh lock, token may be stale', [
                    'vatNumber' => $this->vatNumber,
                ]);

                throw new AuthenticationException(
                    'Token refresh lock timeout. Another process may be refreshing the token. Please retry.',
                    0,
                    $e
                );
            }

            // Re-read the persisted tokens now that we hold the lock. Whoever held it
            // before us may have refreshed and rotated them, which would leave our
            // in-memory refreshToken already spent. Without this the re-check below
            // is a no-op: nothing mutates our own properties while we block.
            $this->reloadTokens();

            if ($this->isTokenValid()) {
                return $this->accessToken;
            }

            $newTokens = $this->refreshTokens();

            // Still under the lock: the next worker to take it reloads what we stored.
            if ($this->tokenPersister !== null) {
                ($this->tokenPersister)($newTokens);
            }

            return $this->accessToken;
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
     * @return OAuthTokensData The tokens ANAF just issued
     *
     * @throws AuthenticationException When refresh fails
     */
    private function refreshTokens(): OAuthTokensData
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

            return $newTokens;
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
        $xml = trim(AnafValue::withoutBom($body));

        // Try to parse as XML first (ANAF returns XML for upload responses)
        if (str_starts_with($xml, '<')) {
            try {
                $parsed = XmlParser::parseUploadResponse($xml);

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
        return UploadResponseData::fromAnafResponse(
            $this->decodeJsonObject($response, 'Unable to parse upload response')
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
        $xml = trim(AnafValue::withoutBom($body));

        // Try to parse as XML first
        if (str_starts_with($xml, '<')) {
            try {
                $parsed = XmlParser::parseStatusResponse($xml);

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
        return StatusResponseData::fromAnafResponse(
            $this->decodeJsonObject($response, 'Unable to parse status response')
        );
    }

    /**
     * Parse validation response from ANAF.
     *
     * @throws ApiException When response cannot be parsed
     */
    private function parseValidationResponse(Response $response): ValidationResultData
    {
        return ValidationResultData::fromAnafResponse(
            $this->decodeJsonObject($response, 'Unable to parse validation response')
        );
    }

    /**
     * Decode a 2xx ANAF JSON body that must be an object.
     *
     * A body that is not JSON, or JSON that is not an object (a bare number or
     * string), is answered with ApiException carrying ANAF's own status code: the
     * same failure as any other unparseable 2xx body.
     *
     * @return array<mixed>
     *
     * @throws ApiException When the body does not decode to an array
     */
    private function decodeJsonObject(Response $response, string $message): array
    {
        $json = static::decodeJson($response);

        if (! is_array($json)) {
            throw new ApiException($message, $response->status(), $response->body());
        }

        return $json;
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
     * Validate the CIF of a message list request.
     *
     * @throws ValidationException When the CIF is empty or blank
     */
    private function validateCif(string $cif): void
    {
        if (trim($cif) === '') {
            throw new ValidationException('CIF cannot be empty');
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
