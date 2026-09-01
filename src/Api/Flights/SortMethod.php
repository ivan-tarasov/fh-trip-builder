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
    case LayoverShort = 'layover_short';
    case Recommended = 'recommended';

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
        // Every ordering ends with the same two keys. Without them a sort with
        // many ties — layover_minutes is 0 for every direct flight — leaves the
        // winner to the database, so the same search can order ties differently
        // between pages and the "what you get" figure on a sort tab can name an
        // itinerary the sort does not actually return first.
        return $this->primaryOrderBy() . ', (price_base + price_tax) ASC, seg1 ASC';
    }

    private function primaryOrderBy(): string
    {
        return match ($this) {
            self::Price => '(price_base + price_tax) ASC',
            self::Duration => 'duration ASC',
            self::Depart => 'depart_time ASC',
            self::Arrive => 'arrive_time ASC',
            self::Rating => 'rating DESC',
            // A direct itinerary waits nowhere, so it sorts first — which is
            // what "short layovers" should mean.
            self::LayoverShort => 'layover_minutes ASC',
            // Ranked afterwards against the whole result set (see
            // ranksAcrossResults). Price only decides which candidates survive
            // the cap, and the cheapest are the right ones to keep.
            self::Recommended => '(price_base + price_tax) ASC',
        };
    }

    /**
     * Whether this sort can only be decided once every result is known.
     *
     * "Recommended" scores each itinerary against the best and worst of the
     * set, so there is no ORDER BY that expresses it — the repository ranks it
     * after filtering instead.
     */
    public function ranksAcrossResults(): bool
    {
        return $this === self::Recommended;
    }

}
