<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What a seat on a flight costs the atmosphere, near enough to be useful.
 *
 * Google Flights puts a CO2e figure on every result and almost nobody at this
 * size does, which is the reason it is here (C5, #154). The inputs were all in
 * the database already: the distance on the leg, the type flying it, the seats
 * that type has fitted in each cabin.
 *
 * The model is four steps and no more, because a fifth would be pretending:
 *
 * 1. Fuel for the leg is the type's cruise burn over the distance, plus a fixed
 *    allowance for taxi, take-off and the climb.
 * 2. Burning a kilogram of jet fuel makes 3.16 kilograms of CO2. This one is
 *    chemistry rather than an estimate -- it is the carbon in the fuel meeting
 *    the oxygen it burns in -- and it is the only number here that is exact.
 * 3. That total is divided across the seats on board, weighted by how much
 *    floor each cabin takes. A business seat is not one economy seat.
 * 4. Divided again by how full the aeroplane typically is, because an empty
 *    seat burns the same fuel as a full one.
 *
 * **It is an estimate and the site says so.** A real figure would need the load
 * on the day, the winds, the routing, the engine variant and the age of the
 * airframe. What this gets right is the comparison, which is what the number is
 * on the card for: a 787 against a 777 on the same route, or a connection
 * against the direct.
 *
 * Only CO2 from the fuel. The warming from contrails and high-altitude NOx is
 * real and is thought to be about as large again, but the multiplier for it is
 * genuinely contested, and a contested number multiplied into every card would
 * make the comparison worse rather than better.
 */
final class Emissions
{
    /**
     * Kilograms of CO2 from burning one kilogram of jet fuel.
     *
     * Jet A-1 is about 86% carbon by mass, and each carbon atom leaves with two
     * oxygen atoms it did not arrive with, which is where a kilogram in becomes
     * more than three kilograms out.
     */
    public const float KG_CO2_PER_KG_FUEL = 3.16;

    /**
     * Taxi, take-off and the climb, as the extra cruise kilometres that burn
     * the same fuel.
     *
     * Getting to altitude is the expensive part of a flight, and it costs the
     * same whether the leg is 400km or 4,000. Without this a short hop looks
     * far cleaner per kilometre than it is -- which is the one thing everybody
     * knows about short flights, so a model that misses it is visibly wrong.
     */
    private const int CLIMB_EQUIVALENT_KM = 125;

    /**
     * How full the seats typically are.
     *
     * The fuel is burnt for the aeroplane, not for the people in it, so the
     * share falling to each traveller is the share of the seats that are sold.
     * Near the industry's own long-run average.
     */
    private const float SEATS_SOLD = 0.82;

    /**
     * Floor area per seat, against economy.
     *
     * This is how the total is split and it is the only fair way to split it: a
     * business seat where four economy seats would fit is four economy seats'
     * worth of aeroplane, whatever the fare was.
     */
    private const array CABIN_SHARE = [
        'Y' => 1.0,
        'W' => 1.5,
        'C' => 2.5,
        'F' => 4.0,
    ];

    /**
     * Kilograms of CO2 for one seat on one leg, or null when it cannot be said.
     *
     * Null rather than a guess: an aircraft type with no published burn, or one
     * whose seat counts are missing, has nothing behind a number and a figure
     * invented for it would be indistinguishable on the card from a real one.
     *
     * @param array<string, int> $seats seats fitted, keyed by IATA cabin code
     */
    public static function forLeg(
        float $distanceKm,
        float $burnKgPerKm,
        array $seats,
        CabinClass $cabin,
    ): ?float {
        if ($distanceKm <= 0 || $burnKgPerKm <= 0) {
            return null;
        }

        $shares = self::weightedSeats($seats);

        // A cabin with no seats on this frame is a leg that cannot be flown in
        // it, which the search has already refused to sell. Reaching here means
        // the seat data and the flight disagree, and there is no share to take.
        if ($shares <= 0 || ($seats[$cabin->code()] ?? 0) <= 0) {
            return null;
        }

        $fuel = $burnKgPerKm * ($distanceKm + self::CLIMB_EQUIVALENT_KM);
        $share = self::CABIN_SHARE[$cabin->code()] ?? 1.0;

        return $fuel * self::KG_CO2_PER_KG_FUEL * $share / $shares / self::SEATS_SOLD;
    }

    /**
     * The seats on board counted in economy seats.
     *
     * @param array<string, int> $seats seats fitted, keyed by IATA cabin code
     */
    private static function weightedSeats(array $seats): float
    {
        $total = 0.0;

        foreach ($seats as $code => $fitted) {
            $total += max(0, $fitted) * (self::CABIN_SHARE[$code] ?? 1.0);
        }

        return $total;
    }
}
