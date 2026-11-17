<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
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
     * The timezone ANAF's systems operate in.
     *
     * Every date the SDK *chooses* on the caller's behalf must be resolved here:
     * ANAF evaluates dates against the Romanian calendar, so a UTC server between
     * midnight and 02:00/03:00 Bucharest would otherwise report yesterday.
     *
     * Exposed publicly so callers can anchor their own dates to ANAF's business
     * day, e.g. Carbon::parse('2024-03-15', DateHelper::ANAF_TIMEZONE).
     */
    public const ANAF_TIMEZONE = 'Europe/Bucharest';

    /**
     * ANAF date format pattern.
     */
    private const ANAF_DATE_FORMAT = 'Y-m-d';

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
     * A Unix timestamp is read on ANAF's calendar (Europe/Bucharest); a Carbon keeps its own timezone and a
     * string is parsed in the app timezone (see toTimestamp()). For today on ANAF's calendar, pass
     * now(DateHelper::ANAF_TIMEZONE) or use getCurrentDateForAnaf().
     *
     * @param  CarbonInterface|string|int  $date  Any Carbon date, a string, or a Unix timestamp in seconds or milliseconds (read as a Europe/Bucharest date)
     * @return string The formatted date string
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    public static function formatForAnaf(CarbonInterface|string|int $date): string
    {
        // A real Y-m-d date is returned as-is
        if (is_string($date) && self::isValidAnafFormat($date)) {
            return $date;
        }

        $carbon = self::toCarbon($date);

        return $carbon->format(self::ANAF_DATE_FORMAT);
    }

    /**
     * Check if a date string is exactly YYYY-MM-DD and a real calendar date.
     *
     * @param  string  $date  The date string to validate
     * @return bool True if the format is valid
     */
    public static function isValidAnafFormat(string $date): bool
    {
        return self::exact($date, self::ANAF_DATE_FORMAT) !== null;
    }

    /**
     * Read a date written exactly in $format, or null when it is not, or names no real date or time.
     *
     * Fields the format leaves out are zero (the `!` reset). Nothing is trimmed and nothing throws.
     */
    public static function exact(string $value, string $format, ?string $timezone = null): ?Carbon
    {
        $d = \DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone !== null ? new \DateTimeZone($timezone) : null);

        return $d !== false && $d->format($format) === $value ? Carbon::instance($d) : null;
    }

    /**
     * Parse a date string like Carbon::parse(), refusing what Carbon would roll over.
     *
     * @throws InvalidArgumentException When the string is blank, unparseable, impossible (2024-02-30, 24:00) or a slash date starting with the day or month (05/03/2024)
     */
    public static function parse(string $value): Carbon
    {
        // timelib warns on day/month overflow and on 24:00 or :60; Carbon would roll them over.
        // ... and n/n[/y], which timelib reads month-first (05/03/2024 would be 3 May).
        if (trim($value) === '' || preg_match('#\A\s*\d{1,2}/\d#', $value) === 1 || date_parse($value)['warnings'] !== []) {
            throw new InvalidArgumentException('Invalid date provided: '.$value);
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Invalid date provided: '.$value, 0, $e);
        }
    }

    /**
     * Convert a date to timestamp in milliseconds (for ANAF pagination).
     *
     * ANAF uses millisecond timestamps for message list pagination.
     *
     * Timezone: the caller's is respected. A CarbonInterface already carries an
     * absolute instant, and a bare string is parsed in the app timezone per
     * Laravel convention. This helper does NOT re-anchor to ANAF_TIMEZONE --
     * unlike getCurrentDateForAnaf(), the caller supplies the date, and
     * overriding an explicitly-chosen timezone would override stated intent.
     * To pin a date to ANAF's business day, say so:
     *   DateHelper::toTimestamp(Carbon::parse('2024-03-15', DateHelper::ANAF_TIMEZONE))
     *
     * @param  CarbonInterface|string  $date  The date to convert
     * @return int Timestamp in milliseconds
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    public static function toTimestamp(CarbonInterface|string $date): int
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
     * Timezone: the day boundaries are anchored in the timezone of the date you
     * pass in, not in ANAF_TIMEZONE -- see the note on toTimestamp(). On a UTC
     * server, getDayRange('2024-03-15') therefore spans a UTC day, which is
     * offset 2-3 hours from ANAF's Romanian day. Pass the timezone you mean:
     *   DateHelper::getDayRange(Carbon::parse('2024-03-15', DateHelper::ANAF_TIMEZONE))
     *
     * @param  CarbonInterface|string  $date  The date to get the range for
     * @return array{start: int, end: int} Array with 'start' and 'end' timestamps in milliseconds
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    public static function getDayRange(CarbonInterface|string $date): array
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
     * Resolved in Europe/Bucharest, not the app timezone. ANAF interprets this
     * value against the Romanian calendar, so on a UTC server between midnight
     * and 02:00/03:00 Bucharest the app timezone still reads yesterday -- which
     * would return stale data on effective-date boundaries (VAT registrations
     * flip on the 1st).
     *
     * @return string Current date in YYYY-MM-DD format
     */
    public static function getCurrentDateForAnaf(): string
    {
        return Carbon::now(self::ANAF_TIMEZONE)->format(self::ANAF_DATE_FORMAT);
    }

    /**
     * Get a date N days ago.
     *
     * Anchored to Europe/Bucharest for the same reason as getCurrentDateForAnaf():
     * the SDK chooses "now" here, and callers format the result as an ANAF date.
     * Only the display timezone differs -- the instant returned is unchanged.
     *
     * @param  int  $days  Number of days to go back
     * @return Carbon The Carbon instance for that date
     */
    public static function getDaysAgo(int $days): Carbon
    {
        return Carbon::now(self::ANAF_TIMEZONE)->subDays($days);
    }

    /**
     * Calculate the number of days between two dates.
     *
     * @param  CarbonInterface|string  $from  Start date
     * @param  CarbonInterface|string  $to  End date
     * @return int Number of days between the dates (absolute value)
     *
     * @throws InvalidArgumentException If either date is invalid
     */
    public static function daysBetween(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        $fromCarbon = self::toCarbon($from);
        $toCarbon = self::toCarbon($to);

        return (int) abs($fromCarbon->diffInDays($toCarbon));
    }

    /**
     * Convert various date formats to a mutable Carbon instance.
     *
     * Any CarbonInterface implementation is accepted; immutable dates from
     * Date::use(CarbonImmutable::class) are converted via Carbon::instance().
     *
     * @param  CarbonInterface|string|int  $date  The date to convert; a Unix timestamp (seconds or milliseconds) is read in Europe/Bucharest
     * @return Carbon The Carbon instance
     *
     * @throws InvalidArgumentException If the date is invalid
     */
    private static function toCarbon(CarbonInterface|string|int $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date);
        }

        if (is_int($date)) {
            // A Unix timestamp has no timezone: read it on ANAF's calendar (seconds, or milliseconds above 9999999999).
            if ($date > 9999999999) {
                return Carbon::createFromTimestampMs($date, self::ANAF_TIMEZONE);
            }

            return Carbon::createFromTimestamp($date, self::ANAF_TIMEZONE);
        }

        return self::parse($date);
    }
}
