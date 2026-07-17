<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Support\DateHelper;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('formatForAnaf', function () {
    it('formats Carbon instance to ANAF format', function () {
        $date = Carbon::create(2024, 3, 15);

        expect(DateHelper::formatForAnaf($date))->toBe('2024-03-15');
    });

    it('returns date string as-is if already in ANAF format', function () {
        expect(DateHelper::formatForAnaf('2024-03-15'))->toBe('2024-03-15');
    });

    it('parses string date to ANAF format', function () {
        expect(DateHelper::formatForAnaf('March 15, 2024'))->toBe('2024-03-15');
        expect(DateHelper::formatForAnaf('2024/03/15'))->toBe('2024-03-15');
    });

    it('converts unix timestamp in seconds', function () {
        $timestamp = Carbon::create(2024, 3, 15, 12, 0, 0)->getTimestamp();

        expect(DateHelper::formatForAnaf($timestamp))->toBe('2024-03-15');
    });

    it('converts unix timestamp in milliseconds', function () {
        $timestamp = Carbon::create(2024, 3, 15, 12, 0, 0)->getTimestamp() * 1000;

        expect(DateHelper::formatForAnaf($timestamp))->toBe('2024-03-15');
    });

    it('throws exception for invalid date string', function () {
        DateHelper::formatForAnaf('not-a-date');
    })->throws(InvalidArgumentException::class, 'Invalid date provided');
});

describe('isValidAnafFormat', function () {
    it('returns true for valid ANAF format', function () {
        expect(DateHelper::isValidAnafFormat('2024-03-15'))->toBeTrue();
        expect(DateHelper::isValidAnafFormat('2024-12-31'))->toBeTrue();
    });

    it('returns false for invalid formats', function () {
        expect(DateHelper::isValidAnafFormat('03-15-2024'))->toBeFalse();
        expect(DateHelper::isValidAnafFormat('2024/03/15'))->toBeFalse();
        expect(DateHelper::isValidAnafFormat('15-03-2024'))->toBeFalse();
        expect(DateHelper::isValidAnafFormat('invalid'))->toBeFalse();
        expect(DateHelper::isValidAnafFormat(''))->toBeFalse();
    });
});

describe('toTimestamp', function () {
    it('converts Carbon to milliseconds timestamp', function () {
        $date = Carbon::create(2024, 3, 15, 12, 0, 0);
        $expected = $date->getTimestamp() * 1000;

        expect(DateHelper::toTimestamp($date))->toBe($expected);
    });

    it('converts string date to milliseconds timestamp', function () {
        $date = '2024-03-15';
        $expected = Carbon::parse($date)->getTimestamp() * 1000;

        expect(DateHelper::toTimestamp($date))->toBe($expected);
    });
});

describe('getDayRange', function () {
    it('returns start and end timestamps for a day', function () {
        $date = Carbon::create(2024, 3, 15, 12, 30, 45);
        $range = DateHelper::getDayRange($date);

        $startOfDay = Carbon::create(2024, 3, 15, 0, 0, 0);
        $endOfDay = Carbon::create(2024, 3, 15, 23, 59, 59);

        expect($range)->toHaveKey('start');
        expect($range)->toHaveKey('end');
        expect($range['start'])->toBe($startOfDay->getTimestamp() * 1000);
        expect($range['end'])->toBe($endOfDay->getTimestamp() * 1000 + 999);
    });

    it('works with string date', function () {
        $range = DateHelper::getDayRange('2024-03-15');

        $startOfDay = Carbon::parse('2024-03-15')->startOfDay();
        $endOfDay = Carbon::parse('2024-03-15')->endOfDay();

        expect($range['start'])->toBe($startOfDay->getTimestamp() * 1000);
        expect($range['end'])->toBe($endOfDay->getTimestamp() * 1000 + 999);
    });
});

describe('isValidDaysParameter', function () {
    it('returns true for valid range (1-60)', function () {
        expect(DateHelper::isValidDaysParameter(1))->toBeTrue();
        expect(DateHelper::isValidDaysParameter(30))->toBeTrue();
        expect(DateHelper::isValidDaysParameter(60))->toBeTrue();
    });

    it('returns false for invalid range', function () {
        expect(DateHelper::isValidDaysParameter(0))->toBeFalse();
        expect(DateHelper::isValidDaysParameter(-1))->toBeFalse();
        expect(DateHelper::isValidDaysParameter(61))->toBeFalse();
        expect(DateHelper::isValidDaysParameter(100))->toBeFalse();
    });
});

describe('getCurrentDateForAnaf', function () {
    it('returns current date in ANAF format', function () {
        expect(DateHelper::getCurrentDateForAnaf())->toBe('2024-06-15');
    });
});

describe('getDaysAgo', function () {
    it('returns Carbon instance for N days ago', function () {
        $daysAgo = DateHelper::getDaysAgo(5);

        expect($daysAgo)->toBeInstanceOf(Carbon::class);
        expect($daysAgo->format('Y-m-d'))->toBe('2024-06-10');
    });

    it('handles zero days', function () {
        $daysAgo = DateHelper::getDaysAgo(0);

        expect($daysAgo->format('Y-m-d'))->toBe('2024-06-15');
    });
});

describe('daysBetween', function () {
    it('calculates days between two Carbon instances', function () {
        $from = Carbon::create(2024, 3, 10);
        $to = Carbon::create(2024, 3, 15);

        expect(DateHelper::daysBetween($from, $to))->toBe(5);
    });

    it('calculates days between two date strings', function () {
        expect(DateHelper::daysBetween('2024-03-10', '2024-03-15'))->toBe(5);
    });

    it('returns absolute difference regardless of order', function () {
        expect(DateHelper::daysBetween('2024-03-15', '2024-03-10'))->toBe(5);
    });

    it('handles same dates', function () {
        expect(DateHelper::daysBetween('2024-03-15', '2024-03-15'))->toBe(0);
    });
});

describe('immutable date support', function () {
    // Apps calling Date::use(CarbonImmutable::class) pass CarbonImmutable instances in.
    // CarbonImmutable is not a Carbon subclass, so these entry points must accept
    // CarbonInterface and normalise internally.

    it('formats an immutable date for ANAF', function () {
        expect(DateHelper::formatForAnaf(CarbonImmutable::create(2024, 3, 15)))->toBe('2024-03-15');
    });

    it('converts an immutable date to a millisecond timestamp', function () {
        $date = CarbonImmutable::create(2024, 1, 1, 0, 0, 0, 'UTC');

        expect(DateHelper::toTimestamp($date))->toBe($date->getTimestamp() * 1000);
    });

    it('builds a day range from an immutable date', function () {
        $date = CarbonImmutable::create(2024, 3, 15, 8, 30, 0, 'UTC');

        $range = DateHelper::getDayRange($date);

        expect($range['start'])->toBe(Carbon::create(2024, 3, 15, 0, 0, 0, 'UTC')->getTimestamp() * 1000)
            ->and($range['end'])->toBe(Carbon::create(2024, 3, 15, 23, 59, 59, 'UTC')->getTimestamp() * 1000 + 999);
    });

    it('calculates days between immutable dates', function () {
        expect(DateHelper::daysBetween(
            CarbonImmutable::create(2024, 3, 10),
            CarbonImmutable::create(2024, 3, 15),
        ))->toBe(5);
    });

    it('does not mutate the caller\'s mutable Carbon', function () {
        // toCarbon() returns the caller's instance verbatim for a mutable Carbon,
        // so getDayRange() must keep copying before startOfDay()/endOfDay().
        $date = Carbon::create(2024, 3, 15, 8, 30, 0, 'UTC');

        DateHelper::getDayRange($date);

        expect($date->format('Y-m-d H:i:s'))->toBe('2024-03-15 08:30:00');
    });
});
