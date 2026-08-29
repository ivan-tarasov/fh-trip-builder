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

    /**
     * Ascending sort key for a round-trip pairing, combining both directions'
     * aggregates. Rating is negated so higher-rated pairs sort first. Depart /
     * Arrive are one-way-only sorts (see config search.sort), so they fall back
     * to combined price here.
     *
     * @param array{price_base: float, price_tax: float, duration: int, rating: float} $out
     * @param array{price_base: float, price_tax: float, duration: int, rating: float} $in
     */
    public function pairSortKey(array $out, array $in): float
    {
        return match ($this) {
            self::Duration => $out['duration'] + $in['duration'],
            self::Rating => -(($out['rating'] + $in['rating']) / 2),
            self::Price, self::Depart, self::Arrive =>
                ($out['price_base'] + $out['price_tax']) + ($in['price_base'] + $in['price_tax']),
        };
    }
}
