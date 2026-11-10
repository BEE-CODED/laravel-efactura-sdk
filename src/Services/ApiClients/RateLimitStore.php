<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services\ApiClients;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limit counter store using Laravel's native RateLimiter.
 *
 * Supports multiple time windows:
 * - Per-minute (for global API limit)
 * - Per-day (for endpoint-specific limits)
 */
class RateLimitStore
{
    public function __construct(
        private readonly string $prefix,
        private readonly int $decaySeconds,
    ) {}

    /**
     * Count one unit, or refuse when the limit is already reached.
     *
     * Increments first and reads the result, so two workers can never both take
     * the last unit (a check-then-hit could). A refused call is left counted: it only
     * lands in a full window, and increment() does not move the window's expiry, so
     * the window still ends on time and no future quota is spent. (Taking it back
     * via decrement() would re-put the key with a fresh TTL.) Atomic only on a cache
     * store with an atomic increment (redis, memcached, database).
     *
     * @param  string  $key  The rate limit key
     * @param  int  $limit  Maximum allowed attempts
     * @return bool True if the request is allowed (under limit), false if limit exceeded
     */
    public function attemptOrFail(string $key, int $limit): bool
    {
        $cacheKey = $this->buildKey($key);

        return RateLimiter::increment($cacheKey, $this->decaySeconds) <= $limit;
    }

    /**
     * Get remaining quota.
     */
    public function getRemaining(string $key, int $limit): int
    {
        return RateLimiter::remaining($this->buildKey($key), $limit);
    }

    /**
     * Get seconds until the rate limit resets.
     */
    public function availableIn(string $key): int
    {
        return RateLimiter::availableIn($this->buildKey($key));
    }

    private function buildKey(string $key): string
    {
        return "efactura-sdk:{$this->prefix}:{$key}";
    }
}
