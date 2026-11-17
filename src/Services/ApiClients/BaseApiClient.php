<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\RequestNotSentException;
use BeeCoded\EFacturaSdk\Support\AnafValue;
use BeeCoded\EFacturaSdk\Support\XmlParser;
use Closure;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

abstract class BaseApiClient implements HasLogger
{
    protected LoggerInterface $logger;

    protected const int MAX_TRY_COUNT = 3;

    protected const int RETRY_DELAY = 5;

    /** The message of an error answer that carried no message of its own. */
    public const string NO_ERROR_MESSAGE = 'No error message in API response';

    /**
     * cURL error numbers that prove the request never reached the server.
     *
     * Only these may be retried for a non-idempotent call: in each case the
     * connection was never established, so the server cannot have acted on a
     * request it never received.
     *
     * Deliberately EXCLUDES CURLE_OPERATION_TIMEDOUT (28) and CURLE_GOT_NOTHING
     * (52). Guzzle reports both as a ConnectException (see CurlFactory's
     * $connectionErrors map), but both can fire *after* the request body was
     * delivered and accepted -- a read timeout is exactly the case where ANAF
     * has the document and we simply never saw the reply. The exception class is
     * therefore not proof of anything; the errno is. A 28 counts as pre-send only
     * with the connect-phase evidence checked in wasNotSent().
     *
     * A failure proven pre-send surfaces as RequestNotSentException once the
     * retries are spent.
     *
     * @var list<int>
     */
    protected const array PRE_SEND_CURL_ERRORS = [
        5,  // CURLE_COULDNT_RESOLVE_PROXY - proxy DNS failed, no connection made
        6,  // CURLE_COULDNT_RESOLVE_HOST  - DNS failed, no connection made
        7,  // CURLE_COULDNT_CONNECT       - TCP connect refused/failed, no bytes sent
        35, // CURLE_SSL_CONNECT_ERROR     - TLS handshake failed, precedes any HTTP data
    ];

    protected float $lastRequestDurationMilliseconds = 0;

    /**
     * Whether an attempt of the call in flight may have reached ANAF: it got a response,
     * or failed in a way wasNotSent() cannot rule out. Reset when a call starts.
     */
    protected bool $attemptDelivered = false;

    public function __construct()
    {
        $this->logger = new class(static::getLogger()) implements LoggerInterface
        {
            use LoggerTrait;

            public function __construct(private readonly LoggerInterface $inner) {}

            /**
             * A failing log handler (an unwritable file, a full disk) must never cost an
             * ANAF answer or turn a delivered request into a transport failure.
             *
             * @param  array<mixed>  $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                try {
                    // The named method (debug(), error(), ...), so a host's logger sees the same calls as before.
                    $this->inner->{$level}($message, $context);
                } catch (\Throwable) {
                    // Swallowed on purpose: logging is never part of the call's outcome.
                }
            }
        };
    }

    abstract public static function getBaseUrl(): string;

    abstract public static function getTimeoutDuration(): float|int;

    /**
     * @return array<string, string>
     */
    abstract protected function getHeaders(): array;

    /**
     * Seconds between retries: efactura-sdk.http.retry_delay (normalised at boot), else RETRY_DELAY.
     */
    protected function getRetryDelay(): int
    {
        // Floored again for a runtime config()->set(): a -1 would make sleep() throw ValueError.
        return max(0, (int) (config('efactura-sdk.http.retry_delay') ?? static::RETRY_DELAY));
    }

    /**
     * The timeout of this client's requests; an instance may differ from the class default.
     */
    protected function getRequestTimeout(): float|int
    {
        return static::getTimeoutDuration();
    }

    /**
     * Attempts per call: efactura-sdk.http.retry_times (normalised at boot), else MAX_TRY_COUNT; at least 1.
     */
    protected function getMaxTryCount(): int
    {
        return max(1, (int) (config('efactura-sdk.http.retry_times') ?? static::MAX_TRY_COUNT));
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [];
    }

    /**
     * The URL for a route under the base URL; an absolute http(s) URL is used as is
     * (ANAF's validate, verify and PDF services live on another host).
     */
    public function getRequestUrl(string $route): string
    {
        if (preg_match('#^https?://#i', $route) === 1) {
            return $route;
        }

        $baseUrl = rtrim(static::getBaseUrl(), '/');
        $route = ltrim($route, '/');

        return $baseUrl.'/'.$route;
    }

    /**
     * @param  array<string, mixed>|string  $data
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|Closure  $context
     * @param  bool  $idempotent  False for anything that files a document: it then never follows a redirect
     *
     * @throws ConnectionException
     */
    protected function request(
        string $route,
        array|string $data = [],
        string $method = 'get',
        #[\SensitiveParameter] array $headers = [],
        array|Closure $context = [],
        ?string $contentType = null,
        bool $idempotent = true
    ): Response {
        $url = $this->getRequestUrl($route);

        $startTime = Carbon::now();
        $request = Http::timeout($this->getRequestTimeout());
        if (! $idempotent) {
            // A redirect hides that the first hop reached ANAF: a failure on a later hop would read as "not sent".
            $request->withoutRedirecting();
        }

        $request->withHeaders($this->getDefaultHeaders() + $headers);

        if ($contentType) {
            // When contentType is provided, data must be a string (raw body)
            assert(is_string($data), 'Data must be a string when contentType is provided');
            $response = $request->withBody($data, $contentType)->$method($url);
        } elseif ($data === [] || $data === '') {
            // Only the URL: Laravel's PendingRequest::get($url, []) passes ['query' => []]
            // to Guzzle, which would strip query params already embedded in the URL.
            $response = $request->$method($url);
        } elseif (strtolower($method) === 'get') {
            $response = $request->get($url, $data);
        } else {
            // Every SDK body travels through callRaw() with an explicit content type.
            throw new \LogicException('A request body needs a content type: send it through callRaw().');
        }

        $endTime = Carbon::now();

        $timeDiff = $startTime->diffInMilliseconds($endTime);
        $this->lastRequestDurationMilliseconds = $timeDiff;

        $logContext = array_merge(
            $context instanceof Closure ? $context() : $context,
            [
                'requestBodyLength' => is_string($data) ? strlen($data) : strlen(json_encode($data) ?: ''),
                'responseBodyLength' => strlen($response->body()),
            ]
        );

        if (config('efactura-sdk.logging.debug')) {
            // Never write the bearer token to the logs.
            $requestHeaders = $this->getDefaultHeaders() + $headers;
            foreach (array_keys($requestHeaders) as $name) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $requestHeaders[$name] = '[redacted]';
                }
            }
            $logContext['requestHeaders'] = $requestHeaders;
            $logContext['responseHeaders'] = $response->headers();
            $logContext['responseBody'] = $response->body();

            if (is_string($data)) {
                $logContext['requestBody'] = $data;
            }
        }

        $this->logger->debug(
            sprintf(
                '%s %s. Response %s. Duration: %d ms.',
                strtoupper($method),
                $url,
                $response->status(),
                $timeDiff
            ),
            $logContext
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $customHeaders
     * @param  bool  $idempotent  Whether re-sending this request is free of side effects
     *                            at ANAF. Pass false for anything that files a document.
     *
     * @throws ApiException
     */
    protected function call(
        string $route,
        string $method = 'get',
        array $data = [],
        #[\SensitiveParameter] array $customHeaders = [],
        bool $idempotent = true,
        int $tryCount = 0
    ): Response {
        return $this->attempt($route, $method, $data, null, $customHeaders, $idempotent, $tryCount, ['data' => $data]);
    }

    /**
     * Make a raw request with a specific content type (e.g., XML).
     *
     * @param  array<string, string>  $customHeaders
     * @param  bool  $idempotent  Whether re-sending this request is free of side effects
     *                            at ANAF. Pass false for anything that files a document:
     *                            uploads travel through here, and a blind retry files the
     *                            same invoice twice.
     *
     * @throws ApiException
     */
    protected function callRaw(
        string $route,
        string $method,
        string $body,
        string $contentType,
        #[\SensitiveParameter] array $customHeaders = [],
        bool $idempotent = true,
        int $tryCount = 0
    ): Response {
        return $this->attempt($route, $method, $body, $contentType, $customHeaders, $idempotent, $tryCount, [
            'bodyLength' => strlen($body),
            'contentType' => $contentType,
        ]);
    }

    /**
     * The one retry loop and "was it sent" reading behind call() and callRaw().
     *
     * @param  array<string, mixed>|string  $data
     * @param  array<string, string>  $customHeaders
     * @param  array<string, mixed>  $describe  How the log context describes the body
     *
     * @throws ApiException
     */
    private function attempt(
        string $route,
        string $method,
        array|string $data,
        ?string $contentType,
        #[\SensitiveParameter] array $customHeaders,
        bool $idempotent,
        int $tryCount,
        array $describe
    ): Response {
        $tryCount++;
        if ($tryCount === 1) {
            $this->attemptDelivered = false;
        }
        $startTime = Carbon::now();
        $context = fn () => [
            'route' => $route,
            ...$describe,
            'duration' => $this->lastRequestDurationMilliseconds,
            'tryCount' => $tryCount,
        ];

        try {
            $response = $this->request(
                $route,
                $data,
                $method,
                empty($customHeaders) ? $this->getHeaders() : $customHeaders,
                $context,
                $contentType,
                $idempotent
            );
        } catch (\LogicException $exception) {
            // A programming error while the request was built, before any I/O: never a transport failure to retry.
            throw $exception;
        } catch (Exception $exception) {
            // Calculate duration for failed requests so logging is accurate
            $this->lastRequestDurationMilliseconds = $startTime->diffInMilliseconds(Carbon::now());

            $this->logger->error(
                "Exception before response was received: {$exception->getMessage()}.",
                $context()
            );

            $sent = ! self::wasNotSent($exception);
            $this->attemptDelivered = $this->attemptDelivered || $sent;

            if ($tryCount < $this->getMaxTryCount() && $this->isRetryableException($exception, $idempotent)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt($sent);

                return $this->attempt($route, $method, $data, $contentType, $customHeaders, $idempotent, $tryCount, $describe);
            }

            throw $this->transportFailure($exception, $context());
        }

        if (! $response->successful()) {
            $this->attemptDelivered = true;

            // A response means the request was delivered and ANAF acted on it, so a
            // non-idempotent call must never be re-sent -- even on a 5xx.
            if ($tryCount < $this->getMaxTryCount() && $idempotent && $this->isRetryable($response)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt(true);

                return $this->attempt($route, $method, $data, $contentType, $customHeaders, $idempotent, $tryCount, $describe);
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

    protected function isRetryable(Response $response): bool
    {
        // Retry server errors (5xx) only.
        //
        // 429 (rate limit) is NOT retried because:
        // 1. Client-side rate limiting should prevent most 429s
        // 2. Blind retry without Retry-After header is counterproductive
        // 3. If client-side limits are wrong/disabled, failing fast is better
        //
        // Connection failures never reach this method, despite what the status === 0
        // check below implies. There is no response to classify when the connection
        // fails: Laravel raises a ConnectionException, which call()/callRaw() catch
        // and route to isRetryableException() instead. A Response here always wraps a
        // PSR-7 response, whose status is always a real HTTP code -- never 0. The
        // check is kept only as a cheap guard against a future transport that does
        // synthesise one; it is not the connection-failure path.
        //
        // This classifies the response only. Whether a retry is *safe* for the
        // operation is a separate question -- see isRetryableException() and the
        // $idempotent flag threaded through call()/callRaw().
        return $response->status() === 0
            || $response->status() >= 500;
    }

    /**
     * Decide whether an exception raised before any response may be retried.
     *
     * Idempotent calls (status/list/download reads) can always be retried: a
     * repeated read has no effect at ANAF, so availability wins.
     *
     * Non-idempotent calls (document uploads) must not be retried blindly. ANAF
     * accepts no idempotency key and mints a fresh index_incarcare for every
     * accepted POST, so re-sending a document that already landed files the same
     * invoice twice -- a duplicate legal submission the recipient also receives
     * twice. We therefore retry only errors that provably occurred *before* the
     * request left this machine, and treat anything we cannot classify as
     * "possibly delivered". A failed upload the caller retries knowingly is far
     * better than a silent duplicate filing.
     */
    protected function isRetryableException(\Throwable $exception, bool $idempotent): bool
    {
        return $idempotent || self::wasNotSent($exception);
    }

    /**
     * Whether a transport failure provably happened before the request was sent.
     *
     * True for the PRE_SEND_CURL_ERRORS, and for a timeout (28) only when the
     * handler context proves the connect phase never finished: no transfer had
     * started (pretransfer_time 0) and no request bytes were issued (request_size
     * 0). A timeout without that evidence may have delivered the request.
     *
     * @internal Also read by AnafAuthenticator for the token request.
     */
    public static function wasNotSent(\Throwable $exception): bool
    {
        $errno = self::extractCurlErrno($exception);

        if ($errno !== null && in_array($errno, static::PRE_SEND_CURL_ERRORS, true)) {
            return true;
        }

        if ($errno !== 28) {
            return false;
        }

        // The timings live on Guzzle's exception, below Laravel's ConnectionException.
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            /** @var mixed $context */
            $context = method_exists($current, 'getHandlerContext') ? $current->getHandlerContext() : null;

            if (is_array($context) && $context !== []) {
                $zero = static fn (mixed $value): bool => (is_int($value) || is_float($value)) && $value == 0;

                return $zero($context['pretransfer_time'] ?? null) && $zero($context['request_size'] ?? null);
            }
        }

        return false;
    }

    /**
     * The exception for a transport failure that got no response: RequestNotSentException
     * only when no attempt of the call may have reached ANAF.
     *
     * @param  array<string, mixed>  $context
     */
    private function transportFailure(\Throwable $exception, array $context): ApiException
    {
        return ! $this->attemptDelivered
            ? new RequestNotSentException($exception->getMessage(), $exception, $context)
            : new ApiException($exception->getMessage(), 500, null, $exception, $context);
    }

    /**
     * Pull the cURL error number out of an exception chain, if there is one.
     *
     * Laravel's ConnectionException wraps Guzzle's ConnectException verbatim
     * (PendingRequest::marshalConnectionException), so the errno is available
     * either from the handler context or from the "cURL error N: ..." message.
     * Both lookups are duck-typed/textual so the SDK keeps no hard dependency on
     * Guzzle.
     *
     * In production the MESSAGE REGEX is the path that fires, not the handler
     * context. The loop starts at the outermost exception -- Laravel's
     * ConnectionException, which has no getHandlerContext() -- and Laravel copies
     * Guzzle's "cURL error N: ..." message onto it verbatim, so the regex matches
     * and returns on the first iteration. getHandlerContext() only exists on the
     * inner Guzzle ConnectException, which the loop would reach on iteration 2 and
     * never does.
     *
     * The handler-context branch is kept deliberately: it is the more precise of the
     * two (a structured int, not a parse of a human-readable string), and it is the
     * fallback if a future Laravel stops copying the message verbatim, or if some
     * other wrapper puts a context-carrying exception outermost. Since this decides
     * whether an upload may be re-sent, a redundant source of truth is worth its
     * keep -- an unclassifiable failure fails closed and blocks the retry.
     *
     * @return int|null The cURL errno, or null when the failure cannot be classified
     */
    private static function extractCurlErrno(\Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (method_exists($current, 'getHandlerContext')) {
                /** @var mixed $context */
                $context = $current->getHandlerContext();

                if (is_array($context) && isset($context['errno']) && is_int($context['errno']) && $context['errno'] > 0) {
                    return $context['errno'];
                }
            }

            if (preg_match('/cURL error (\d+)/', $current->getMessage(), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Hook invoked immediately before each retry attempt.
     *
     * ANAF meters every HTTP request, but a client's quota checks run once per
     * logical call. Subclasses override this to consume a quota unit for the
     * extra request a retry is about to make.
     *
     * @param  bool  $previousAttemptDelivered  Whether the attempt being retried may have reached
     *                                          ANAF: false only when wasNotSent() proves it never left
     */
    protected function onRetryAttempt(bool $previousAttemptDelivered): void
    {
        // No-op by default.
    }

    /**
     * Extract error message from API response.
     *
     * Checks multiple common error keys used by different ANAF endpoints:
     * - 'message': Standard HTTP error message
     * - 'eroare': Romanian error message (used by e-Factura API)
     * - 'error': Standard error key
     * - an XML body's errorMessage (or mesaj), e.g. <Errors errorMessage="..."/>
     */
    protected function extractErrorMessage(Response $response): string
    {
        $data = static::decodeJson($response);
        $data = is_array($data) ? $data : [];

        return self::errorText($data['message'] ?? null)
            ?? self::errorText($data['eroare'] ?? null)
            ?? self::errorText($data['error'] ?? null)
            ?? self::errorText(XmlParser::extractErrorMessage(AnafValue::withoutBom($response->body())))
            ?? self::NO_ERROR_MESSAGE;
    }

    /**
     * An error field's text, or null so the next candidate is read: it counts only when it is
     * non-blank text (same rule as XmlParser), so a blank, boolean or non-scalar value falls
     * through; a literal "0" counts.
     *
     * @internal Also read by AnafAuthenticator for the token endpoint's errors.
     */
    public static function errorText(mixed $value): ?string
    {
        return is_bool($value) || trim((string) AnafValue::text($value)) === '' ? null : AnafValue::text($value);
    }

    /**
     * Decode an ANAF JSON body, keeping integers too big for PHP as strings.
     *
     * Decodes the body directly rather than through Response::json(), which would
     * apply a host-wide default flag set and does not strip a UTF-8 BOM. Without
     * JSON_BIGINT_AS_STRING a big index or id becomes a float that has already lost
     * digits. Callers must check the result is an array: ANAF can answer 2xx with a
     * bare scalar.
     */
    protected static function decodeJson(Response $response): mixed
    {
        return AnafValue::json($response->body());
    }
}
