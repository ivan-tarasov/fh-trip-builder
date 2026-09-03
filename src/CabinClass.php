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
 * Note what this is and is not: it is the cabin somebody *asked* for, carried
 * through the search so the results describe the query. The flights table has
 * no cabin column, so it is not a claim about the seat on a given aircraft --
 * which is also why the rebuild paths in FlightFinder pass no cabin at all
 * rather than defaulting to one.
 */
enum CabinClass: string
{
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
}
