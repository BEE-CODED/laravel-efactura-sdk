<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Enums\RateLimitScope;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Services\RateLimiter;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Clear any existing rate limits before each test
    LaravelRateLimiter::clear('efactura-sdk:minute:global');
    LaravelRateLimiter::clear('efactura-sdk:daily:rasp_upload:12345678');
    LaravelRateLimiter::clear('efactura-sdk:daily:status:MSG123');
    LaravelRateLimiter::clear('efactura-sdk:daily:list_simple:12345678');
    LaravelRateLimiter::clear('efactura-sdk:daily:list_paginated:12345678');
    LaravelRateLimiter::clear('efactura-sdk:daily:download:MSG123');
    LaravelRateLimiter::clear('efactura-sdk:second:company_lookup');
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
        config(['efactura-sdk.rate_limits.enabled' => false, 'efactura-sdk.rate_limits.global_per_minute' => 1]);
        $limiter = new RateLimiter;

        // Neither refused past the limit nor counted
        $limiter->checkGlobal();
        $limiter->checkGlobal();

        expect(LaravelRateLimiter::attempts('efactura-sdk:minute:global'))->toBe(0);
    });
});

describe('refused calls', function () {
    it('keeps the window ending on time after a refused call', function () {
        // Regression: taking a refused unit back went through increment(-1), which
        // re-put the key with a fresh TTL, so steady traffic was refused forever.
        config(['efactura-sdk.rate_limits.company_lookup_per_second' => 1]);
        $limiter = new RateLimiter;
        $this->freezeTime();

        $limiter->checkCompanyLookup();

        $this->travel(900)->milliseconds();
        expect(fn () => $limiter->checkCompanyLookup())->toThrow(RateLimitExceededException::class);

        $this->travel(1200)->milliseconds();
        $limiter->checkCompanyLookup();

        expect(LaravelRateLimiter::attempts('efactura-sdk:second:company_lookup'))->toBe(1);
    });

    it('lets the next window allow exactly the limit after refused calls', function () {
        config(['efactura-sdk.rate_limits.global_per_minute' => 2]);
        $limiter = new RateLimiter;
        $this->freezeTime();
        $limiter->checkGlobal();
        $limiter->checkGlobal();
        expect(fn () => $limiter->checkGlobal())->toThrow(RateLimitExceededException::class);

        $this->travel(61)->seconds();
        $limiter->checkGlobal();
        $limiter->checkGlobal();

        expect(fn () => $limiter->checkGlobal())->toThrow(RateLimitExceededException::class);
    });

    it('refuses the last unit when another worker takes it first', function () {
        // A check-then-hit (RateLimiter::attempt) reads limit-1, lets the other worker
        // count, then counts too: both get the last unit. Increment-first sees limit+1.
        config(['efactura-sdk.rate_limits.global_per_minute' => 3]);
        $key = 'efactura-sdk:minute:global';
        LaravelRateLimiter::increment($key, 60, 2);

        // The other worker's increment lands just before this call's own.
        $racing = new class(Cache::store()->getStore(), $key) extends Repository
        {
            private bool $raced = false;

            public function __construct(Store $store, private readonly string $counterKey)
            {
                parent::__construct($store);
            }

            public function increment($key, $value = 1)
            {
                if (! $this->raced && $key === $this->counterKey) {
                    $this->raced = true;
                    parent::increment($key);
                }

                return parent::increment($key, $value);
            }
        };
        LaravelRateLimiter::swap(new CacheRateLimiter($racing));

        expect(fn () => (new RateLimiter)->checkGlobal())->toThrow(RateLimitExceededException::class)
            ->and(LaravelRateLimiter::attempts($key))->toBe(4);
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

    it('neither refuses nor counts a full per-CUI limit when disabled', function () {
        config(['efactura-sdk.rate_limits.enabled' => false, 'efactura-sdk.rate_limits.rasp_upload_per_day_cui' => 1]);
        $key = 'efactura-sdk:daily:rasp_upload:12345678';
        LaravelRateLimiter::increment($key, 86400); // the bucket is full
        $limiter = new RateLimiter;

        $limiter->checkRaspUpload('12345678');
        $limiter->ensureAvailable(RateLimitScope::RaspUpload, '12345678');

        expect(LaravelRateLimiter::attempts($key))->toBe(1);
    });
});

describe('checkStatusQuery', function () {
    it('throws exception when message limit exceeded', function () {
        config(['efactura-sdk.rate_limits.status_per_day_message' => 1]);
        $limiter = new RateLimiter;

        $limiter->checkStatusQuery('MSG123');
        $limiter->checkStatusQuery('MSG123');
    })->throws(RateLimitExceededException::class, 'Status query limit exceeded');

    it('refuses the 51st read at the SDK limit but allows ANAF\'s 100 with the provider limit', function () {
        $limiter = new RateLimiter;

        for ($i = 0; $i < 50; $i++) {
            $limiter->checkStatusQuery('MSG123');
        }

        expect(fn () => $limiter->checkStatusQuery('MSG123'))
            ->toThrow(RateLimitExceededException::class, '(50/day)');

        // Same counter: reads 51-100 pass only against the provider limit.
        for ($i = 50; $i < 100; $i++) {
            $limiter->checkStatusQuery('MSG123', true);
        }

        expect(fn () => $limiter->checkStatusQuery('MSG123', true))
            ->toThrow(RateLimitExceededException::class, '(100/day)');
    });
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

    it('reports the status quota against ANAF\'s 100 on the same counter when asked', function () {
        $this->freezeTime();
        $limiter = new RateLimiter;

        for ($i = 0; $i < 60; $i++) {
            $limiter->checkStatusQuery('MSG123', true);
        }

        expect($limiter->getRemainingQuota('status', 'MSG123'))
            ->toBe(['limit' => 50, 'remaining' => 0, 'resetsIn' => 86400])
            ->and($limiter->getRemainingQuota('status', 'MSG123', true))
            ->toBe(['limit' => 100, 'remaining' => 40, 'resetsIn' => 86400]);
    });

    it('reads the provider limit from config, defaulting to 100 for a config published before it existed', function () {
        config(['efactura-sdk.rate_limits.status_per_day_message_provider' => 80]);

        expect((new RateLimiter)->getRemainingQuota('status', 'MSG123', true)['limit'])->toBe(80);

        $limits = config('efactura-sdk.rate_limits');
        unset($limits['status_per_day_message_provider']);
        config(['efactura-sdk.rate_limits' => $limits]);

        expect((new RateLimiter)->getRemainingQuota('status', 'MSG123', true)['limit'])->toBe(100);
    });

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

    it('reports the company_lookup quota', function () {
        // checkCompanyLookup() enforces this limit, so getRemainingQuota() must be
        // able to report it -- otherwise the one limit the SDK cannot introspect is
        // the one with the tightest window.
        $limiter = new RateLimiter;

        $quota = $limiter->getRemainingQuota('company_lookup');

        expect($quota)->toHaveKeys(['limit', 'remaining', 'resetsIn']);
        expect($quota['limit'])->toBe(1);
        expect($quota['remaining'])->toBe(1);
    });

    it('allows empty identifier for company_lookup type', function () {
        // Like 'global' this is a single bucket: the limit is per request, not per CUI.
        $limiter = new RateLimiter;

        expect($limiter->getRemainingQuota('company_lookup', ''))->toHaveKeys(['limit', 'remaining', 'resetsIn']);
    });

    it('reports company_lookup against the same bucket checkCompanyLookup consumes', function () {
        $limiter = new RateLimiter;

        expect($limiter->getRemainingQuota('company_lookup')['remaining'])->toBe(1);

        $limiter->checkCompanyLookup();

        expect($limiter->getRemainingQuota('company_lookup')['remaining'])->toBe(0);
    });
});

describe('checkCompanyLookup', function () {
    it('allows the first call and blocks the second within the same second', function () {
        $limiter = new RateLimiter;

        $limiter->checkCompanyLookup(); // first: allowed

        expect(fn () => $limiter->checkCompanyLookup())
            ->toThrow(RateLimitExceededException::class);
    });

    it('reports a retryAfterSeconds of at least 1', function () {
        $limiter = new RateLimiter;
        $limiter->checkCompanyLookup();

        try {
            $limiter->checkCompanyLookup();
            $this->fail('expected RateLimitExceededException');
        } catch (RateLimitExceededException $e) {
            expect($e->retryAfterSeconds)->toBeGreaterThanOrEqual(1);
            expect($e->getCode())->toBe(429);
        }
    });

    it('names the 2-second window in its refusal', function () {
        $limiter = new RateLimiter;
        $limiter->checkCompanyLookup();

        expect(fn () => $limiter->checkCompanyLookup())
            ->toThrow(RateLimitExceededException::class, 'Company lookup rate limit exceeded (1 per 2 seconds).');
    });

    it('is bypassed entirely when rate limiting is disabled', function () {
        config()->set('efactura-sdk.rate_limits.enabled', false);
        $limiter = new RateLimiter;

        $limiter->checkCompanyLookup();
        $limiter->checkCompanyLookup();
        $limiter->checkCompanyLookup();

        expect(true)->toBeTrue(); // no exception thrown
    });

    it('keeps lookups at least a second apart on a whole-second cache store', function () {
        // The database store expires a key at a whole second, so a 1-second window opened
        // at x.999 would be gone at x+1.000 and let a second lookup through 2 ms later.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        LaravelRateLimiter::swap(new CacheRateLimiter(Cache::store('database')));
        $limiter = new RateLimiter;
        $at = fn (string $time) => Carbon::setTestNow(Carbon::parse($time));

        $at('2026-11-10 10:00:00.999');
        $limiter->checkCompanyLookup();

        foreach (['2026-11-10 10:00:01.001', '2026-11-10 10:00:01.999'] as $time) {
            $at($time);
            expect(fn () => $limiter->checkCompanyLookup())->toThrow(RateLimitExceededException::class);
        }

        $at('2026-11-10 10:00:02.000');
        $limiter->checkCompanyLookup();

        expect(true)->toBeTrue();
    });
});

describe('exception scope', function () {
    it('names the limit each check trips', function (string $configKey, Closure $check, RateLimitScope $scope) {
        config()->set("efactura-sdk.rate_limits.{$configKey}", 1);
        $limiter = new RateLimiter;
        $check($limiter);

        try {
            $check($limiter);
            $this->fail('Expected the limit to trip.');
        } catch (RateLimitExceededException $e) {
            expect($e->scope)->toBe($scope);
        }
    })->with([
        'global' => ['global_per_minute', fn (RateLimiter $l) => $l->checkGlobal(), RateLimitScope::Global],
        'company lookup' => ['company_lookup_per_second', fn (RateLimiter $l) => $l->checkCompanyLookup(), RateLimitScope::CompanyLookup],
        'rasp upload' => ['rasp_upload_per_day_cui', fn (RateLimiter $l) => $l->checkRaspUpload('12345678'), RateLimitScope::RaspUpload],
        'status' => ['status_per_day_message', fn (RateLimiter $l) => $l->checkStatusQuery('MSG123'), RateLimitScope::Status],
        'status at the provider limit' => ['status_per_day_message_provider', fn (RateLimiter $l) => $l->checkStatusQuery('MSG123', true), RateLimitScope::Status],
        'simple list' => ['simple_list_per_day_cui', fn (RateLimiter $l) => $l->checkSimpleList('12345678'), RateLimitScope::SimpleList],
        'paginated list' => ['paginated_list_per_day_cui', fn (RateLimiter $l) => $l->checkPaginatedList('12345678'), RateLimitScope::PaginatedList],
        'download' => ['download_per_day_message', fn (RateLimiter $l) => $l->checkDownload('MSG123'), RateLimitScope::Download],
    ]);
});
