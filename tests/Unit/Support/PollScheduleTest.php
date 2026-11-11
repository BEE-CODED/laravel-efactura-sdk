<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Support\PollSchedule;

it('reads the status less often as the upload ages', function (int $age, int $seconds) {
    expect(PollSchedule::status($age))->toBe($seconds);
})->with([
    'just uploaded' => [0, 60],
    'before 10 minutes' => [599, 60],
    'at 10 minutes' => [600, 300],
    'before an hour' => [3599, 300],
    'at an hour' => [3600, 900],
    'before three hours' => [10799, 900],
    'at three hours' => [10800, 3600],
    'after a day' => [86400 * 3, 3600],
]);

it('doubles the archive retry delay up to a day', function (int $attempt, int $seconds) {
    expect(PollSchedule::archive($attempt))->toBe($seconds);
})->with([
    'first failure' => [1, 300],
    'second' => [2, 600],
    'third' => [3, 1200],
    'ninth' => [9, 76800],
    'tenth reaches the cap' => [10, 86400],
    'long after' => [1000, 86400],
]);

it('takes the host its own first delay and cap', function () {
    expect(PollSchedule::archive(1, 60, 500))->toBe(60)
        ->and(PollSchedule::archive(4, 60, 500))->toBe(480)
        ->and(PollSchedule::archive(5, 60, 500))->toBe(500);
});
