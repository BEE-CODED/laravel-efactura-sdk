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
                $response = $request->$method($url, $data);
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

        $this->logger->debug(
            sprintf(
                '%s %s. Response %s. Duration: %d ms.',
                strtoupper($method),
                $url,
                $response->status(),
                $timeDiff
            ),
            array_merge(
                $context instanceof Closure ? $context() : $context,
                [
                    'requestBodyLength' => is_string($data) ? strlen($data) : strlen(json_encode($data) ?: ''),
                    'responseBodyLength' => strlen($response->body()),
                ]
            )
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $customHeaders
     *
     * @throws ApiException
     */
    protected function call(
        string $route,
        string $method = 'get',
        array $data = [],
        array $customHeaders = [],
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

            if ($tryCount < $this->getMaxTryCount()) {
                sleep($this->getRetryDelay());

                return $this->call($route, $method, $data, $customHeaders, $tryCount);
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

                return $this->call($route, $method, $data, $customHeaders, $tryCount);
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
     *
     * @throws ApiException
     */
    protected function callRaw(
        string $route,
        string $method,
        string $body,
        string $contentType,
        array $customHeaders = [],
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

            if ($tryCount < $this->getMaxTryCount()) {
                sleep($this->getRetryDelay());

                return $this->callRaw($route, $method, $body, $contentType, $customHeaders, $tryCount);
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

                return $this->callRaw($route, $method, $body, $contentType, $customHeaders, $tryCount);
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
        // Only retry on connection failures (status 0) or server errors (5xx)
        // 429 (rate limit) is NOT retried because:
        // 1. Client-side rate limiting should prevent most 429s
        // 2. Blind retry without Retry-After header is counterproductive
        // 3. If client-side limits are wrong/disabled, failing fast is better
        return $response->status() === 0
            || $response->status() >= 500;
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
