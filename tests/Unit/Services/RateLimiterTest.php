<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;

beforeEach(function () {
    // Clear any existing rate limits before each test
    LaravelRateLimiter::clear('efactura-sdk:minute:global');
    LaravelRateLimiter::clear('efactura-sdk:daily:rasp_upload:12345678');
    LaravelRateLimiter::clear('efactura-sdk:daily:status:MSG123');
    LaravelRateLimiter::clear('efactura-sdk:daily:list_simple:12345678');
    LaravelRateLimiter::clear('efactura-sdk:daily:list_paginated:12345678');
    LaravelRateLimiter::clear('efactura-sdk:daily:download:MSG123');
});

describe('isEnabled', function () {
    it('returns true by default', function () {
        $limiter = new RateLimiter;

        expect($limiter->isEnabled())->toBeTrue();
    });

    it('returns false when disabled in config', function () {
        config(['efactura-sdk.rate_limits.enabled' => false]);
        $limiter = new RateLimiter;

        expect($limiter->isEnabled())->toBeFalse();

        config(['efactura-sdk.rate_limits.enabled' => true]);
    });
});

describe('checkGlobal', function () {
    it('allows requests under limit', function () {
        $limiter = new RateLimiter;

        expect(fn () => $limiter->checkGlobal())->not->toThrow(RateLimitExceededException::class);
    });

    it('throws exception when limit exceeded', function () {
        config(['efactura-sdk.rate_limits.global_per_minute' => 1]);
        $limiter = new RateLimiter;

        // First request should pass
        $limiter->checkGlobal();

        // Second request should fail
        $limiter->checkGlobal();
    })->throws(RateLimitExceededException::class, 'Global rate limit exceeded');

    it('does not check when rate limiting is disabled', function () {
        config(['efactura-sdk.rate_limits.enabled' => false, 'efactura.rate_limits.global_per_minute' => 1]);
        $limiter = new RateLimiter;

        // Should not throw even if we exceed limit
        $limiter->checkGlobal();
        $limiter->checkGlobal();

        config(['efactura-sdk.rate_limits.enabled' => true]);

        expect(true)->toBeTrue(); // If we reach here, test passes
    });
});

describe('checkRaspUpload', function () {
    it('allows uploads under limit', function () {
        $limiter = new RateLimiter;

        expect(fn () => $limiter->checkRaspUpload('12345678'))->not->toThrow(RateLimitExceededException::class);
    });

    it('throws exception when CUI limit exceeded', function () {
        config(['efactura-sdk.rate_limits.rasp_upload_per_day_cui' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkRaspUpload('12345678');
        $limiter->checkRaspUpload('12345678');
    })->throws(RateLimitExceededException::class, 'RASP upload limit exceeded');

    it('tracks per CUI independently', function () {
        config(['efactura-sdk.rate_limits.rasp_upload_per_day_cui' => 1]);
        $limiter = new RateLimiter;

        // Different CUIs should have separate limits
        $limiter->checkRaspUpload('11111111');
        $limiter->checkRaspUpload('22222222');

        expect(true)->toBeTrue();
    });
});

describe('checkStatusQuery', function () {
    it('allows queries under limit', function () {
        $limiter = new RateLimiter;

        expect(fn () => $limiter->checkStatusQuery('MSG123'))->not->toThrow(RateLimitExceededException::class);
    });

    it('throws exception when message limit exceeded', function () {
        config(['efactura-sdk.rate_limits.status_per_day_message' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkStatusQuery('MSG123');
        $limiter->checkStatusQuery('MSG123');
    })->throws(RateLimitExceededException::class, 'Status query limit exceeded');
});

describe('checkSimpleList', function () {
    it('allows queries under limit', function () {
        $limiter = new RateLimiter;

        expect(fn () => $limiter->checkSimpleList('12345678'))->not->toThrow(RateLimitExceededException::class);
    });

    it('throws exception when CUI limit exceeded', function () {
        config(['efactura-sdk.rate_limits.simple_list_per_day_cui' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkSimpleList('12345678');
        $limiter->checkSimpleList('12345678');
    })->throws(RateLimitExceededException::class, 'Simple list query limit exceeded');
});

describe('checkPaginatedList', function () {
    it('allows queries under limit', function () {
        $limiter = new RateLimiter;

        expect(fn () => $limiter->checkPaginatedList('12345678'))->not->toThrow(RateLimitExceededException::class);
    });

    it('throws exception when CUI limit exceeded', function () {
        config(['efactura-sdk.rate_limits.paginated_list_per_day_cui' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkPaginatedList('12345678');
        $limiter->checkPaginatedList('12345678');
    })->throws(RateLimitExceededException::class, 'Paginated list query limit exceeded');
});

describe('checkDownload', function () {
    it('allows downloads under limit', function () {
        $limiter = new RateLimiter;

        expect(fn () => $limiter->checkDownload('MSG123'))->not->toThrow(RateLimitExceededException::class);
    });

    it('throws exception when message limit exceeded', function () {
        config(['efactura-sdk.rate_limits.download_per_day_message' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkDownload('MSG123');
        $limiter->checkDownload('MSG123');
    })->throws(RateLimitExceededException::class, 'Download limit exceeded');
});

describe('getRemainingQuota', function () {
    it('returns global quota info', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('global');

        expect($quota)->toHaveKeys(['limit', 'remaining', 'resetsIn']);
        expect($quota['limit'])->toBe(500); // Default limit
    });

    it('returns rasp_upload quota info', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('rasp_upload', '12345678');

        expect($quota)->toHaveKeys(['limit', 'remaining', 'resetsIn']);
        expect($quota['limit'])->toBe(500);
    });

    it('returns status quota info', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('status', 'MSG123');

        expect($quota['limit'])->toBe(50);
    });

    it('returns simple_list quota info', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('simple_list', '12345678');

        expect($quota['limit'])->toBe(750);
    });

    it('returns paginated_list quota info', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('paginated_list', '12345678');

        expect($quota['limit'])->toBe(50000);
    });

    it('returns download quota info', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('download', 'MSG123');

        expect($quota['limit'])->toBe(5);
    });

    it('throws exception for unknown type', function () {
        $limiter = new RateLimiter;

        $limiter->getRemainingQuota('unknown');
    })->throws(InvalidArgumentException::class, 'Unknown rate limit type');
});
