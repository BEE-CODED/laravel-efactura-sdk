<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use Closure;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

abstract class BaseApiClient implements HasLogger
{
    protected LoggerInterface $logger;

    protected const int MAX_TRY_COUNT = 3;

    protected const int RETRY_DELAY = 5;

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
     * therefore not proof of anything; the errno is.
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

    public function __construct()
    {
        $this->logger = static::getLogger();
    }

    abstract public static function getBaseUrl(): string;

    abstract public static function getTimeoutDuration(): float|int;

    /**
     * @return array<string, string>
     */
    abstract protected function getHeaders(): array;

    /**
     * Get the retry delay in seconds.
     * Can be overridden in subclasses for custom behavior.
     */
    protected function getRetryDelay(): int
    {
        return static::RETRY_DELAY;
    }

    /**
     * Get the max retry count.
     * Can be overridden in subclasses for custom behavior.
     */
    protected function getMaxTryCount(): int
    {
        return static::MAX_TRY_COUNT;
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [];
    }

    public function getRequestUrl(string $route): string
    {
        $baseUrl = rtrim(static::getBaseUrl(), '/');
        $route = ltrim($route, '/');

        return $baseUrl.'/'.$route;
    }

    /**
     * @param  array<string, mixed>|string  $data
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|Closure  $context
     *
     * @throws ConnectionException
     */
    protected function request(
        string $route,
        array|string $data = [],
        string $method = 'get',
        array $headers = [],
        array|Closure $context = [],
        ?string $contentType = null
    ): Response {
        $url = $this->getRequestUrl($route);

        $startTime = Carbon::now();
        $request = Http::timeout(static::getTimeoutDuration());

        $asMultipart = false;
        if (isset($headers['Content-Type']) && Str::startsWith($headers['Content-Type'], 'multipart/form-data')) {
            $asMultipart = true;
            unset($headers['Content-Type']);
        }

        $request->withHeaders($this->getDefaultHeaders() + $headers);

        if ($contentType) {
            // When contentType is provided, data must be a string (raw body)
            assert(is_string($data), 'Data must be a string when contentType is provided');
            $response = $request->withBody($data, $contentType)->$method($url);
        } else {
            if (strtolower($method) === 'get') {
                // When $data is empty, call with only the URL to avoid Guzzle
                // overwriting URL query params with an empty query string.
                // Laravel's PendingRequest::get($url, []) passes ['query' => []]
                // to Guzzle, which builds '' and calls $uri->withQuery(''),
                // stripping any query params already embedded in the URL.
                $response = empty($data)
                    ? $request->$method($url)
                    : $request->$method($url, $data);
            } else {
                if ($asMultipart) {
                    $response = $request->asMultipart()->$method($url, $data);
                } elseif (isset($headers['Content-Type']) && $headers['Content-Type'] === 'application/x-www-form-urlencoded') {
                    $response = $request->asForm()->$method($url, $data);
                } else {
                    $response = $request->$method($url, $data);
                }
            }
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
            $logContext['requestHeaders'] = $this->getDefaultHeaders() + $headers;
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
        array $customHeaders = [],
        bool $idempotent = true,
        int $tryCount = 0
    ): Response {
        $tryCount++;
        $startTime = Carbon::now();
        $context = fn () => [
            'route' => $route,
            'data' => $data,
            'duration' => $this->lastRequestDurationMilliseconds,
            'tryCount' => $tryCount,
        ];

        try {
            $response = $this->request(
                $route,
                $data,
                $method,
                empty($customHeaders) ? $this->getHeaders() : $customHeaders,
                $context
            );
        } catch (Exception $exception) {
            // Calculate duration for failed requests so logging is accurate
            $this->lastRequestDurationMilliseconds = $startTime->diffInMilliseconds(Carbon::now());

            $this->logger->error(
                "Exception before response was received: {$exception->getMessage()}.",
                $context()
            );

            if ($tryCount < $this->getMaxTryCount() && $this->isRetryableException($exception, $idempotent)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt();

                return $this->call($route, $method, $data, $customHeaders, $idempotent, $tryCount);
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
            // A response means the request was delivered and ANAF acted on it, so a
            // non-idempotent call must never be re-sent -- even on a 5xx.
            if ($tryCount < $this->getMaxTryCount() && $idempotent && $this->isRetryable($response)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt();

                return $this->call($route, $method, $data, $customHeaders, $idempotent, $tryCount);
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
        array $customHeaders = [],
        bool $idempotent = true,
        int $tryCount = 0
    ): Response {
        $tryCount++;
        $startTime = Carbon::now();
        $context = fn () => [
            'route' => $route,
            'bodyLength' => strlen($body),
            'contentType' => $contentType,
            'duration' => $this->lastRequestDurationMilliseconds,
            'tryCount' => $tryCount,
        ];

        try {
            $response = $this->request(
                $route,
                $body,
                $method,
                empty($customHeaders) ? $this->getHeaders() : $customHeaders,
                $context,
                $contentType
            );
        } catch (Exception $exception) {
            // Calculate duration for failed requests so logging is accurate
            $this->lastRequestDurationMilliseconds = $startTime->diffInMilliseconds(Carbon::now());

            $this->logger->error(
                "Exception before response was received: {$exception->getMessage()}.",
                $context()
            );

            if ($tryCount < $this->getMaxTryCount() && $this->isRetryableException($exception, $idempotent)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt();

                return $this->callRaw($route, $method, $body, $contentType, $customHeaders, $idempotent, $tryCount);
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
            // A response means the request was delivered and ANAF acted on it, so a
            // non-idempotent call must never be re-sent -- even on a 5xx.
            if ($tryCount < $this->getMaxTryCount() && $idempotent && $this->isRetryable($response)) {
                sleep($this->getRetryDelay());
                $this->onRetryAttempt();

                return $this->callRaw($route, $method, $body, $contentType, $customHeaders, $idempotent, $tryCount);
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
        if ($idempotent) {
            return true;
        }

        $errno = $this->extractCurlErrno($exception);

        return $errno !== null && in_array($errno, static::PRE_SEND_CURL_ERRORS, true);
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
    private function extractCurlErrno(\Throwable $exception): ?int
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
     */
    protected function onRetryAttempt(): void
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
     */
    protected function extractErrorMessage(Response $response): string
    {
        return $response->json('message')
            ?? $response->json('eroare')
            ?? $response->json('error')
            ?? 'No error message in API response';
    }
}
