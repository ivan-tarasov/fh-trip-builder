<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

/**
 * Flight sort options and the SQL ORDER BY expression each maps to.
 *
 * Expressions reference the SELECT aliases produced by FlightRepository
 * (`out_*` for the primary leg, `in_*` for the return leg).
 */
enum SortMethod: string
{
    case Price = 'price';
    case Duration = 'duration';
    case Depart = 'depart_time';
    case Arrive = 'arrive_time';
    case Rating = 'rating';

    /**
     * Resolve a requested sort string, falling back to Price for anything
     * unknown (matches the legacy `?? SORT[...price]` behaviour).
     */
    public static function fromRequest(string $sort): self
    {
        return self::tryFrom($sort) ?? self::Price;
    }

    public function onewayOrderBy(): string
    {
        return match ($this) {
            self::Price => '(out_price_base + out_price_tax)',
            self::Duration => 'out_duration',
            self::Depart => 'out_dep_datetime',
            self::Arrive => 'out_arr_datetime',
            self::Rating => 'out_rating',
        };
    }

    public function roundtripOrderBy(): string
    {
        return match ($this) {
            self::Price, self::Depart, self::Arrive => '(out_price_base + out_price_tax + in_price_base + in_price_tax)',
            self::Duration => '(out_duration + in_duration)',
            self::Rating => '(out_rating + in_rating)',
        };
    }
}
