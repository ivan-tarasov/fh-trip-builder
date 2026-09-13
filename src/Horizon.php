<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * How far ahead this site goes: today plus a year, and one number saying so.
 *
 * The calendar, the URL and the generated flights all end on the same day. That
 * is the whole point of the class existing rather than the number being written
 * in each of them — the defect it replaces was a picker that paged to September
 * 2028 while the flights stopped at day ninety, so `AMS -> FRA`, one of the
 * busiest routes there is, answered "No flights found" and nothing told the
 * visitor the route was fine (E30, #215).
 *
 * A longer window on its own would not have fixed that; it would have moved the
 * same cliff to month twelve. What removes it is the two ending together, which
 * is a thing one constant can guarantee and two cannot.
 *
 * **Rolling, not fixed.** Today plus 365 days, recomputed per request, so the
 * horizon moves with the calendar and nothing needs a yearly edit.
 */
final class Horizon
{
    /**
     * A year. Long enough that a holiday can be booked from it, and the number
     * the nightly generation fills up to -- 10,000 flights a day against this
     * is 3,650,000 rows and about 2.2 GB.
     */
    public const int DAYS = 365;

    /** The last day anything is offered or held, as `Y-m-d`. */
    public static function last(?string $from = null): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%s +%d days', $from ?? 'today', self::DAYS)));
    }

    /**
     * Whether a date is one this site can answer for.
     *
     * The past is not excluded here, and deliberately: a date behind us is read
     * faithfully and finds nothing, which is the honest answer and what the
     * query-string form has always done. What this refuses is the far side,
     * where the emptiness is an artefact of how much has been generated rather
     * than anything about the date.
     */
    public static function covers(string $date, ?string $from = null): bool
    {
        return $date <= self::last($from);
    }

    /**
     * The generator's window, as the offsets it draws days from.
     *
     * From tomorrow: a flight earlier today has mostly left, and the sweep
     * removes the rest tonight.
     *
     * @return array{0: int, 1: int}
     */
    public static function window(): array
    {
        return [1, self::DAYS];
    }
}
