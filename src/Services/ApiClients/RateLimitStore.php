<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Services\ApiClients;

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
     * Atomically check limit and increment counter.
     *
     * This uses Laravel's RateLimiter::attempt() for atomic operation,
     * preventing race conditions in concurrent environments.
     *
     * @param  string  $key  The rate limit key
     * @param  int  $limit  Maximum allowed attempts
     * @return bool True if the request is allowed (under limit), false if limit exceeded
     */
    public function attemptOrFail(string $key, int $limit): bool
    {
        $cacheKey = $this->buildKey($key);

        // RateLimiter::attempt() is atomic - it checks AND increments in one operation
        // Returns true if allowed (executed callback), false if rate limited
        return RateLimiter::attempt(
            $cacheKey,
            $limit,
            fn () => true, // Callback executed if allowed
            $this->decaySeconds
        );
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

    /**
     * Clear the rate limit for a key.
     */
    public function clear(string $key): void
    {
        RateLimiter::clear($this->buildKey($key));
    }

    private function buildKey(string $key): string
    {
        return "efactura:{$this->prefix}:{$key}";
    }
}
