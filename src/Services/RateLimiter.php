<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services;

use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\ApiClients\RateLimitStore;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Support\Facades\Log;

/**
 * Rate limiter for ANAF e-Factura API.
 *
 * Uses Laravel's native RateLimiter counters to enforce limits at multiple levels:
 * global, and per endpoint/CUI/message combinations.
 *
 * The defaults, the fixed windows and the invalid-value rule are described once, in
 * the rate_limits comment of config/efactura-sdk.php.
 *
 * Note: counts with increment-then-check (RateLimitStore::attemptOrFail()), so
 * concurrent workers cannot both take the last unit on a cache store with an atomic
 * increment. A refused increment stays counted in its already-full window, which still
 * ends on time; per-CUI/per-message checks peek first so a refusal there counts nothing.
 */
class RateLimiter
{
    private RateLimitStore $minuteStore;

    private RateLimitStore $dailyStore;

    private RateLimitStore $secondStore;

    private int $globalPerMinute;

    private int $raspUploadPerDayCui;

    private int $statusPerDayMessage;

    private int $statusPerDayMessageProvider;

    private int $simpleListPerDayCui;

    private int $paginatedListPerDayCui;

    private int $downloadPerDayMessage;

    private int $companyLookupPerSecond;

    public function __construct()
    {
        $this->minuteStore = new RateLimitStore('minute', 60);
        $this->dailyStore = new RateLimitStore('daily', 86400); // 24 hours
        // shortcut: a 2 s window holds 1/s on whole-second stores but halves Redis throughput; pick the window per store if that matters.
        $this->secondStore = new RateLimitStore('second', 2);

        // Load from config with conservative defaults (50% of ANAF limits,
        // except company_lookup_per_second - see the class docblock)
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
        // ANAF's own ceiling, for reads that opt in; the code default keeps older published configs working.
        $this->statusPerDayMessageProvider = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.status_per_day_message_provider', 100),
            'status_per_day_message_provider'
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
        $this->companyLookupPerSecond = $this->validateLimit(
            (int) config('efactura-sdk.rate_limits.company_lookup_per_second', 1),
            'company_lookup_per_second'
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
     * Warn when the store behind Laravel's rate limiter (cache.limiter, else cache.default)
     * cannot hold the limits across processes.
     */
    private function validateCacheDriver(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        // Judge the limiter's own store by its driver, not the default store by its name.
        $store = config('cache.limiter') ?? config('cache.default');
        $driver = config("cache.stores.{$store}.driver", $store);

        // null/array are not shared between processes; file increments without a lock, so concurrent workers can overshoot.
        if (in_array($driver, ['null', 'array', 'file'], true)) {
            try {
                Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->warning(
                    "EFactura SDK rate limits may not hold on the '{$store}' cache store ({$driver} driver): ".
                    'its counters are not shared or not incremented atomically across processes. '.
                    'Use a redis, memcached or database store for production.',
                    ['cache_store' => $store, 'cache_driver' => $driver]
                );
            } catch (\Throwable) {
                // A failing log handler must never stop the client from being built.
            }
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
                // A window in its last fraction of a second reports availableIn=0; never return a 0 wait.
                retryAfterSeconds: max($this->minuteStore->availableIn($key), 1),
                scope: RateLimitScope::Global,
            );
        }
    }

    /**
     * Check and record a company-lookup API call (atomic, at most 1/second).
     *
     * ANAF limits the public company-details endpoint (PlatitorTvaRest) to
     * 1 request/second. Unlike the per-CUI/per-message limits, this is a
     * single global bucket — the limit is per request, not per CUI.
     *
     * Counted over a 2-second window: a key that a whole-second store expires at
     * x+1 would let a lookup at x.999 be followed by another at x+1.001.
     *
     * @throws RateLimitExceededException
     */
    public function checkCompanyLookup(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $key = 'company_lookup';

        if (! $this->secondStore->attemptOrFail($key, $this->companyLookupPerSecond)) {
            throw new RateLimitExceededException(
                "Company lookup rate limit exceeded ({$this->companyLookupPerSecond} per 2 seconds). Please wait before retrying.",
                remaining: 0,
                // A window in its last fraction of a second reports availableIn=0; never return a 0 wait.
                retryAfterSeconds: max($this->secondStore->availableIn($key), 1),
                scope: RateLimitScope::CompanyLookup,
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
        $this->attemptEndpoint(RateLimitScope::RaspUpload, $cui);
    }

    /**
     * Check status query limit for a message (atomic operation).
     *
     * @param  bool  $useProviderLimit  Check the same per-message counter against ANAF's own
     *                                  ceiling (status_per_day_message_provider, default 100)
     *                                  instead of status_per_day_message
     *
     * @throws RateLimitExceededException
     */
    public function checkStatusQuery(string $messageId, bool $useProviderLimit = false): void
    {
        $this->attemptEndpoint(RateLimitScope::Status, $messageId, $useProviderLimit);
    }

    /**
     * Check simple list limit for a CUI (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkSimpleList(string $cui): void
    {
        $this->attemptEndpoint(RateLimitScope::SimpleList, $cui);
    }

    /**
     * Check paginated list limit for a CUI (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkPaginatedList(string $cui): void
    {
        $this->attemptEndpoint(RateLimitScope::PaginatedList, $cui);
    }

    /**
     * Check download limit for a message (atomic operation).
     *
     * @throws RateLimitExceededException
     */
    public function checkDownload(string $messageId): void
    {
        $this->attemptEndpoint(RateLimitScope::Download, $messageId);
    }

    /**
     * Refuse, counting nothing, when a per-CUI or per-message limit has no room left.
     *
     * EFacturaClient calls this before it counts the global unit, so a call refused by
     * its own per-CUI or per-message limit spends no global quota either.
     *
     * @param  RateLimitScope  $scope  RaspUpload, Status, SimpleList, PaginatedList or Download
     * @param  bool  $useProviderLimit  For Status only, as in checkStatusQuery()
     *
     * @throws RateLimitExceededException When the limit is reached
     * @throws \InvalidArgumentException When the identifier is empty or the scope is a single bucket
     */
    public function ensureAvailable(RateLimitScope $scope, string $identifier, bool $useProviderLimit = false): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        [$key, $limit, $message] = $this->endpointBucket($scope, $identifier, $useProviderLimit);

        if ($this->dailyStore->getRemaining($key, $limit) <= 0) {
            $this->refuseEndpoint($scope, $key, $message);
        }
    }

    /**
     * Count one unit of a per-CUI or per-message limit, or refuse without counting.
     *
     * @throws RateLimitExceededException
     */
    private function attemptEndpoint(RateLimitScope $scope, string $identifier, bool $useProviderLimit = false): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        [$key, $limit, $message] = $this->endpointBucket($scope, $identifier, $useProviderLimit);

        // Peek first: the status counter is shared by two limits, so a unit counted while
        // refused at the lower one would spend the higher one. Only two workers racing
        // for the lower limit's last unit can still leave the loser's unit counted.
        if ($this->dailyStore->getRemaining($key, $limit) <= 0
            || ! $this->dailyStore->attemptOrFail($key, $limit)) {
            $this->refuseEndpoint($scope, $key, $message);
        }
    }

    /**
     * The daily counter key, limit and refusal message of a per-CUI or per-message limit.
     *
     * @return array{0: string, 1: int, 2: string}
     *
     * @throws \InvalidArgumentException When the identifier is empty or the scope is a single bucket
     */
    private function endpointBucket(RateLimitScope $scope, string $identifier, bool $useProviderLimit): array
    {
        $statusLimit = $useProviderLimit ? $this->statusPerDayMessageProvider : $this->statusPerDayMessage;

        // The client meters a per-CUI limit on the canonical CUI, so every spelling of it shares that counter.
        if (in_array($scope, [RateLimitScope::RaspUpload, RateLimitScope::SimpleList, RateLimitScope::PaginatedList], true)) {
            $identifier = VatNumberValidator::canonical($identifier) ?? $identifier;
        }

        [$label, $key, $limit, $message] = match ($scope) {
            RateLimitScope::RaspUpload => ['CUI', "rasp_upload:{$identifier}", $this->raspUploadPerDayCui,
                "RASP upload limit exceeded for CUI {$identifier} ({$this->raspUploadPerDayCui}/day)."],
            RateLimitScope::Status => ['message ID', "status:{$identifier}", $statusLimit,
                "Status query limit exceeded for message {$identifier} ({$statusLimit}/day)."],
            RateLimitScope::SimpleList => ['CUI', "list_simple:{$identifier}", $this->simpleListPerDayCui,
                "Simple list query limit exceeded for CUI {$identifier} ({$this->simpleListPerDayCui}/day)."],
            RateLimitScope::PaginatedList => ['CUI', "list_paginated:{$identifier}", $this->paginatedListPerDayCui,
                "Paginated list query limit exceeded for CUI {$identifier} ({$this->paginatedListPerDayCui}/day)."],
            RateLimitScope::Download => ['message ID', "download:{$identifier}", $this->downloadPerDayMessage,
                "Download limit exceeded for message {$identifier} ({$this->downloadPerDayMessage}/day)."],
            RateLimitScope::Global, RateLimitScope::CompanyLookup => throw new \InvalidArgumentException(
                "Rate limit '{$scope->value}' is a single bucket, not a per-CUI or per-message limit."
            ),
        };

        $this->validateIdentifier($identifier, $label);

        return [$key, $limit, $message];
    }

    /**
     * @throws RateLimitExceededException Always
     */
    private function refuseEndpoint(RateLimitScope $scope, string $key, string $message): never
    {
        throw new RateLimitExceededException(
            $message,
            remaining: 0,
            // A window in its last fraction of a second reports availableIn=0; never return a 0 wait.
            retryAfterSeconds: max($this->dailyStore->availableIn($key), 1),
            scope: $scope,
        );
    }

    /**
     * Get remaining quota for various limits.
     *
     * @param  string  $type  The rate limit type ('global', 'company_lookup', 'rasp_upload', 'status', 'simple_list', 'paginated_list', 'download')
     * @param  string  $identifier  The identifier (CUI or message ID) - required for all types
     *                              except 'global' and 'company_lookup', which are single buckets;
     *                              a CUI is read as its canonical digits (spaces and an RO prefix ignored)
     * @param  bool  $useProviderLimit  For 'status' only: report the same counter against ANAF's own
     *                                  ceiling (status_per_day_message_provider); other types ignore it
     * @return array{limit: int, remaining: int, resetsIn: int}
     *
     * @throws \InvalidArgumentException If type is unknown or identifier is empty for types that require it
     */
    public function getRemainingQuota(string $type, string $identifier = '', bool $useProviderLimit = false): array
    {
        $scope = RateLimitScope::tryFrom($type) ?? throw new \InvalidArgumentException("Unknown rate limit type: {$type}");

        // 'global' and 'company_lookup' are single buckets: the limit is per request, not per CUI or message.
        if ($scope === RateLimitScope::Global) {
            return [
                'limit' => $this->globalPerMinute,
                'remaining' => $this->minuteStore->getRemaining('global', $this->globalPerMinute),
                'resetsIn' => $this->minuteStore->availableIn('global'),
            ];
        }

        if ($scope === RateLimitScope::CompanyLookup) {
            return [
                'limit' => $this->companyLookupPerSecond,
                'remaining' => $this->secondStore->getRemaining('company_lookup', $this->companyLookupPerSecond),
                'resetsIn' => $this->secondStore->availableIn('company_lookup'),
            ];
        }

        if (trim($identifier) === '') {
            throw new \InvalidArgumentException("Identifier is required for rate limit type: {$type}");
        }

        // The same key and limit the check spends, so the two can never diverge.
        [$key, $limit] = $this->endpointBucket($scope, $identifier, $useProviderLimit);

        return [
            'limit' => $limit,
            'remaining' => $this->dailyStore->getRemaining($key, $limit),
            'resetsIn' => $this->dailyStore->availableIn($key),
        ];
    }
}
