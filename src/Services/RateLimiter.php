<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Services;

use Beecoded\EFactura\Exceptions\RateLimitExceededException;
use Beecoded\EFactura\Services\ApiClients\RateLimitStore;

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
        $this->globalPerMinute = (int) config('efactura.rate_limits.global_per_minute', 500);
        $this->raspUploadPerDayCui = (int) config('efactura.rate_limits.rasp_upload_per_day_cui', 500);
        $this->statusPerDayMessage = (int) config('efactura.rate_limits.status_per_day_message', 50);
        $this->simpleListPerDayCui = (int) config('efactura.rate_limits.simple_list_per_day_cui', 750);
        $this->paginatedListPerDayCui = (int) config('efactura.rate_limits.paginated_list_per_day_cui', 50000);
        $this->downloadPerDayMessage = (int) config('efactura.rate_limits.download_per_day_message', 5);
    }

    /**
     * Check if rate limiting is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('efactura.rate_limits.enabled', true);
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
     * @return array{limit: int, remaining: int, resetsIn: int}
     */
    public function getRemainingQuota(string $type, string $identifier = ''): array
    {
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
            default => throw new \InvalidArgumentException("Unknown rate limit type: {$type}"),
        };
    }
}
