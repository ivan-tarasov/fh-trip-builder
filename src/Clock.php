<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Database\Connection;

/**
 * Whether the application and its database agree about the time.
 *
 * They did not. Measured 2026-09-12: PHP on UTC, MySQL on `SYSTEM` and so on
 * the server's own Eastern clock, four hours apart on the laptop and four hours
 * apart in production (E17, #171).
 *
 * That matters because this codebase writes timestamps with both. `NOW()` at 37
 * sites, `date()` at the rest, and the pair that decides what a visitor sees is
 * `flights.departure_time >= NOW()` -- which is how "upcoming" is decided, on a
 * clock offset from the one that wrote the row. Four hours is a whole
 * short-haul flight.
 *
 * The failure is silent, which is the only reason this class exists. Nothing
 * errors, no query returns nothing, no page breaks: every timestamp keeps being
 * written, just four hours away from the one beside it. So the invariant gets
 * stated somewhere that is checked rather than left as something somebody has
 * to think to go and look at.
 */
final readonly class Clock
{
    /**
     * Anything under a minute is two clocks ticking, not two timezones.
     *
     * A second or two of skew between a web server and a database server is
     * ordinary and means nothing here. A timezone difference is at least a
     * quarter of an hour and usually a whole one, so the gap between "noise"
     * and "wrong" is wide enough that the threshold does not need care.
     */
    public const int TOLERANCE_SECONDS = 60;

    /**
     * How far the database's clock is from this process's, in seconds.
     *
     * Positive means the database is ahead. Null means it could not be asked,
     * which is a different answer from zero and has to stay different: a
     * database that is down must not report as a database that agrees.
     */
    public static function drift(Connection $connection): ?int
    {
        try {
            $database = (string) $connection->fetchValue('SELECT NOW()');

            if ($database === '') {
                return null;
            }

            return new DateTimeImmutable($database)->getTimestamp() - time();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The same thing in words, for the health endpoint.
     */
    public static function describe(?int $drift): string
    {
        if ($drift === null) {
            return 'unknown';
        }

        if (abs($drift) <= self::TOLERANCE_SECONDS) {
            return 'ok';
        }

        return sprintf(
            '%s %s',
            self::readable(abs($drift)),
            $drift > 0 ? 'ahead' : 'behind',
        );
    }

    private static function readable(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => intdiv($seconds, 60) . 'm',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h',
            default => intdiv($seconds, 86400) . 'd',
        };
    }
}
