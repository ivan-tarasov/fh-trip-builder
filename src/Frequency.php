<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;

/**
 * How often a scheduled command runs.
 *
 * An enum rather than an array key, for the reason E15 (#162) gives for status
 * codes: a misspelled `'dailly'` is a silent no-op and `Frequency::Daily` is
 * not spellable wrongly. It also names the vocabulary in one place, which a
 * convention spread across a config file does not.
 *
 * Two cases and one caller today. `Hourly` is here because a one-case enum is
 * ceremony rather than a type, and because C6 (#155)'s price alerts are the
 * expected second caller -- alerts checked once a day are not alerts.
 */
enum Frequency: string
{
    case Daily = 'daily';
    case Hourly = 'hourly';

    /**
     * What `at` has to look like for this frequency.
     *
     * `03:00` for a daily task, `:20` for an hourly one -- the minute past
     * whichever hour it is.
     */
    public function pattern(): string
    {
        return match ($this) {
            self::Daily => '/^([01]\d|2[0-3]):[0-5]\d$/',
            self::Hourly => '/^:[0-5]\d$/',
        };
    }

    public function describe(string $at): string
    {
        return match ($this) {
            self::Daily => 'daily at ' . $at,
            self::Hourly => 'hourly at ' . ltrim($at, ':') . ' past',
        };
    }

    /**
     * The most recent moment this task was supposed to run, at or before now.
     *
     * Always a real moment in the past, never null, and that is what makes
     * catching up fall out rather than be written: a task is due when nothing
     * has run since this instant, whether that is because the last tick was
     * fifteen minutes ago or because the server was off for two days.
     */
    public function lastOccurrence(DateTimeImmutable $now, string $at): DateTimeImmutable
    {
        $today = match ($this) {
            self::Daily => $now->modify($at . ':00'),
            self::Hourly => $now->setTime((int) $now->format('G'), (int) ltrim($at, ':')),
        };

        if ($today === false) {
            // Unreachable while `at` matches pattern(); Schedule checks it on load.
            return $now;
        }

        return $today <= $now
            ? $today
            : $today->modify($this === self::Daily ? '-1 day' : '-1 hour');
    }
}
