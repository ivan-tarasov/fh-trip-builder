<?php

declare(strict_types=1);

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
        public ?FlightFilters $filters = null,
        // The return leg keeps its own set: which one applies depends on the
        // step, and only the search knows that for certain.
        public ?FlightFilters $returnFilters = null,
    ) {}
}
