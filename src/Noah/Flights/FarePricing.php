<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use TripBuilder\Helper;

/**
 * What one leg costs, in economy.
 *
 * The previous formula produced fares nobody would recognise -- a Melbourne to
 * London itinerary landed around CAD 9,500 -- for three compounding reasons:
 *
 *   - the nonstop premium was an absolute 4000 at the reference distance, so on
 *     a long leg it was roughly twice the fare it was meant to garnish;
 *   - a flat 5-800 was added to every leg at random, which is most of a
 *     short-haul ticket;
 *   - tax was drawn from 5-90 percent. Real air taxes and carrier fees run
 *     around 10-25 percent of the base; ninety is not a number that occurs.
 *
 * What is kept is the distance-linear base and the convex nonstop premium. The
 * premium matters structurally, not just for realism: legs are priced
 * independently and the search sums them, so without it a direct flight would
 * always undercut a connection covering the same ground, and the "cheapest"
 * option would never be a connection -- which is the opposite of how fares
 * actually behave, and would make the results dull.
 *
 * Resulting one-way economy fares, base plus tax:
 *
 *     500 km      ~  80      a domestic hop
 *   2,000 km      ~ 215      short international
 *  10,000 km      ~ 1,000    long haul
 *  16,900 km      ~ 1,850    Melbourne to London, direct
 *
 * price_base is decimal(6,2), and the longest leg in the network lands near
 * 1,600, so there is a wide margin before the column complains.
 */
final class FarePricing
{
    /**
     * Flat cost on a leg that does not scale with distance: handling, fees.
     * Charged per leg, so a connection carries it once for each -- part of what
     * stops connections undercutting the nonstop covering the same ground.
     */
    public const float FIXED_DOLLARS = 30.0;

    /** Dollars per kilometre of the base fare. */
    public const float PER_KM = 0.075;

    /**
     * How much of the distance component a nonstop adds at or beyond the
     * reference distance. Convex below it, so short legs are barely touched.
     */
    public const float NONSTOP_PREMIUM_SHARE = 0.22;
    public const int NONSTOP_PREMIUM_REF_KM = 15000;

    /** Fare spread between two otherwise identical legs, as percent. */
    public const array VARIANCE_PERCENT = [90, 112];

    /** Taxes and carrier fees, as a percent of the base. */
    public const array TAX_PERCENT = [11, 26];

    /**
     * Base fare for a leg of this distance, before tax. Randomised within
     * VARIANCE_PERCENT so two legs on the same route are not identical.
     */
    public static function base(int $distance): float
    {
        $byDistance = self::PER_KM * $distance;

        $premium = $byDistance * self::NONSTOP_PREMIUM_SHARE * min(
            1.0,
            ($distance / self::NONSTOP_PREMIUM_REF_KM) ** 2,
        );

        $fare = (self::FIXED_DOLLARS + $byDistance + $premium)
            * (Helper::random(self::VARIANCE_PERCENT) / 100);

        return round($fare, 2);
    }

    /** Taxes and fees on a base fare. */
    public static function tax(float $base): float
    {
        return round($base * (Helper::random(self::TAX_PERCENT) / 100), 2);
    }

    /**
     * The same base-fare arithmetic as base(), expressed for MySQL.
     *
     * It exists because repricing the existing network row by row through PHP
     * would be hundreds of thousands of round trips. It reads its numbers from
     * the constants above, so only the shape of the expression is duplicated --
     * change the formula and both follow. Kept in this file, next to its twin,
     * for exactly that reason.
     */
    public static function sqlBase(): string
    {
        $byKm = sprintf('(%F * distance)', self::PER_KM);
        $premium = sprintf(
            '(%s * %F * LEAST(1, POW(distance / %d, 2)))',
            $byKm,
            self::NONSTOP_PREMIUM_SHARE,
            self::NONSTOP_PREMIUM_REF_KM,
        );
        $variance = sprintf(
            '((%d + (RAND() * %d)) / 100)',
            self::VARIANCE_PERCENT[0],
            self::VARIANCE_PERCENT[1] - self::VARIANCE_PERCENT[0],
        );

        return sprintf('ROUND((%F + %s + %s) * %s, 2)', self::FIXED_DOLLARS, $byKm, $premium, $variance);
    }

    /** The tax twin of sqlBase(), applied to an already-updated price_base. */
    public static function sqlTax(): string
    {
        return sprintf(
            'ROUND(price_base * ((%d + (RAND() * %d)) / 100), 2)',
            self::TAX_PERCENT[0],
            self::TAX_PERCENT[1] - self::TAX_PERCENT[0],
        );
    }
}
