<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

use TripBuilder\CabinClass;

/**
 * Immutable set of validated parameters for a flight search request.
 */
final readonly class FlightSearchQuery
{
    public function __construct(
        // How much of the ranked result to render: the list grows by appending,
        // so a "load more" asks only for the part it does not have.
        public int $offset,
        public int $limit,
        public string $sort,
        public string $from,
        public string $to,
        public string $departDate,
        public string $returnDate,
        public int $adultNum,
        public int $childNum,
        // The cabin the search asked for. Carried so the results can say which
        // cabin they describe; the flights table has no cabin column, so this
        // is the query's cabin and not the aircraft's.
        public CabinClass $cabin = CabinClass::Economy,
        public ?FlightFilters $filters = null,
        // The return leg keeps its own set: which one applies depends on the
        // step, and only the search knows that for certain.
        public ?FlightFilters $returnFilters = null,
    ) {}
}
