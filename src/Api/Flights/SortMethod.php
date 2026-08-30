<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

/**
 * Flight sort options and how each maps onto the candidate-itinerary columns
 * produced by FlightRepository (an itinerary is direct or a connection, with
 * pre-aggregated `price_base`/`price_tax`, total `duration`, `depart_time`,
 * `arrive_time`, and average `rating`).
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

    /**
     * ORDER BY clause (expression + direction) over the candidate-itinerary
     * columns. Rating sorts highest-first; the rest lowest-first.
     */
    public function candidateOrderBy(): string
    {
        return match ($this) {
            self::Price => '(price_base + price_tax) ASC',
            self::Duration => 'duration ASC',
            self::Depart => 'depart_time ASC',
            self::Arrive => 'arrive_time ASC',
            self::Rating => 'rating DESC',
        };
    }

}
