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
    it('throws exception when message limit exceeded', function () {
        config(['efactura-sdk.rate_limits.status_per_day_message' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkStatusQuery('MSG123');
        $limiter->checkStatusQuery('MSG123');
    })->throws(RateLimitExceededException::class, 'Status query limit exceeded');
});

describe('checkSimpleList', function () {
    it('throws exception when CUI limit exceeded', function () {
        config(['efactura-sdk.rate_limits.simple_list_per_day_cui' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkSimpleList('12345678');
        $limiter->checkSimpleList('12345678');
    })->throws(RateLimitExceededException::class, 'Simple list query limit exceeded');
});

describe('checkPaginatedList', function () {
    it('throws exception when CUI limit exceeded', function () {
        config(['efactura-sdk.rate_limits.paginated_list_per_day_cui' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkPaginatedList('12345678');
        $limiter->checkPaginatedList('12345678');
    })->throws(RateLimitExceededException::class, 'Paginated list query limit exceeded');
});

describe('checkDownload', function () {
    it('throws exception when message limit exceeded', function () {
        config(['efactura-sdk.rate_limits.download_per_day_message' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkDownload('MSG123');
        $limiter->checkDownload('MSG123');
    })->throws(RateLimitExceededException::class, 'Download limit exceeded');
});

describe('limit validation', function () {
    it('throws exception for zero global_per_minute limit', function () {
        config(['efactura-sdk.rate_limits.global_per_minute' => 0]);
        new RateLimiter;
    })->throws(InvalidArgumentException::class, "Rate limit 'global_per_minute' must be a positive integer, got 0");

    it('throws exception for negative global_per_minute limit', function () {
        config(['efactura-sdk.rate_limits.global_per_minute' => -10]);
        new RateLimiter;
    })->throws(InvalidArgumentException::class, "Rate limit 'global_per_minute' must be a positive integer, got -10");

    it('throws exception for zero rasp_upload_per_day_cui limit', function () {
        config(['efactura-sdk.rate_limits.rasp_upload_per_day_cui' => 0]);
        new RateLimiter;
    })->throws(InvalidArgumentException::class, "Rate limit 'rasp_upload_per_day_cui' must be a positive integer, got 0");
});

describe('empty identifier validation', function () {
    it('throws exception for empty CUI in checkRaspUpload', function () {
        $limiter = new RateLimiter;
        $limiter->checkRaspUpload('');
    })->throws(InvalidArgumentException::class, 'CUI cannot be empty for rate limiting');

    it('throws exception for whitespace-only CUI in checkRaspUpload', function () {
        $limiter = new RateLimiter;
        $limiter->checkRaspUpload('   ');
    })->throws(InvalidArgumentException::class, 'CUI cannot be empty for rate limiting');

    it('throws exception for empty message ID in checkStatusQuery', function () {
        $limiter = new RateLimiter;
        $limiter->checkStatusQuery('');
    })->throws(InvalidArgumentException::class, 'message ID cannot be empty for rate limiting');

    it('throws exception for empty CUI in checkSimpleList', function () {
        $limiter = new RateLimiter;
        $limiter->checkSimpleList('');
    })->throws(InvalidArgumentException::class, 'CUI cannot be empty for rate limiting');

    it('throws exception for empty CUI in checkPaginatedList', function () {
        $limiter = new RateLimiter;
        $limiter->checkPaginatedList('');
    })->throws(InvalidArgumentException::class, 'CUI cannot be empty for rate limiting');

    it('throws exception for empty message ID in checkDownload', function () {
        $limiter = new RateLimiter;
        $limiter->checkDownload('');
    })->throws(InvalidArgumentException::class, 'message ID cannot be empty for rate limiting');
});

describe('getRemainingQuota', function () {
    it('returns quota info with correct structure and limits', function (string $type, ?string $identifier, int $expectedLimit) {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota($type, $identifier ?? '');

        expect($quota)->toHaveKeys(['limit', 'remaining', 'resetsIn']);
        expect($quota['limit'])->toBe($expectedLimit);
    })->with([
        'global' => ['global', null, 500],
        'rasp_upload' => ['rasp_upload', '12345678', 500],
        'status' => ['status', 'MSG123', 50],
        'simple_list' => ['simple_list', '12345678', 750],
        'paginated_list' => ['paginated_list', '12345678', 50000],
        'download' => ['download', 'MSG123', 5],
    ]);

    it('throws exception for unknown type', function () {
        $limiter = new RateLimiter;

        $limiter->getRemainingQuota('unknown');
    })->throws(InvalidArgumentException::class, 'Unknown rate limit type');

    it('throws exception for empty identifier on non-global types', function (string $type) {
        $limiter = new RateLimiter;

        $limiter->getRemainingQuota($type, '');
    })->with([
        'rasp_upload',
        'status',
        'simple_list',
        'paginated_list',
        'download',
    ])->throws(InvalidArgumentException::class, 'Identifier is required for rate limit type');

    it('throws exception for whitespace-only identifier', function () {
        $limiter = new RateLimiter;

        $limiter->getRemainingQuota('download', '   ');
    })->throws(InvalidArgumentException::class, 'Identifier is required for rate limit type: download');

    it('allows empty identifier for global type', function () {
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('global', '');

        expect($quota)->toHaveKeys(['limit', 'remaining', 'resetsIn']);
    });
});
