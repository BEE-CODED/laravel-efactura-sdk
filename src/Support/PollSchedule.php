<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

/**
 * How long to wait before the next status read or archive download.
 *
 * Exact intervals, without slack: a scheduler that runs on a fixed tick should
 * subtract its own margin so a read is not pushed to the tick after.
 */
final class PollSchedule
{
    /**
     * Seconds until the next status read, by the seconds since the upload: every minute
     * for the first 10 minutes, every 5 until an hour, every 15 until three hours, then
     * hourly: 49 reads in the first day and 24 a day after. The SDK's own default cap is
     * 50 status reads per message per day (rate_limits.status_per_day_message; ANAF allows
     * 100), so one transport retry or a manual check on day one can meet that cap: reschedule
     * on RateLimitExceededException.
     */
    public static function status(int $ageSeconds): int
    {
        return match (true) {
            $ageSeconds < 600 => 60,
            $ageSeconds < 3600 => 300,
            $ageSeconds < 10800 => 900,
            default => 3600,
        };
    }

    /**
     * Seconds before the next try after the $attempt-th failed archive download
     * (1-based): $first, doubling each attempt, at most $max. With the defaults the sixth
     * download comes about 2.6 h after the first, over the SDK's default cap of 5 downloads
     * per message per day (rate_limits.download_per_day_message): reschedule on
     * RateLimitExceededException after its retryAfterSeconds.
     */
    public static function archive(int $attempt, int $first = 300, int $max = 86400): int
    {
        $delay = $first;
        for ($retry = 1; $retry < $attempt && $delay < $max; $retry++) {
            $delay *= 2;
        }

        return min($delay, $max);
    }
}
