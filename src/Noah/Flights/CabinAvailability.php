<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use TripBuilder\CabinClass;
use TripBuilder\Database\Table;

/**
 * Folds an aircraft type's fitted cabins into a `flights.cabins` bitmask.
 *
 * What a flight can sell is settled by the frame flying it: the cabins are
 * bolted to the floor, so `aircraft_cabins` is the whole answer and there is
 * nothing here to infer. An earlier version of this class guessed from
 * `is_widebody` and the length of the leg, which put premium economy on frames
 * that have never been fitted with it.
 *
 * Distance does not appear at all. A carrier sells every cabin on board on a
 * 300 km positioning hop as readily as on a 12,000 km one -- what stops a
 * turboprop route from offering business is that a turboprop has no business
 * cabin, and the generator is what keeps the frame suited to the leg.
 *
 * Bits are independent rather than a grade, because fitted cabins are not
 * nested: an A321neo sells Economy, Premium Economy and Business, while a 737
 * of the same size and range sells Economy and Business only.
 *
 * Exists in PHP and in SQL: the generator folds a mask a row at a time from
 * cabins it has already loaded, while the populate command does the whole table
 * in one UPDATE. Both agree because both spend the bits from CabinClass.
 */
final class CabinAvailability
{
    /**
     * Fold IATA cabin codes -- as held in `aircraft_cabins.cabin` -- into a
     * mask. Unrecognised codes are skipped rather than silently counted.
     *
     * An aircraft with no cabin rows, or none this build understands, still
     * sells economy: every flight has an economy cabin, and a mask of 0 would
     * mean a flight nobody can buy a seat on.
     *
     * @param list<string> $codes
     */
    public static function bits(array $codes): int
    {
        $bits = CabinClass::Economy->bit();

        foreach ($codes as $code) {
            $bits |= CabinClass::tryFromCode($code)?->bit() ?? 0;
        }

        return $bits;
    }

    /**
     * Every type's mask, as a derived table of (aircraft, mask).
     *
     * Joined against rather than correlated per row: 28 types fold once, where
     * a subquery per flight would fold them 695,000 times.
     */
    public static function sqlMaskByAircraft(string $alias): string
    {
        $cases = '';

        foreach (CabinClass::cases() as $cabin) {
            $cases .= sprintf(" WHEN '%s' THEN %d", $cabin->code(), $cabin->bit());
        }

        // BIT_OR over the rows of one type, so a duplicate row cannot double a
        // bit the way SUM would.
        return sprintf(
            '(SELECT aircraft, BIT_OR(CASE cabin%s ELSE 0 END) | %d AS mask'
            . ' FROM %s GROUP BY aircraft) %s',
            $cases,
            CabinClass::Economy->bit(),
            Table::AircraftCabins->value,
            $alias,
        );
    }
}
