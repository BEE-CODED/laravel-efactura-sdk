<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Support;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Date utilities for ANAF e-Factura API interactions.
 *
 * ANAF requires dates in YYYY-MM-DD format and timestamps in milliseconds
 * for pagination and message filtering operations.
 */
final class DateHelper
{
    /**
     * ANAF date format pattern.
     */
    private const ANAF_DATE_FORMAT = 'Y-m-d';

    /**
     * Regular expression for ANAF date format.
     */
    private const ANAF_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Minimum days for message filtering.
     */
    private const MIN_DAYS = 1;

    /**
     * Maximum days for message filtering (ANAF limit).
     */
    private const MAX_DAYS = 60;

    /**
     * Format a date for ANAF API (YYYY-MM-DD format).
     *
     * @param  Carbon|string|int  $date  The date to format (Carbon, string, or Unix timestamp in seconds)
     * @return string The formatted date string
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    public static function formatForAnaf(Carbon|string|int $date): string
    {
        // If already in ANAF format, return as-is
        if (is_string($date) && self::isValidAnafFormat($date)) {
            return $date;
        }

        $carbon = self::toCarbon($date);

        return $carbon->format(self::ANAF_DATE_FORMAT);
    }

    /**
     * Check if a date string is in valid ANAF format (YYYY-MM-DD).
     *
     * @param  string  $date  The date string to validate
     * @return bool True if the format is valid
     */
    public static function isValidAnafFormat(string $date): bool
    {
        return (bool) preg_match(self::ANAF_DATE_PATTERN, $date);
    }

    /**
     * Convert a date to timestamp in milliseconds (for ANAF pagination).
     *
     * ANAF uses millisecond timestamps for message list pagination.
     *
     * @param  Carbon|string  $date  The date to convert
     * @return int Timestamp in milliseconds
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    public static function toTimestamp(Carbon|string $date): int
    {
        $carbon = self::toCarbon($date);

        /** @var int $timestamp */
        $timestamp = $carbon->getTimestamp();

        return $timestamp * 1000;
    }

    /**
     * Get the start and end timestamps for a given day.
     *
     * Returns timestamps in milliseconds for the beginning (00:00:00.000)
     * and end (23:59:59.999) of the specified day, useful for day-based
     * message filtering.
     *
     * @param  Carbon|string  $date  The date to get the range for
     * @return array{start: int, end: int} Array with 'start' and 'end' timestamps in milliseconds
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    public static function getDayRange(Carbon|string $date): array
    {
        $carbon = self::toCarbon($date);

        // Start of day: 00:00:00.000
        $start = $carbon->copy()->startOfDay();

        // End of day: 23:59:59.999
        $end = $carbon->copy()->endOfDay();

        /** @var int $startTimestamp */
        $startTimestamp = $start->getTimestamp();
        /** @var int $endTimestamp */
        $endTimestamp = $end->getTimestamp();

        return [
            'start' => $startTimestamp * 1000,
            'end' => $endTimestamp * 1000 + 999,
        ];
    }

    /**
     * Check if a days parameter is valid for ANAF message filtering.
     *
     * ANAF limits message filtering to 1-60 days.
     *
     * @param  int  $days  The number of days to validate
     * @return bool True if the value is within valid range
     */
    public static function isValidDaysParameter(int $days): bool
    {
        return $days >= self::MIN_DAYS && $days <= self::MAX_DAYS;
    }

    /**
     * Get the current date formatted for ANAF.
     *
     * @return string Current date in YYYY-MM-DD format
     */
    public static function getCurrentDateForAnaf(): string
    {
        return Carbon::now()->format(self::ANAF_DATE_FORMAT);
    }

    /**
     * Get a date N days ago.
     *
     * @param  int  $days  Number of days to go back
     * @return Carbon The Carbon instance for that date
     */
    public static function getDaysAgo(int $days): Carbon
    {
        return Carbon::now()->subDays($days);
    }

    /**
     * Calculate the number of days between two dates.
     *
     * @param  Carbon|string  $from  Start date
     * @param  Carbon|string  $to  End date
     * @return int Number of days between the dates (absolute value)
     *
     * @throws InvalidArgumentException If either date is invalid
     */
    public static function daysBetween(Carbon|string $from, Carbon|string $to): int
    {
        $fromCarbon = self::toCarbon($from);
        $toCarbon = self::toCarbon($to);

        return (int) abs($fromCarbon->diffInDays($toCarbon));
    }

    /**
     * Convert various date formats to Carbon instance.
     *
     * @param  Carbon|string|int  $date  The date to convert
     * @return Carbon The Carbon instance
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    private static function toCarbon(Carbon|string|int $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        if (is_int($date)) {
            // Assume Unix timestamp in seconds if less than year 10000 in milliseconds
            // This handles both second and millisecond timestamps
            if ($date > 9999999999) {
                // Milliseconds - convert to seconds
                return Carbon::createFromTimestampMs($date);
            }

            return Carbon::createFromTimestamp($date);
        }

        // String date
        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Invalid date provided: '.$date, 0, $e);
        }
    }
}
