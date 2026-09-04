<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The cabins the search form offers.
 *
 * Backed by the slug that crosses the request boundary, so a shared search URL
 * reads `class=business` rather than `class=C`. The IATA code is what each leg
 * of the flights payload carries, and the label is what a passenger reads.
 *
 * This is the cabin somebody *asked* for. Which cabins a given flight actually
 * sells is a property of the flight -- see the `cabins` bitmask, built by
 * CabinAvailability -- and `bit()` is what joins the two: a search for Business
 * keeps only the flights whose mask has bit 4 set.
 *
 * The rebuild paths in FlightFinder still pass no cabin at all, because a saved
 * itinerary or a stored booking records no cabin to rebuild from.
 */
enum CabinClass: string
{
    /** Distance at which a cabin commands its full premium. */
    private const int HAUL_FULL_KM = 7000;

    /** Share of the premium the shortest hop still carries. */
    private const float HAUL_FLOOR = 0.45;

    case Economy = 'economy';
    case PremiumEconomy = 'premium';
    case Business = 'business';
    case First = 'first';

    /**
     * Resolve a raw request value, falling back to economy for anything
     * missing or unrecognised (the search form's default).
     */
    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Economy;
    }

    /** The IATA cabin code carried on each leg of the payload. */
    public function code(): string
    {
        return match ($this) {
            self::Economy => 'Y',
            self::PremiumEconomy => 'W',
            self::Business => 'C',
            self::First => 'F',
        };
    }

    /**
     * The cabin for an IATA code, or null when nothing matches.
     *
     * The inverse of code(), for reading cabins back out of stored data --
     * `aircraft_cabins.cabin` holds these codes. Unlike fromRequest() this does
     * not fall back to economy: a code that does not resolve is bad data, not a
     * traveller who left the select alone.
     */
    public static function tryFromCode(string $code): ?self
    {
        foreach (self::cases() as $cabin) {
            if ($cabin->code() === $code) {
                return $cabin;
            }
        }

        return null;
    }

    /** What a passenger reads, in the form's own wording. */
    public function label(): string
    {
        return match ($this) {
            self::Economy => 'Economy',
            self::PremiumEconomy => 'Premium Economy',
            self::Business => 'Business',
            self::First => 'First',
        };
    }

    /**
     * This cabin's bit in a flight's `cabins` mask.
     *
     * Powers of two in cabin order, so a mask can carry any combination --
     * which is the point, since a flight selling Economy and Business without
     * Premium Economy is the normal short-haul shape rather than an oddity.
     */
    public function bit(): int
    {
        return match ($this) {
            self::Economy => 1,
            self::PremiumEconomy => 2,
            self::Business => 4,
            self::First => 8,
        };
    }

    /**
     * How much of the full long-haul premium this cabin commands, as a share of
     * the economy fare: business at 2.2 sells for 3.2x economy on a long leg.
     *
     * These are published-fare ratios rather than anything derived -- economy
     * is the anchor and every other cabin is quoted against it.
     */
    private function uplift(): float
    {
        return match ($this) {
            self::Economy => 0.0,
            self::PremiumEconomy => 0.6,
            self::Business => 2.2,
            self::First => 4.5,
        };
    }

    /**
     * What multiple of the economy fare this cabin costs over `$distance` km.
     *
     * The premium is not flat: short-haul business is a recliner with the
     * middle seat blocked and sells for about twice economy, while long-haul
     * business is a flat bed and sells for over three times it. So the uplift
     * is scaled by haul -- HAUL_FLOOR of it on the shortest hop, all of it from
     * HAUL_FULL_KM out -- rather than applied whole at every distance.
     *
     * Economy has no uplift, so this is exactly 1.0 for it at every distance.
     * That is deliberate: the default cabin must not reprice anything.
     */
    public function priceMultiplier(int $distance): float
    {
        return 1.0 + $this->uplift() * self::haul($distance);
    }

    /**
     * The same multiplier as SQL, over a flight alias's distance column.
     *
     * Search prices whole itineraries in one statement -- sorting and the price
     * slider both read the total the cards will show -- so the multiplier has
     * to exist in SQL as well as in PHP. Kept beside priceMultiplier() and
     * built from the same constants so the two cannot drift, the same
     * arrangement FarePricing uses for the fare itself.
     *
     * Returns null for economy, whose multiplier is 1.0 at every distance:
     * callers then leave the column alone instead of multiplying by one.
     */
    public function sqlPriceMultiplier(string $alias): ?string
    {
        if ($this->uplift() === 0.0) {
            return null;
        }

        return sprintf(
            '(1 + %s * (%s + %s * LEAST(1, %s.distance / %d)))',
            $this->uplift(),
            self::HAUL_FLOOR,
            1 - self::HAUL_FLOOR,
            $alias,
            self::HAUL_FULL_KM,
        );
    }

    /**
     * Share of the full premium a leg of this length carries, HAUL_FLOOR..1.
     */
    private static function haul(int $distance): float
    {
        return self::HAUL_FLOOR
            + (1 - self::HAUL_FLOOR) * min(1.0, max(0, $distance) / self::HAUL_FULL_KM);
    }
}
