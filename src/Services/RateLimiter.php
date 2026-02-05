<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services;

use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\ApiClients\RateLimitStore;
use Illuminate\Support\Facades\Log;

/**
 * Rate limiter for ANAF e-Factura API.
 *
 * Uses Laravel's native RateLimiter with atomic operations to enforce limits at multiple levels:
 * - Global: 500/minute (ANAF limit: 1000)
 * - Per endpoint/CUI/message combinations
 *
 * All defaults are set to 50% of ANAF's actual limits for safety.
 *
 * Note: Uses atomic increment-then-check pattern via RateLimiter::attempt()
 * to prevent race conditions in concurrent environments.
 */
class RateLimiter
{
    private RateLimitStore $minuteStore;

    private RateLimitStore $dailyStore;

    private int $globalPerMinute;

    private int $raspUploadPerDayCui;

    private int $statusPerDayMessage;

    private int $simpleListPerDayCui;

    private int $paginatedListPerDayCui;

    private int $downloadPerDayMessage;

    public function __construct()
    {
        $this->minuteStore = new RateLimitStore('minute', 60);
        $this->dailyStore = new RateLimitStore('daily', 86400); // 24 hours

        // Load from config with conservative defaults (50% of ANAF limits)
        $this->globalPerMinute = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.global_per_minute', 500),
            'global_per_minute'
        );
        $this->raspUploadPerDayCui = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.rasp_upload_per_day_cui', 500),
            'rasp_upload_per_day_cui'
        );
        $this->statusPerDayMessage = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.status_per_day_message', 50),
            'status_per_day_message'
        );
        $this->simpleListPerDayCui = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.simple_list_per_day_cui', 750),
            'simple_list_per_day_cui'
        );
        $this->paginatedListPerDayCui = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.paginated_list_per_day_cui', 50000),
            'paginated_list_per_day_cui'
        );
        $this->downloadPerDayMessage = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.download_per_day_message', 5),
            'download_per_day_message'
        );

        // Warn about cache driver requirements for rate limiting
        $this->validateCacheDriver();
    }

    /**
     * Validate that a rate limit value is positive.
     *
     * @throws \InvalidArgumentException If the limit is zero or negative
     */
    private function validateLimit(int $limit, string $name): int
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException(
                "Rate limit '{$name}' must be a positive integer, got {$limit}. ".
                'Check your efactura-sdk.php config or environment variables.'
            );
        }

        return $limit;
    }

    /**
     * Validate that an identifier is not empty.
     *
     * @throws \InvalidArgumentException If the identifier is empty
     */
    private function validateIdentifier(string $identifier, string $name): void
    {
        if (trim($identifier) === '') {
            throw new \InvalidArgumentException(
                "{$name} cannot be empty for rate limiting"
            );
        }
    }

    /**
     * Validate cache driver is suitable for rate limiting.
     * Logs warnings for drivers that may not work correctly.
     */
    private function validateCacheDriver(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $cacheDriver = config('cache.default');

        // Warn about drivers that don't persist across requests/processes
        if (in_array($cacheDriver, ['null', 'array'], true)) {
            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->warning(
                "EFactura SDK rate limiting may not work correctly with '{$cacheDriver}' cache driver. ".
                'Rate limits will not persist across requests. '.
                'Consider using redis, memcached, or database cache driver for production.',
                ['cache_driver' => $cacheDriver]
            );
        }
    }

    /**
     * Check if rate limiting is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('efactura-sdk.rate_limits.enabled', true);
    }

    /**
     * Check and record a global API call (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkGlobal(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = 'global';

        // Atomic check-and-increment to prevent race conditions
        if (! $this->minuteStore->attemptOrFail($key, $this->globalPerMinute)) {
            throw new RateLimitExceededException(
                "Global rate limit exceeded ({$this->globalPerMinute}/minute). Please wait before retrying.",
                remaining: 0,
                retryAfterSeconds: $this->minuteStore->availableIn($key)
            );
        }
    }

    /**
     * Check RASP upload limit for a CUI (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkRaspUpload(string $cui): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->validateIdentifier($cui, 'CUI');

        $key = "rasp_upload:{$cui}";

        if (! $this->dailyStore->attemptOrFail($key, $this->raspUploadPerDayCui)) {
            throw new RateLimitExceededException(
                "RASP upload limit exceeded for CUI {$cui} ({$this->raspUploadPerDayCui}/day).",
                remaining: 0,
                retryAfterSeconds: $this->dailyStore->availableIn($key)
            );
        }
    }

    /**
     * Check status query limit for a message (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkStatusQuery(string $messageId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->validateIdentifier($messageId, 'message ID');

        $key = "status:{$messageId}";

        if (! $this->dailyStore->attemptOrFail($key, $this->statusPerDayMessage)) {
            throw new RateLimitExceededException(
                "Status query limit exceeded for message {$messageId} ({$this->statusPerDayMessage}/day).",
                remaining: 0,
                retryAfterSeconds: $this->dailyStore->availableIn($key)
            );
        }
    }

    /**
     * Check simple list limit for a CUI (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkSimpleList(string $cui): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->validateIdentifier($cui, 'CUI');

        $key = "list_simple:{$cui}";

        if (! $this->dailyStore->attemptOrFail($key, $this->simpleListPerDayCui)) {
            throw new RateLimitExceededException(
                "Simple list query limit exceeded for CUI {$cui} ({$this->simpleListPerDayCui}/day).",
                remaining: 0,
                retryAfterSeconds: $this->dailyStore->availableIn($key)
            );
        }
    }

    /**
     * Check paginated list limit for a CUI (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkPaginatedList(string $cui): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->validateIdentifier($cui, 'CUI');

        $key = "list_paginated:{$cui}";

        if (! $this->dailyStore->attemptOrFail($key, $this->paginatedListPerDayCui)) {
            throw new RateLimitExceededException(
                "Paginated list query limit exceeded for CUI {$cui} ({$this->paginatedListPerDayCui}/day).",
                remaining: 0,
                retryAfterSeconds: $this->dailyStore->availableIn($key)
            );
        }
    }

    /**
     * Check download limit for a message (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkDownload(string $messageId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->validateIdentifier($messageId, 'message ID');

        $key = "download:{$messageId}";

        if (! $this->dailyStore->attemptOrFail($key, $this->downloadPerDayMessage)) {
            throw new RateLimitExceededException(
                "Download limit exceeded for message {$messageId} ({$this->downloadPerDayMessage}/day).",
                remaining: 0,
                retryAfterSeconds: $this->dailyStore->availableIn($key)
            );
        }
    }

    /**
     * Get remaining quota for various limits.
     *
     * @param  string  $type  The rate limit type ('global', 'rasp_upload', 'status', 'simple_list', 'paginated_list', 'download')
     * @param  string  $identifier  The identifier (CUI or message ID) - required for all types except 'global'
     * @return array{limit: int, remaining: int, resetsIn: int}
     *
     * @throws \InvalidArgumentException If type is unknown or identifier is empty for types that require it
     */
    public function getRemainingQuota(string $type, string $identifier = ''): array
    {
        // Validate type first
        $validTypes = ['global', 'rasp_upload', 'status', 'simple_list', 'paginated_list', 'download'];
        if (! in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException("Unknown rate limit type: {$type}");
        }

        // Validate identifier for types that require it
        if ($type !== 'global' && trim($identifier) === '') {
            throw new \InvalidArgumentException(
                "Identifier is required for rate limit type: {$type}"
            );
        }

        return match ($type) {
            'global' => [
                'limit' => $this->globalPerMinute,
                'remaining' => $this->minuteStore->getRemaining('global', $this->globalPerMinute),
                'resetsIn' => $this->minuteStore->availableIn('global'),
            ],
            'rasp_upload' => [
                'limit' => $this->raspUploadPerDayCui,
                'remaining' => $this->dailyStore->getRemaining("rasp_upload:{$identifier}", $this->raspUploadPerDayCui),
                'resetsIn' => $this->dailyStore->availableIn("rasp_upload:{$identifier}"),
            ],
            'status' => [
                'limit' => $this->statusPerDayMessage,
                'remaining' => $this->dailyStore->getRemaining("status:{$identifier}", $this->statusPerDayMessage),
                'resetsIn' => $this->dailyStore->availableIn("status:{$identifier}"),
            ],
            'simple_list' => [
                'limit' => $this->simpleListPerDayCui,
                'remaining' => $this->dailyStore->getRemaining("list_simple:{$identifier}", $this->simpleListPerDayCui),
                'resetsIn' => $this->dailyStore->availableIn("list_simple:{$identifier}"),
            ],
            'paginated_list' => [
                'limit' => $this->paginatedListPerDayCui,
                'remaining' => $this->dailyStore->getRemaining("list_paginated:{$identifier}", $this->paginatedListPerDayCui),
                'resetsIn' => $this->dailyStore->availableIn("list_paginated:{$identifier}"),
            ],
            'download' => [
                'limit' => $this->downloadPerDayMessage,
                'remaining' => $this->dailyStore->getRemaining("download:{$identifier}", $this->downloadPerDayMessage),
                'resetsIn' => $this->dailyStore->availableIn("download:{$identifier}"),
            ],
        };
    }
}
