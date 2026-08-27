<?php

namespace TripBuilder\Api\Flights;

/**
 * Immutable set of validated parameters for a flight search request.
 */
final readonly class FlightSearchQuery
{
    public function __construct(
        public int $currentPage,
        public string $sort,
        public string $from,
        public string $to,
        public string $departDate,
        public string $returnDate,
        public int $adultNum,
        public int $childNum,
    ) {
    }
}
