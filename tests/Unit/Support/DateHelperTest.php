<?php

declare(strict_types=1);

use Beecoded\EFactura\Support\DateHelper;
use Carbon\Carbon;

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
