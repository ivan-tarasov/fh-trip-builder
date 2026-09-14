<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Emissions;
use TripBuilder\Party;

/**
 * Flight search: cheapest-itinerary search over direct and connecting flights.
 *
 * An "itinerary" is an ordered list of 1..(maxStops+1) legs from the origin to
 * the destination, where each connection departs within a valid layover window
 * of the previous leg's arrival. The search unions direct, 1-stop and 2-stop
 * candidates, ranks the lightweight candidate rows (ids + aggregated price /
 * duration / rating — no display joins) and only then hydrates the display data
 * for the legs on the requested page. This keeps `flights` the selective driving
 * table (all filters ride the (departure_airport, arrival_airport,
 * departure_time) index) and keeps cost bounded regardless of how many
 * combinations exist.
 *
 * Returned shape:
 *   onewaySearch  -> ['rows' => list<itinerary>, 'total' => int]
 *   roundtripSearch -> ['rows' => list<{outbound, returning, price_base, price_tax}>, 'total' => int]
 * where an itinerary is
 *   ['legs' => list<leg>, 'stops' => int, 'price_base' => float, 'price_tax' => float,
 *    'duration' => int, 'depart_time' => string, 'arrive_time' => string, 'rating' => float]
 * and a leg is one hydrated flight row (see legColumns()).
 *
 * Three shapes for one row, because it is not one row -- it is three pipeline
 * stages, and a type that claimed the last stage's keys at the first stage
 * would be phpstan trusting a lie the same way the casts it replaces did not.
 * Verified against a live fetch rather than assumed: PDO with native prepares
 * still returns a DECIMAL column as `string`, not `float` -- `price_base`,
 * `price_tax` and `rating` are strings here for that reason, and the union
 * across the direct/1-stop/2-stop branches in `candidateSql()` makes every
 * `CONCAT_WS` column a string for every row once the branches are combined,
 * even the direct ones.
 *
 * @phpstan-type RawCandidate array{
 *     seg1: int, seg2: int|null, seg3: int|null, stops: int,
 *     price_base: string, price_tax: string, duration: int,
 *     depart_time: string, arrive_time: string, rating: string,
 *     carriers: string, aircraft: string, distances: string,
 *     dep_airport: string, arr_airport: string,
 *     stops_at: string|null, layover_minutes: int,
 *     stop1_in: string|null, stop1_out: string|null,
 *     stop2_in: string|null, stop2_out: string|null,
 * }
 * @phpstan-type WithCountries array{
 *     seg1: int, seg2: int|null, seg3: int|null, stops: int,
 *     price_base: string, price_tax: string, duration: int,
 *     depart_time: string, arrive_time: string, rating: string,
 *     carriers: string, aircraft: string, distances: string,
 *     dep_airport: string, arr_airport: string,
 *     stops_at: string|null, layover_minutes: int,
 *     stop1_in: string|null, stop1_out: string|null,
 *     stop2_in: string|null, stop2_out: string|null,
 *     stop_countries: list<string>,
 *     origin_country: string|null,
 *     destination_country: string|null,
 * }
 * @phpstan-type Candidate array{
 *     seg1: int, seg2: int|null, seg3: int|null, stops: int,
 *     price_base: string, price_tax: string, duration: int,
 *     depart_time: string, arrive_time: string, rating: string,
 *     carriers: string, aircraft: string, distances: string,
 *     dep_airport: string, arr_airport: string,
 *     stops_at: string|null, layover_minutes: int,
 *     stop1_in: string|null, stop1_out: string|null,
 *     stop2_in: string|null, stop2_out: string|null,
 *     stop_countries: list<string>,
 *     origin_country: string|null,
 *     destination_country: string|null,
 *     co2_kg: float|null,
 *     co2_typical: bool|null,
 *     price_offset?: float,
 * }
 *
 * One hydrated flight (see legColumns()) and one assembled itinerary, both
 * verified the same way: `aircraft_name`, `aircraft_widebody`, `seat_layout`,
 * `seat_pitch`, `seat_width`, `seat_flat_bed`, `aircraft_seats`, `dep_country`
 * and `arr_country` are nullable because their joins are LEFT on purpose --
 * an aircraft type this data does not carry a name for, or has not fitted a
 * cabin on, still returns a leg, just without that half of it. This data
 * happens not to exercise every one of those gaps today, which is why the
 * nullability comes from reading the joins rather than from a row that
 * proved it -- `FlightRepositoryTest`'s own `DANGLING_AIRPORT` /
 * `DANGLING_COUNTRY` fixtures exist because this has bitten before.
 *
 * @phpstan-type LegRow array{
 *     id: int, carrier: string, carrier_name: string, number: int,
 *     dep_code: string, dep_name: string, dep_country: string|null,
 *     dep_city: string, dep_datetime: string,
 *     arr_code: string, arr_name: string, arr_country: string|null,
 *     arr_city: string, arr_datetime: string,
 *     aircraft_code: string, aircraft_name: string|null,
 *     aircraft_widebody: int|null,
 *     seat_layout: string|null, seat_pitch: int|null,
 *     seat_width: string|null, seat_flat_bed: int|null,
 *     aircraft_seats: string|null,
 *     distance: int, duration: int,
 *     price_base: string, price_tax: string, rating: string,
 * }
 * @phpstan-type Itinerary array{
 *     legs: list<LegRow>, badges: list<string>, stops: int,
 *     price_base: float, price_tax: float, duration: int,
 *     depart_time: string, arrive_time: string, rating: float,
 *     co2_kg: float|null, co2_typical: bool|null,
 * }
 *
 * `cheapestPerDestinationCity()`'s own shape, live-verified: `duration` is a
 * plain `int` column, `total` (`price_base + price_tax`) is DECIMAL
 * arithmetic and stays `string`, `rn` (the window function) is `int`.
 *
 * @phpstan-type CheapestDestinationRow array{
 *     to_city_code: string, to_city: string, from_city_code: string, from_city: string,
 *     airline: string, departure_airport: string, arrival_airport: string,
 *     departure_time: string, arrival_time: string, duration: int, total: string, rn: int,
 * }
 *
 * `cheapestDirectPerOrigin()`'s shape is the same columns minus the
 * destination-city pair -- the caller already knows which city that is --
 * so the same live-verified types apply.
 *
 * @phpstan-type CheapestOriginRow array{
 *     from_city_code: string, from_city: string,
 *     airline: string, departure_airport: string, arrival_airport: string,
 *     departure_time: string, arrival_time: string, duration: int, total: string, rn: int,
 * }
 *
 * Four more live-verified memoised/lookup shapes: `airports.code`,
 * `airports.country_code`, `aircraft.code` and `aircraft_cabins.aircraft` /
 * `cabin` are all non-nullable `char` columns; `aircraft_cabins.seats` is a
 * plain `smallint` and stays `int`; `aircraft.fuel_burn_kg_per_km` is
 * DECIMAL and stringifies like every other DECIMAL column in this file.
 *
 * @phpstan-type AirportCountryRow array{code: string, country_code: string}
 * @phpstan-type AircraftBurnRow array{code: string, fuel_burn_kg_per_km: string}
 * @phpstan-type AircraftCabinRow array{aircraft: string, cabin: string, seats: int}
 * @phpstan-type AirportCodeRow array{code: string}
 * @phpstan-type FareBrandRow array{id: int, fare_brand: string|null}
 */
final readonly class FlightRepository
{
    // Cap the candidate count so a very connective route can't make the COUNT
    // scan every 2-stop combination; results past this show as "N+".
    //
    // Filters are applied to these rows after they come back, so the cap also
    // bounds what a filter can see — too low and a selective filter would search
    // only the best N. Measured at 500/1000/2000/5000 across the densest routes
    // in this data (LON-NYC, PAR-NYC, LON-PAR, and multi-airport pairs both
    // ways): the union is materialised and sorted regardless, so the limit only
    // bounds transfer and every setting timed the same. The densest route
    // produced 353 candidates, so this is headroom rather than a live constraint.
    private const int COUNT_CAP = 2000;

    // A connecting leg departs within this many days of the search date. This
    // constant bound lets the index seek the connecting leg by date (the exact
    // correlated layover window still filters on top); without it MySQL would
    // scan every flight on that route across the whole schedule.
    private const int CONNECT_DATE_BUFFER_DAYS = 3;

    // Highlighting a "cheapest" or "fastest" option only means something when
    // there are a few to choose between.
    private const int BADGE_MIN_CHOICES = 3;

    // And "lower than typical for this route" means nothing at all with two
    // itineraries, where one of them is always the lower.
    private const int CO2_MIN_CHOICES = 3;

    // How much the balanced pick leans on fare over elapsed time.
    private const float BADGE_PRICE_WEIGHT = 0.6;

    public function __construct(private Connection $connection) {}

    /**
     * Itineraries for one direction of a trip (origin -> destination on a date),
     * ranked and paginated. A round trip searches each direction separately —
     * the outbound first, then the return — rather than pairing every outbound
     * with every return.
     *
     * `cheapest` is the lowest total among the results this search can show, so
     * a row can be priced relative to it without a second query.
     *
     * `available` reports, per filter dimension, which options would still
     * return something — that is what greys out a control the sidebar cannot
     * usefully offer.
     *
     * `bounds` gives each slider its ends, measured the same way, and
     * `highlights` says what each sort option would put first — the price and
     * the travel time you would get by choosing it.
     *
     * @return array{rows: list<Itinerary>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>, option_prices: array<string, array<array-key, float>>, bounds: array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>, highlights: array<string, array{price: float, duration: int}>}
     */
    public function searchDirection(
        string $from,
        string $to,
        string $departDate,
        SortMethod $sort,
        int $offset,
        int $limit,
        CabinClass $cabin,
        ?FlightFilters $filters = null,
        float $priceOffset = 0.0,
        // Days the departure may fall on, itself included.
        int $span = 1,
    ): array {
        $filters ??= new FlightFilters();
        $empty = ['rows' => [], 'total' => 0, 'cheapest' => null, 'available' => [], 'option_prices' => [], 'bounds' => [], 'highlights' => []];

        $fromCodes = $this->resolveAirportCodes($from);
        $toCodes = $this->resolveAirportCodes($to);

        if ($fromCodes === [] || $toCodes === []) {
            return $empty;
        }

        [$candidateSql, $params] = $this->candidateSql($fromCodes, $toCodes, $departDate, $cabin, $span);

        // One ranked pass over the candidates (lightweight rows), capped so a very
        // connective route can't sort an unbounded set. The page and total both
        // come from this single result; only the page's legs are then hydrated.
        //
        // Remembered, because this statement *is* the cost of a search: 76 to
        // 200ms measured, against about 4ms for the whole of the rest of this
        // method. Every "show more" used to run it again and slice a different
        // window, so paging multiplied the work rather than dividing it -- five
        // slices were five searches (E31, #219).
        //
        // Filters, the party and the sort's later passes are applied to these
        // rows afterwards, so a filter change reads the cache too rather than
        // only a second page.
        $sql = $candidateSql . ' ORDER BY ' . $sort->candidateOrderBy() . ' LIMIT ' . (self::COUNT_CAP + 1);
        $cache = new SearchCandidateRepository($this->connection);
        $key = $cache->keyFor($sql, $params);

        $candidates = $cache->get($key);

        if ($candidates === null) {
            $candidates = $this->connection->fetchAll($sql, $params);
            $cache->put($key, $candidates);
        }

        // SearchCandidateRepository is generic -- any search's rows pass
        // through it -- so its own return type stays untyped. This is the one
        // place the columns candidateSql() actually selects are named.
        /** @var list<RawCandidate> $candidates */
        if ($candidates === []) {
            return $empty;
        }

        // Filters are applied here rather than in the SQL above: that query is
        // the hot path and its joins were tuned around a fixed shape, while a
        // candidate row already carries everything a filter asks about.
        $candidates = $this->withLayoverCountries($candidates);
        // Measured against every itinerary the route offers rather than every
        // one that survives the sidebar, so "lower than typical" says something
        // about the route and does not move as filters are chosen.
        $candidates = $this->withEmissions($candidates, $cabin);
        // Half of a round trip is priced as the whole trip on screen, so the
        // price filter and its slider work against that same total rather than
        // this direction's share of it.
        $candidates = $this->withPriceOffset($candidates, $priceOffset);
        ['available' => $available, 'prices' => $optionPrices] = $this->availability($candidates, $filters);
        $bounds = $this->bounds($candidates, $filters);
        $matching = array_values(array_filter(
            $candidates,
            static fn(array $c): bool => $filters->matches($c),
        ));

        if ($matching === []) {
            return ['rows' => [], 'total' => 0, 'cheapest' => null, 'available' => $available, 'option_prices' => $optionPrices, 'bounds' => $bounds, 'highlights' => []];
        }

        // A sort that scores an itinerary against the rest of the set can only
        // be resolved now, with filtering done and the whole result in hand.
        if ($sort->ranksAcrossResults()) {
            $matching = match ($sort) {
                SortMethod::Emissions => $this->rankByEmissions($matching),
                default => $this->rankByValue($matching),
            };
        }

        $total = min(count($matching), self::COUNT_CAP);

        // Badges are decided across every match, not just this page, so
        // "cheapest" means cheapest of what the search can actually show.
        $badges = count($matching) >= self::BADGE_MIN_CHOICES ? $this->badgeKeys($matching) : [];

        // A window into the ranked set rather than a page of it: the list grows
        // by appending, so a "load more" asks for what it does not have yet.
        // Only this window's legs are hydrated, which is what keeps a later
        // request as cheap as the first.
        $window = array_slice($matching, max(0, $offset), max(0, $limit));

        $legs = $this->hydrateLegs($this->collectLegIds($window), $cabin);

        // array_filter, because an itinerary whose legs did not all hydrate is
        // dropped rather than shown short. `total` is counted from candidates
        // and can then be one high, which is a cosmetic inaccuracy; a price for
        // legs that are not on the card is not.
        $rows = array_values(array_filter(array_map(
            fn(array $c): ?array => $this->assembleItinerary($c, $legs, $badges),
            $window,
        )));

        // Across every match, not just this page — otherwise page two would
        // call its own first row the cheapest. Zero where there is nothing to
        // compare: min() on an empty array is fatal, and a search with no
        // matches has no cheapest.
        $cheapest = $matching === [] ? 0.0 : min(array_map(
            // Without the offset: FlightFinder adds the other half itself, and
            // adding it twice would quote a round trip at double.
            fn(array $c): float => $this->displayTotal(
                ['price_base' => $c['price_base'], 'price_tax' => $c['price_tax']],
                $filters->party,
            ),
            $matching,
        ));

        return ['rows' => $rows, 'total' => $total, 'cheapest' => $cheapest, 'available' => $available, 'option_prices' => $optionPrices,
            'bounds' => $bounds, 'highlights' => $this->highlights($matching, $filters->party)];
    }

    /**
     * Which value of each dimension would still return something.
     *
     * A dimension is measured with its own filter lifted, so the options on
     * offer narrow as you choose elsewhere without the dimension you are
     * choosing in collapsing to the one value you picked.
     *
     * @param list<Candidate> $candidates
     * @return array{available: array<string, list<string>|list<int>|bool>, prices: array<string, array<array-key, float>>}
     */
    private function availability(array $candidates, FlightFilters $filters): array
    {
        $available = [];
        // What each option would cost, keyed the same way, so the sidebar can
        // print a price beside every row.
        $prices = [];

        // Airlines, aircraft and layover airports all match on any leg, so
        // appearing anywhere is the same as being selectable.
        foreach ([
            FlightFilters::DIM_AIRLINES => 'carriers',
            FlightFilters::DIM_AIRCRAFT => 'aircraft',
            FlightFilters::DIM_LAYOVER_AIRPORTS => 'stops_at',
        ] as $dimension => $column) {
            $byValue = $this->distinct(
                $candidates,
                $filters,
                $dimension,
                static function (array $c) use ($column): array {
                    $raw = (string) ($c[$column] ?? '');

                    return $raw === '' ? [] : explode(',', $raw);
                },
            );

            $available[$dimension] = array_keys($byValue);
            $prices[$dimension] = $byValue;
        }

        $singles = [
            FlightFilters::DIM_DEPART_AIRPORTS => 'dep_airport',
            FlightFilters::DIM_ARRIVE_AIRPORTS => 'arr_airport',
        ];

        foreach ($singles as $dimension => $column) {
            $byValue = $this->distinct($candidates, $filters, $dimension, static fn(array $c): array => [(string) $c[$column]]);
            $available[$dimension] = array_keys($byValue);
            $prices[$dimension] = $byValue;
        }

        $byStops = $this->distinct($candidates, $filters, FlightFilters::DIM_STOPS, static fn(array $c): array => [(string) $c['stops']]);
        $available[FlightFilters::DIM_STOPS] = array_map(intval(...), array_keys($byStops));
        $prices[FlightFilters::DIM_STOPS] = $byStops;

        $byDate = $this->distinct(
            $candidates,
            $filters,
            FlightFilters::DIM_ARRIVE_DATE,
            static fn(array $c): array => [date('Y-m-d', (int) strtotime((string) $c['arrive_time']))],
        );
        $available[FlightFilters::DIM_ARRIVE_DATE] = array_keys($byDate);
        $prices[FlightFilters::DIM_ARRIVE_DATE] = $byDate;

        foreach ([FlightFilters::DIM_DEPART_TIME => 'depart_time', FlightFilters::DIM_ARRIVE_TIME => 'arrive_time'] as $dimension => $column) {
            $byBucket = $this->distinct(
                $candidates,
                $filters,
                $dimension,
                static fn(array $c): array => [self::bucketOf((string) $c[$column])],
            );
            $available[$dimension] = array_keys($byBucket);
            $prices[$dimension] = $byBucket;
        }

        // A toggle is worth offering only if switching it on leaves something.
        //
        // Named methods, not closures -- a closure's own parameter type is
        // what PHPStan checks its body against, and a docblock placed above
        // a closure literal does not change that, verified with isolated
        // test cases the way the stdClass narrowing question was.
        foreach ([
            FlightFilters::DIM_SINGLE_CARRIER => self::singleCarrier(...),
            FlightFilters::DIM_NO_NIGHT => self::noNightLayover(...),
            FlightFilters::DIM_NO_GULF => self::noGulfLayover(...),
            FlightFilters::DIM_NO_VISA => self::noVisaLayover(...),
            FlightFilters::DIM_LOWER_CO2 => self::lowerCo2(...),
        ] as $dimension => $wouldKeep) {
            $available[$dimension] = false;
            $cheapest = null;

            foreach ($candidates as $candidate) {
                if ($filters->matches($candidate, $dimension) && $wouldKeep($candidate)) {
                    $available[$dimension] = true;
                    $total = $this->displayTotal($candidate, $filters->party);
                    $cheapest = $cheapest === null ? $total : min($cheapest, $total);
                }
            }

            if ($cheapest !== null) {
                $prices[$dimension] = ['1' => $cheapest];
            }
        }

        return ['available' => $available, 'prices' => $prices];
    }

    /**
     * @param Candidate $candidate
     */
    private static function singleCarrier(array $candidate): bool
    {
        return count(array_unique(explode(',', $candidate['carriers']))) <= 1;
    }

    /**
     * @param Candidate $candidate
     */
    private static function noNightLayover(array $candidate): bool
    {
        return new FlightFilters(noNightLayover: true)->matches($candidate);
    }

    /**
     * @param Candidate $candidate
     */
    private static function noGulfLayover(array $candidate): bool
    {
        return new FlightFilters(noGulfLayover: true)->matches($candidate);
    }

    /**
     * @param Candidate $candidate
     */
    private static function noVisaLayover(array $candidate): bool
    {
        return new FlightFilters(noVisaLayover: true)->matches($candidate);
    }

    /**
     * @param Candidate $candidate
     */
    private static function lowerCo2(array $candidate): bool
    {
        return new FlightFilters(lowerCo2: true)->matches($candidate);
    }

    /**
     * What each sort option would put at the top: its price and travel time.
     *
     * Sorting is a trade — cheapest is rarely quickest — and the choice is
     * blind unless both numbers are on the control. One pass per option over
     * the same rows the page was built from, so this costs nothing extra.
     *
     * @param list<Candidate> $candidates
     * @return array<string, array{price: float, duration: int}>
     */
    private function highlights(array $candidates, Party $party): array
    {
        if ($candidates === []) {
            return [];
        }

        $scores = $this->valueScores($candidates);

        // Named methods, not closures, for the same reason availability()'s
        // toggle map uses them -- a closure's body is checked against its own
        // parameter type, which a docblock above the literal cannot change.
        // Every ranker takes the same four arguments so they share one array
        // shape, even though most ignore $i, $scores or $party.
        //
        // How each option decides which itinerary wins. Lower is better in all
        // of them except rating, which is negated to keep one comparison.
        $rank = [
            SortMethod::Recommended->value => $this->rankRecommended(...),
            SortMethod::Price->value => $this->rankPrice(...),
            SortMethod::Duration->value => $this->rankDuration(...),
            SortMethod::LayoverShort->value => $this->rankLayoverShort(...),
            SortMethod::Rating->value => $this->rankRating(...),
            SortMethod::Depart->value => $this->rankDepart(...),
            SortMethod::Arrive->value => $this->rankArrive(...),
            // Unknown sorts last here too, so the tab never advertises an
            // itinerary the sort would not put first.
            SortMethod::Emissions->value => $this->rankEmissions(...),
        ];

        $highlights = [];

        foreach ($rank as $sort => $key) {
            $winner = null;
            $best = null;

            foreach ($candidates as $i => $candidate) {
                // Same tie-break as the SQL ordering, or a sort with many equal
                // rows would advertise a different one than it returns.
                $value = [$key($candidate, $i, $scores, $party), $this->highlightTotal($candidate, $party), (int) $candidate['seg1']];

                if ($best === null || $value < $best) {
                    $best = $value;
                    $winner = $candidate;
                }
            }

            if ($winner !== null) {
                $highlights[$sort] = [
                    'price' => $this->highlightTotal($winner, $party),
                    'duration' => (int) $winner['duration'],
                ];
            }
        }

        return $highlights;
    }

    /**
     * @param Candidate $candidate
     */
    private function highlightTotal(array $candidate, Party $party): float
    {
        $priced = $party->apply((float) $candidate['price_base'], (float) $candidate['price_tax']);

        return $priced['base'] + $priced['tax'];
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankRecommended(array $candidate, int $i, array $scores, Party $party): float
    {
        return $scores[$i];
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankPrice(array $candidate, int $i, array $scores, Party $party): float
    {
        return $this->highlightTotal($candidate, $party);
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankDuration(array $candidate, int $i, array $scores, Party $party): float
    {
        return (float) $candidate['duration'];
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankLayoverShort(array $candidate, int $i, array $scores, Party $party): float
    {
        return (float) $candidate['layover_minutes'];
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankRating(array $candidate, int $i, array $scores, Party $party): float
    {
        return -(float) $candidate['rating'];
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankDepart(array $candidate, int $i, array $scores, Party $party): float
    {
        return (float) strtotime($candidate['depart_time']);
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankArrive(array $candidate, int $i, array $scores, Party $party): float
    {
        return (float) strtotime($candidate['arrive_time']);
    }

    /**
     * @param Candidate $candidate
     * @param list<float> $scores
     */
    private function rankEmissions(array $candidate, int $i, array $scores, Party $party): float
    {
        return (float) ($candidate['co2_kg'] ?? INF);
    }

    /**
     * The span each slider should cover: the smallest and largest value still
     * reachable, with that slider's own filter lifted so dragging it never
     * shrinks its own track out from under the handle.
     *
     * @param list<Candidate> $candidates
     * @return array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>
     */
    private function bounds(array $candidates, FlightFilters $filters): array
    {
        // A dispatch table of closures would put an untyped `array $c` between
        // here and priceMeasure()/durationMeasure()/layoverMeasure() -- an
        // arrow function's own parameter is plain `array` in PHP, and PHPStan
        // does not narrow one from where it is later called, in an array
        // literal or in a variable holding it. `$candidate` below really is
        // `Candidate`, straight from the `foreach` over `$candidates`, so a
        // `match` on the dimension keeps that rather than losing it through a
        // callable.
        $dimensions = [FlightFilters::DIM_PRICE, FlightFilters::DIM_DURATION, FlightFilters::DIM_LAYOVER_RANGE];

        $bounds = [];

        foreach ($dimensions as $dimension) {
            $lows = [];
            $highs = [];

            foreach ($candidates as $candidate) {
                if (!$filters->matches($candidate, $dimension)) {
                    continue;
                }

                $values = match ($dimension) {
                    FlightFilters::DIM_PRICE => $this->priceMeasure($candidate, $filters->party),
                    FlightFilters::DIM_DURATION => $this->durationMeasure($candidate),
                    default => $this->layoverMeasure($candidate),
                };

                // An itinerary this range cannot constrain — a direct flight
                // has no wait at all — says nothing about how far either
                // handle can travel. See the note on both ends below.
                if ($values === []) {
                    continue;
                }

                $lows[] = min($values);
                $highs[] = max($values);
            }

            if ($lows === [] || $highs === []) {
                continue;
            }

            // Rounded outwards, so the extremes stay selectable once the
            // handle snaps to a whole unit.
            $min = (int) floor(min($lows));
            $max = (int) ceil(max($highs));

            // How far each handle can travel before the results empty.
            //
            // A measure can yield several values for one itinerary — every
            // layover in it — and the filter asks that they *all* fall inside
            // the range. So the shortest wait on the route is not a usable
            // ceiling: the itinerary it belongs to has longer waits too, and
            // dragging the ceiling down there returns nothing. The lowest
            // ceiling worth offering is the shortest *longest* wait, and the
            // highest floor is the longest *shortest* one.
            //
            // Where a measure yields one value per itinerary these collapse to
            // the ends themselves, which is the same as no limit at all.
            //
            // Measured only from itineraries the range can constrain, both
            // ends. The ceiling used to drop to `min` whenever a direct flight
            // was on offer, on the reasoning that a flight with no wait meets
            // any ceiling. It does — but only a ceiling with no floor under
            // it, and that is not what the control submits:
            // sidebar/slider.html.twig writes `value="{from}-{to}"`, so the
            // floor handle rides along at `min` even untouched, and
            // FlightFilters::waitsWithin() refuses an itinerary with no waits
            // the moment a range has a floor. That left a stretch of ceiling
            // track which could only ever answer with an empty page — the one
            // thing these bounds exist to prevent. It reached CI as an
            // intermittent failure, on the runs where the generator happened
            // to put a direct flight on a connecting route. See
            // LayoverRangeWithADirectFlightTest.
            $bounds[$dimension] = [
                'min' => $min,
                'max' => $max,
                'floor_max' => (int) floor(max($lows)),
                'ceiling_min' => (int) ceil(min($highs)),
            ];
        }

        return $bounds;
    }

    /**
     * The price slider's measure: what `bounds()` scores one itinerary by on
     * the price dimension. Includes the offset and the party, so the slider
     * spans what the cards say -- base and tax carry different shares and this
     * is the last place they are apart.
     *
     * @param Candidate $candidate
     * @return list<float>
     */
    private function priceMeasure(array $candidate, Party $party): array
    {
        return [$this->displayTotal($candidate, $party)];
    }

    /**
     * The duration slider's measure.
     *
     * @param Candidate $candidate
     * @return list<float>
     */
    private function durationMeasure(array $candidate): array
    {
        return [(float) $candidate['duration']];
    }

    /**
     * The layover slider's measure: every wait, not their total, since the
     * slider constrains connections one at a time and its ends have to span
     * single waits.
     *
     * @param Candidate $candidate
     * @return list<float>
     */
    private function layoverMeasure(array $candidate): array
    {
        return array_map(floatval(...), FlightFilters::waits($candidate));
    }

    /**
     * How good each itinerary is on price against travel time, lowest best.
     *
     * Both are min-max scaled across the set being ranked, so the score says
     * "how far from the best on offer" rather than comparing dollars to
     * minutes. This is the one definition of a good itinerary in the app: the
     * "Best value" badge marks its minimum and the Best sort orders by it, so
     * the top row of that sort is the badged one.
     *
     * @param list<Candidate> $candidates
     * @return list<float>
     */
    private function valueScores(array $candidates): array
    {
        // Nothing to score against: min() and max() are fatal on an empty
        // array, and a scale built from no candidates means nothing anyway.
        if ($candidates === []) {
            return [];
        }

        $prices = [];
        $durations = [];

        foreach ($candidates as $candidate) {
            $prices[] = (float) $candidate['price_base'] + (float) $candidate['price_tax'];
            $durations[] = (int) $candidate['duration'];
        }

        $cheapest = min($prices);
        $quickest = min($durations);
        // Guard the degenerate case where every option costs or lasts the same.
        $priceSpan = max(1e-9, max($prices) - $cheapest);
        $durationSpan = max(1, max($durations) - $quickest);

        $scores = [];

        foreach ($prices as $i => $price) {
            $scores[] = self::BADGE_PRICE_WEIGHT * (($price - $cheapest) / $priceSpan)
                + (1 - self::BADGE_PRICE_WEIGHT) * (($durations[$i] - $quickest) / $durationSpan);
        }

        return $scores;
    }

    /**
     * Order itineraries by value, best first, keeping the incoming order as the
     * tie-break so equal scores stay stable from one page to the next.
     *
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function rankByValue(array $candidates): array
    {
        $scores = $this->valueScores($candidates);
        $order = array_keys($candidates);

        usort($order, static fn(int $a, int $b): int => $scores[$a] <=> $scores[$b] ?: $a <=> $b);

        return array_map(static fn(int $i): array => $candidates[$i], $order);
    }

    /**
     * What each value of a dimension would cost and how often it appears,
     * across the candidates that pass every other filter. Commonest first.
     *
     * Frequency rather than alphabet: a list of sixty airlines is only useful
     * if the ones actually flying this route are at the top, and the eight rows
     * shown before "Show all" should be the eight worth seeing. Ties break
     * alphabetically so the order is stable between searches.
     *
     * The price is the cheapest itinerary carrying that value — what you would
     * pay if you picked it and nothing else changed.
     *
     * @param list<Candidate> $candidates
     * @param callable(Candidate): list<string> $values
     * @return array<array-key, float> value => cheapest total, ordered
     *         (PHP narrows numeric keys such as a stop count to int)
     */
    private function distinct(array $candidates, FlightFilters $filters, string $dimension, callable $values): array
    {
        $counts = [];
        $prices = [];

        foreach ($candidates as $candidate) {
            if (!$filters->matches($candidate, $dimension)) {
                continue;
            }

            $total = $this->displayTotal($candidate, $filters->party);

            // Once per itinerary, however many of its legs use the value —
            // otherwise a carrier flying both legs of a connection outranks one
            // flying a whole other itinerary.
            foreach (array_unique($values($candidate)) as $value) {
                if ($value === '') {
                    continue;
                }

                $counts[$value] = ($counts[$value] ?? 0) + 1;
                $prices[$value] = min($prices[$value] ?? $total, $total);
            }
        }

        uksort($counts, static fn(string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));

        $ordered = [];

        foreach (array_keys($counts) as $value) {
            $ordered[(string) $value] = $prices[$value];
        }

        return $ordered;
    }

    /**
     * An itinerary's price as the cards will show it, including whatever the
     * other half of a round trip adds.
     *
     * Not `Candidate` -- the three keys this actually reads, because one call
     * site (searchDirection()'s `$cheapest`) builds a bare array with only
     * these two rather than passing a real candidate row, and a full
     * `Candidate` here would have refused it. That call site was correct and
     * this signature was overclaiming what the function needs.
     *
     * @param array{price_base: string, price_tax: string, price_offset?: float} $candidate
     */
    private function displayTotal(array $candidate, Party $party): float
    {
        // Base and tax carry different shares -- a lap infant pays a token fare
        // and no tax -- so they are scaled apart and added after. The offset is
        // the other half of a round trip, so it rides with the base.
        $priced = $party->apply(
            (float) $candidate['price_base'] + (float) ($candidate['price_offset'] ?? 0),
            (float) $candidate['price_tax'],
        );

        return $priced['base'] + $priced['tax'];
    }

    /**
     * Which time-of-day bucket a stamp falls in, or '' when the buckets do not
     * cover it (they should, but a misconfigured range must not invent one).
     */
    private static function bucketOf(string $stamp): string
    {
        $at = (int) date('G', (int) strtotime($stamp)) * 60 + (int) date('i', (int) strtotime($stamp));

        /** @var array<string, array{from: int, to: int}> $buckets */
        $buckets = (array) Config::get('search.filters.time_buckets', []);

        foreach ($buckets as $key => $range) {
            if ($at >= (int) $range['from'] && $at < (int) $range['to']) {
                return (string) $key;
            }
        }

        return '';
    }

    /**
     * Tag each candidate with the countries its layovers and endpoints sit in,
     * so the visa and Gulf filters can be decided without joining `airports`
     * into the search query.
     *
     * @param list<RawCandidate> $candidates
     * @return list<WithCountries>
     */
    private function withLayoverCountries(array $candidates): array
    {
        $countries = $this->airportCountries();

        return array_map(static function (array $candidate) use ($countries): array {
            $stops = (string) ($candidate['stops_at'] ?? '');

            $candidate['stop_countries'] = $stops === ''
                ? []
                : array_values(array_filter(array_map(
                    static fn(string $code): ?string => $countries[$code] ?? null,
                    explode(',', $stops),
                )));

            $candidate['origin_country'] = $countries[(string) $candidate['dep_airport']] ?? null;
            $candidate['destination_country'] = $countries[(string) $candidate['arr_airport']] ?? null;

            return $candidate;
        }, $candidates);
    }

    /**
     * Record on each candidate what will be added to its price before it is
     * shown, so a filter can compare against the displayed figure.
     *
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function withPriceOffset(array $candidates, float $offset): array
    {
        if ($offset <= 0.0) {
            return $candidates;
        }

        return array_map(static function (array $candidate) use ($offset): array {
            $candidate['price_offset'] = $offset;

            return $candidate;
        }, $candidates);
    }

    /**
     * Airport code to country, memoised — a few hundred rows that every
     * candidate in a search looks up.
     *
     * @return array<string, string>
     */
    private function airportCountries(): array
    {
        // Static rather than an instance field because the repository is
        // readonly, and per-process rather than per-call because a round trip
        // searches twice and the airport list does not move between them.
        /** @var array<string, string>|null $map */
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        /** @var list<AirportCountryRow> $rows */
        $rows = $this->connection->fetchAll('SELECT code, country_code FROM ' . Table::Airports->value);

        foreach ($rows as $row) {
            $map[$row['code']] = $row['country_code'];
        }

        return $map;
    }

    /**
     * A CO2 figure per candidate, and whether it beats the route's middle.
     *
     * `co2_kg` is kilograms for one seat over the whole itinerary and is null
     * where any leg's type has no published burn -- an estimate missing a leg
     * is not a smaller estimate. `co2_typical` marks the ones at or below the
     * median, which is what "lower than typical for this route" means here: the
     * middle of what this route actually offers on this day, not a figure from
     * somewhere else (C5, #154).
     *
     * @param list<WithCountries> $candidates
     * @return list<Candidate>
     */
    private function withEmissions(array $candidates, CabinClass $cabin): array
    {
        $burn = $this->aircraftBurn();
        $seats = $this->aircraftSeats();

        foreach ($candidates as $i => $candidate) {
            $candidates[$i]['co2_kg'] = $this->itineraryEmissions($candidate, $cabin, $burn, $seats);
            $candidates[$i]['co2_typical'] = null;
        }

        $known = array_values(array_filter(
            array_column($candidates, 'co2_kg'),
            static fn(?float $kg): bool => $kg !== null,
        ));

        if (count($known) < self::CO2_MIN_CHOICES) {
            return $candidates;
        }

        $median = self::median($known);

        foreach ($candidates as $i => $candidate) {
            $kg = $candidate['co2_kg'];
            $candidates[$i]['co2_typical'] = $kg === null ? null : $kg <= $median;
        }

        return $candidates;
    }

    /**
     * Kilograms for one seat across every leg, or null when a leg cannot say.
     *
     * The type codes and the distances travel as two parallel comma-separated
     * lists on the candidate, in leg order, because the alternative was three
     * more joins in the one statement that costs anything (E31, #219).
     *
     * @param WithCountries $candidate
     * @param array<string, float> $burn
     * @param array<string, array<string, int>> $seats
     */
    private function itineraryEmissions(array $candidate, CabinClass $cabin, array $burn, array $seats): ?float
    {
        $types = explode(',', (string) $candidate['aircraft']);
        $distances = explode(',', (string) $candidate['distances']);
        $total = 0.0;

        foreach ($types as $leg => $type) {
            $kilograms = Emissions::forLeg(
                (float) ($distances[$leg] ?? 0),
                $burn[$type] ?? 0.0,
                $seats[$type] ?? [],
                $cabin,
            );

            if ($kilograms === null) {
                return null;
            }

            $total += $kilograms;
        }

        return round($total);
    }

    /**
     * The same figure for an itinerary already chosen, from its hydrated legs.
     *
     * So the outbound on the confirmation step reads the same as it did in the
     * list it was picked out of. There is no `co2_typical` to go with it: one
     * itinerary is not a route to be typical of.
     *
     * @param list<LegRow> $legs
     */
    private function legsEmissions(array $legs, CabinClass $cabin): ?float
    {
        $burn = $this->aircraftBurn();
        $seats = $this->aircraftSeats();
        $total = 0.0;

        foreach ($legs as $leg) {
            $kilograms = Emissions::forLeg(
                (float) $leg['distance'],
                $burn[(string) $leg['aircraft_code']] ?? 0.0,
                $seats[(string) $leg['aircraft_code']] ?? [],
                $cabin,
            );

            if ($kilograms === null) {
                return null;
            }

            $total += $kilograms;
        }

        return round($total);
    }

    /**
     * Cruise fuel burn per type, memoised. Twenty-eight rows.
     *
     * @return array<string, float>
     */
    private function aircraftBurn(): array
    {
        /** @var array<string, float>|null $map */
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        /** @var list<AircraftBurnRow> $rows */
        $rows = $this->connection->fetchAll('SELECT code, fuel_burn_kg_per_km FROM ' . Table::Aircraft->value);

        foreach ($rows as $row) {
            $map[$row['code']] = (float) $row['fuel_burn_kg_per_km'];
        }

        return $map;
    }

    /**
     * Seats fitted per type per cabin, memoised. Seventy-four rows.
     *
     * @return array<string, array<string, int>>
     */
    private function aircraftSeats(): array
    {
        /** @var array<string, array<string, int>>|null $map */
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        /** @var list<AircraftCabinRow> $rows */
        $rows = $this->connection->fetchAll('SELECT aircraft, cabin, seats FROM ' . Table::AircraftCabins->value);

        foreach ($rows as $row) {
            $map[$row['aircraft']][$row['cabin']] = $row['seats'];
        }

        return $map;
    }

    /**
     * The middle value, or the mean of the middle two.
     *
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Cleanest first, with the ones that cannot say at the back.
     *
     * Not an ORDER BY like the other single-column sorts, because the figure is
     * computed here rather than stored -- see `SortMethod::ranksAcrossResults`.
     *
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    private function rankByEmissions(array $candidates): array
    {
        /**
         * @param Candidate $a
         * @param Candidate $b
         */
        usort($candidates, static function (array $a, array $b): int {
            $left = $a['co2_kg'] ?? INF;
            $right = $b['co2_kg'] ?? INF;

            // Same tie-break as the SQL ordering, so equal figures come back in
            // the same order on every page.
            return [$left, (float) $a['price_base'] + (float) $a['price_tax'], $a['seg1']]
                <=> [$right, (float) $b['price_base'] + (float) $b['price_tax'], $b['seg1']];
        });

        return $candidates;
    }

    /**
     * Rebuild a chosen itinerary from its ordered leg ids, with the aggregates
     * the display needs. Returns null unless every id resolves and the legs form
     * a connected chain — so a stale or tampered selection is rejected.
     *
     * The cabin has to be supplied: leg ids alone do not say which cabin they
     * were priced in, and rebuilding a business selection at the economy fare
     * would quote a total nobody was shown.
     *
     * @param list<int> $ids
     * @return Itinerary|null
     */
    public function itineraryByIds(array $ids, CabinClass $cabin): ?array
    {
        $legs = $this->legsByIds($ids, $cabin);

        if ($legs === [] || count($legs) !== count($ids)) {
            return null;
        }

        $priceBase = 0.0;
        $priceTax = 0.0;
        $rating = 0.0;
        // Departure and arrival are local times in (often) different timezones,
        // so elapsed time is flying time plus waiting time — never a subtraction
        // of the two stamps. This mirrors how candidateSql totals a duration.
        $duration = 0;

        foreach ($legs as $i => $leg) {
            $priceBase += (float) $leg['price_base'];
            $priceTax += (float) $leg['price_tax'];
            $rating += (float) $leg['rating'];
            $duration += (int) $leg['duration'];

            if ($i > 0) {
                // Legs must chain: each departs where the previous one landed.
                if ($legs[$i - 1]['arr_code'] !== $leg['dep_code']) {
                    return null;
                }

                // A layover is at one airport, so this subtraction is safe.
                $duration += (int) round(
                    (strtotime((string) $leg['dep_datetime']) - strtotime((string) $legs[$i - 1]['arr_datetime'])) / 60,
                );
            }
        }

        $first = $legs[0];
        $last = $legs[count($legs) - 1];

        return [
            'legs' => $legs,
            'badges' => [],
            'stops' => count($legs) - 1,
            'price_base' => $priceBase,
            'price_tax' => round($priceTax, 2),
            'duration' => $duration,
            'depart_time' => (string) $first['dep_datetime'],
            'arrive_time' => (string) $last['arr_datetime'],
            'rating' => $rating / count($legs),
            'co2_kg' => $this->legsEmissions($legs, $cabin),
            'co2_typical' => null,
        ];
    }

    /**
     * A single flight leg by id (hydrated), or null. Used by the booking flow.
     *
     * @return LegRow|null
     */
    public function findById(int $flightId, CabinClass $cabin): ?array
    {
        return $this->hydrateLegs([$flightId], $cabin)[$flightId] ?? null;
    }

    /**
     * The fare brand each leg is sold under, in the order the ids were given.
     *
     * Kept out of legColumns() on purpose: the brand is only wanted at
     * checkout, and every card on a search page would otherwise carry a column
     * it never renders.
     *
     * @param list<int> $ids
     * @return list<string|null>
     */
    public function fareBrandsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<FareBrandRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, fare_brand FROM ' . Table::Flights->value
            . ' WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row['fare_brand'];
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (array_key_exists($id, $byId)) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Hydrated legs for an ordered id list, preserving order (booking flow).
     *
     * @param list<int> $ids
     * @return list<LegRow>
     */
    public function legsByIds(array $ids, CabinClass $cabin): array
    {
        $byId = $this->hydrateLegs($ids, $cabin);

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Build the UNION ALL of direct / 1-stop / 2-stop candidate itineraries for
     * one direction. Each member emits the same columns so the union can be
     * ranked as a whole. Layover window (minutes) is inlined from config (a
     * trusted int); airport codes and dates are bound.
     *
     * Every branch keeps only flights that sell the searched cabin, and prices
     * each leg for it. Both are no-ops for economy, so the default cabin runs
     * the query this method has always built.
     *
     * The connecting branches are additionally bounded by how far the whole
     * itinerary flies -- see detourCapKm().
     *
     * @param list<string> $fromCodes
     * @param list<string> $toCodes
     * @return array{0: string, 1: list<string>, 2: list<string>, 3: list<list<string>>}
     */
    private function candidateSql(
        array $fromCodes,
        array $toCodes,
        string $date,
        CabinClass $cabin,
        int $span = 1,
    ): array {
        $flights = Table::Flights->value;
        $minc = (int) Config::get('search.connections.min_connect_minutes', 45);
        $maxc = (int) Config::get('search.connections.max_connect_minutes', 360);
        $maxStops = (int) Config::get('search.connections.max_stops', 2);
        // The outbound leg may now leave on any of `span` days, and every
        // predicate here was already a half-open range rather than an equality
        // -- so a window is the same single index seek on route_departure_time
        // that one day was, just a wider one. Not N queries, and not N seeks.
        $span = max(1, $span);
        // The connecting legs are bounded relative to the first, so their buffer
        // has to grow with it or a later departure loses its own connections.
        $buffer = self::CONNECT_DATE_BUFFER_DAYS + $span - 1;

        $fromPh = $this->placeholders($fromCodes);
        $toPh = $this->placeholders($toCodes);
        $endpoints = array_values(array_unique([...$fromCodes, ...$toCodes]));
        $endPh = $this->placeholders($endpoints);

        // Branches are kept alongside their own parameters so a caller can run
        // one on its own (see cheapestTotal). Every branch names its columns for
        // the same reason — an unnamed one cannot be used as a derived table.
        $parts = [];
        $partParams = [];

        // How far an itinerary may wander. Only the connecting tiers can: a
        // direct leg's distance *is* the direct distance, measured from the
        // same coordinates, so there is nothing for a cap to catch there.
        //
        // Applied progressively rather than only to the finished total, so the
        // join sheds a first leg that has already overshot instead of pairing
        // it with everything that connects.
        $cap = $this->detourCapKm($fromCodes, $toCodes);
        $within1 = $cap === null ? '' : sprintf(' AND f1.distance <= %d', $cap);
        $within2 = $cap === null ? '' : sprintf(' AND f1.distance + f2.distance <= %d', $cap);
        $within3 = $cap === null ? '' : sprintf(' AND f1.distance + f2.distance + f3.distance <= %d', $cap);

        // Resolved once per branch alias: the cabin is fixed for the whole
        // search, only the leg it applies to changes.
        $base1 = $this->fare('f1', 'price_base', $cabin);
        $base2 = $this->fare('f2', 'price_base', $cabin);
        $base3 = $this->fare('f3', 'price_base', $cabin);
        $tax1 = $this->fare('f1', 'price_tax', $cabin);
        $tax2 = $this->fare('f2', 'price_tax', $cabin);
        $tax3 = $this->fare('f3', 'price_tax', $cabin);
        $sells1 = $this->offersCabin('f1', $cabin);
        $sells2 = $this->offersCabin('f2', $cabin);
        $sells3 = $this->offersCabin('f3', $cabin);

        // Direct.
        $parts[] = "SELECT f1.id AS seg1, NULL AS seg2, NULL AS seg3, 0 AS stops,
            {$base1} AS price_base, {$tax1} AS price_tax,
            f1.duration AS duration,
            f1.departure_time AS depart_time, f1.arrival_time AS arrive_time,
            f1.rating AS rating,
            f1.airline AS carriers, f1.aircraft AS aircraft,
            f1.distance AS distances,
            f1.departure_airport AS dep_airport, f1.arrival_airport AS arr_airport,
            NULL AS stops_at, 0 AS layover_minutes,
            NULL AS stop1_in, NULL AS stop1_out, NULL AS stop2_in, NULL AS stop2_out
            FROM {$flights} f1
            WHERE f1.departure_airport IN ({$fromPh}) AND f1.arrival_airport IN ({$toPh})
              AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL {$span} DAY
              {$sells1}";
        $partParams[] = [...$fromCodes, ...$toCodes, $date, $date];

        // 1-stop: f1 -> f2, connecting at f1.arrival within the layover window.
        // One branch per destination airport rather than a single IN list: with a
        // constant arrival the final leg seeks (departure, arrival, time) on the
        // route index, where an IN list forces it to walk every flight leaving
        // the connection airport and filter afterwards.
        if ($maxStops >= 1) {
            foreach ($toCodes as $toCode) {
                $parts[] = "SELECT f1.id AS seg1, f2.id AS seg2, NULL AS seg3, 1 AS stops,
                    {$base1} + {$base2} AS price_base,
                    {$tax1} + {$tax2} AS price_tax,
                    f1.duration + f2.duration
                        + TIMESTAMPDIFF(MINUTE, f1.arrival_time, f2.departure_time) AS duration,
                    f1.departure_time AS depart_time, f2.arrival_time AS arrive_time,
                    (f1.rating + f2.rating) / 2 AS rating,
                    CONCAT_WS(',', f1.airline, f2.airline) AS carriers,
                    CONCAT_WS(',', f1.aircraft, f2.aircraft) AS aircraft,
                    CONCAT_WS(',', f1.distance, f2.distance) AS distances,
                    f1.departure_airport AS dep_airport, f2.arrival_airport AS arr_airport,
                    f1.arrival_airport AS stops_at,
                    TIMESTAMPDIFF(MINUTE, f1.arrival_time, f2.departure_time) AS layover_minutes,
                    f1.arrival_time AS stop1_in, f2.departure_time AS stop1_out,
                    NULL AS stop2_in, NULL AS stop2_out
                    FROM {$flights} f1
                    INNER JOIN {$flights} f2 ON f2.departure_airport = f1.arrival_airport
                        AND f2.departure_time >= f1.arrival_time + INTERVAL {$minc} MINUTE
                        AND f2.departure_time <= f1.arrival_time + INTERVAL {$maxc} MINUTE
                    WHERE f1.departure_airport IN ({$fromPh})
                      AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL {$span} DAY
                      AND f2.arrival_airport = ?
                      AND f2.departure_time >= ? AND f2.departure_time < ? + INTERVAL {$buffer} DAY
                      AND f1.arrival_airport NOT IN ({$endPh})
                      {$sells1}{$sells2}{$within1}{$within2}";
                // This exclusion was skipped here on the reasoning that a hop through
                // the origin or destination yields no valid second leg. That holds for
                // a single airport code -- nothing connects at the airport it just left
                // -- but resolveAirportCodes() returns every airport in the searched
                // city, so sibling airports were never excluded: ORY -> CDG -> LHR and
                // CDG -> LGW -> LHR both scored as one-stop itineraries whose layover
                // was in the city the traveller had just left, or the one they were
                // flying to. The two-stop branch below has always excluded them.
                $partParams[] = [...$fromCodes, $date, $date, $toCode, $date, $date, ...$endpoints];
            }
        }

        // 2-stop: f1 -> f2 -> f3, two valid connections, distinct intermediates.
        // Split per destination for the same reason as the 1-stop tier — it is
        // worth an order of magnitude when a city has several airports.
        if ($maxStops >= 2) {
            foreach ($toCodes as $toCode) {
                $parts[] = "SELECT f1.id AS seg1, f2.id AS seg2, f3.id AS seg3, 2 AS stops,
                    {$base1} + {$base2} + {$base3} AS price_base,
                    {$tax1} + {$tax2} + {$tax3} AS price_tax,
                    f1.duration + f2.duration + f3.duration
                        + TIMESTAMPDIFF(MINUTE, f1.arrival_time, f2.departure_time)
                        + TIMESTAMPDIFF(MINUTE, f2.arrival_time, f3.departure_time) AS duration,
                    f1.departure_time AS depart_time, f3.arrival_time AS arrive_time,
                    (f1.rating + f2.rating + f3.rating) / 3 AS rating,
                    CONCAT_WS(',', f1.airline, f2.airline, f3.airline) AS carriers,
                    CONCAT_WS(',', f1.aircraft, f2.aircraft, f3.aircraft) AS aircraft,
                    CONCAT_WS(',', f1.distance, f2.distance, f3.distance) AS distances,
                    f1.departure_airport AS dep_airport, f3.arrival_airport AS arr_airport,
                    CONCAT_WS(',', f1.arrival_airport, f2.arrival_airport) AS stops_at,
                    TIMESTAMPDIFF(MINUTE, f1.arrival_time, f2.departure_time)
                        + TIMESTAMPDIFF(MINUTE, f2.arrival_time, f3.departure_time) AS layover_minutes,
                    f1.arrival_time AS stop1_in, f2.departure_time AS stop1_out,
                    f2.arrival_time AS stop2_in, f3.departure_time AS stop2_out
                    FROM {$flights} f1
                    INNER JOIN {$flights} f2 ON f2.departure_airport = f1.arrival_airport
                        AND f2.departure_time >= f1.arrival_time + INTERVAL {$minc} MINUTE
                        AND f2.departure_time <= f1.arrival_time + INTERVAL {$maxc} MINUTE
                    INNER JOIN {$flights} f3 ON f3.departure_airport = f2.arrival_airport
                        AND f3.departure_time >= f2.arrival_time + INTERVAL {$minc} MINUTE
                        AND f3.departure_time <= f2.arrival_time + INTERVAL {$maxc} MINUTE
                    WHERE f1.departure_airport IN ({$fromPh})
                      AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL {$span} DAY
                      AND f2.departure_time >= ? AND f2.departure_time < ? + INTERVAL {$buffer} DAY
                      AND f3.departure_time >= ? AND f3.departure_time < ? + INTERVAL {$buffer} DAY
                      AND f3.arrival_airport = ?
                      AND f1.arrival_airport NOT IN ({$endPh})
                      AND f2.arrival_airport NOT IN ({$endPh})
                      AND f2.arrival_airport <> f1.arrival_airport
                      {$sells1}{$sells2}{$sells3}{$within1}{$within2}{$within3}";
                $partParams[] = [
                    ...$fromCodes, $date, $date, $date, $date, $date, $date,
                    $toCode, ...$endpoints, ...$endpoints,
                ];
            }
        }

        $sql = implode(' UNION ALL ', array_map(static fn(string $p): string => '(' . $p . ')', $parts));
        $params = array_merge(...$partParams);

        return [$sql, $params, $parts, $partParams];
    }

    /**
     * The cheapest direct fare into one city, from each of several origins.
     *
     * One query, not one per origin, and deliberately not the obvious query.
     * Asking for everything arriving at a city and grouping by origin is a full
     * index scan -- 683,760 rows, measured at 1,216ms -- because the only index
     * that covers a route leads with `departure_airport`, and "everything
     * arriving here" names no departure. Naming the origins first turns the same
     * question into a range scan of a couple of thousand rows: 22ms.
     *
     * ROW_NUMBER rather than GROUP BY, because the card needs the whole flight
     * -- airline, times, duration -- and not just its price. Partitioned by the
     * origin's city and not its airport: a fare from London means the cheapest
     * out of any of its three, and three rows for one city would fill the strip
     * with the same place.
     *
     * Direct flights only. Everything here is one flight, one price, one row;
     * connections are what cheapestTotal() is for and cost accordingly.
     *
     * @param list<string> $fromAirports
     * @param list<string> $toAirports
     * @return list<CheapestOriginRow>
     */
    public function cheapestDirectPerOrigin(array $fromAirports, array $toAirports, CabinClass $cabin): array
    {
        if ($fromAirports === [] || $toAirports === []) {
            return [];
        }

        $from = implode(',', array_fill(0, count($fromAirports), '?'));
        $to = implode(',', array_fill(0, count($toAirports), '?'));

        /** @var list<CheapestOriginRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT x.* FROM ('
            . ' SELECT o.city_code AS from_city_code, oc.name AS from_city,'
            . '  f.airline, f.departure_airport, f.arrival_airport,'
            . '  f.departure_time, f.arrival_time, f.duration,'
            . '  f.price_base + f.price_tax AS total,'
            . '  ROW_NUMBER() OVER ('
            . '   PARTITION BY o.city_code'
            . '   ORDER BY f.price_base + f.price_tax ASC, f.departure_time ASC'
            . '  ) AS rn'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Airports->value . ' o ON o.code = f.departure_airport'
            // The city's name, not this airport's idea of it -- see
            // CityRepository::namesSql().
            . ' JOIN (' . CityRepository::namesSql() . ') oc ON oc.code = o.city_code'
            . ' WHERE f.departure_airport IN (' . $from . ')'
            . '  AND f.arrival_airport IN (' . $to . ')'
            // Today's flights that have already left are not fares anybody can
            // buy, and a landing page showing one is worse than showing none.
            . '  AND f.departure_utc >= NOW()'
            . '  AND (f.cabins & ?)'
            . ') x WHERE x.rn = 1 ORDER BY x.total ASC',
            [...$fromAirports, ...$toAirports, $cabin->bit()],
        );

        return $rows;
    }

    /**
     * The cheapest direct fare into each city of a country, from anywhere on a
     * shortlist of origins.
     *
     * The mirror image of cheapestDirectPerOrigin: a city page asks "from
     * where", a country page asks "to which of my cities", so the partition
     * moves from the departure city to the arrival one and the row that wins is
     * the cheapest way into that city rather than out of that origin.
     *
     * The origins still have to be named, and for the same reason -- the index
     * leads with `departure_airport`, so "everything arriving in Canada" seeks
     * nothing. Naming 24 busy origin airports and letting the arrival side
     * filter runs Canada's seven cities in 10ms.
     *
     * Nothing that starts and ends in the same city. Both ends of this query
     * are airports and a city can hold three, so the United Kingdom's domestic
     * tab offered "London -- London" for a Heathrow-Gatwick hop. It is a real
     * flight; it is not a fare anybody is looking for, and it took the cheapest
     * row on the tab.
     *
     * One carrier or all of them. An airline page asks the same question of its
     * own hubs -- where is it cheap to go from here -- and the answer has to be
     * a flight that airline actually operates, or the page recommends a rival.
     *
     * @param list<string> $fromAirports
     * @param list<string> $toAirports
     * @return list<CheapestDestinationRow>
     */
    public function cheapestPerDestinationCity(
        array $fromAirports,
        array $toAirports,
        CabinClass $cabin,
        ?string $airline = null,
    ): array {
        if ($fromAirports === [] || $toAirports === []) {
            return [];
        }

        $from = implode(',', array_fill(0, count($fromAirports), '?'));
        $to = implode(',', array_fill(0, count($toAirports), '?'));

        /** @var list<CheapestDestinationRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT x.* FROM ('
            . ' SELECT d.city_code AS to_city_code, dc.name AS to_city,'
            . '  o.city_code AS from_city_code, oc.name AS from_city,'
            . '  f.airline, f.departure_airport, f.arrival_airport,'
            . '  f.departure_time, f.arrival_time, f.duration,'
            . '  f.price_base + f.price_tax AS total,'
            . '  ROW_NUMBER() OVER ('
            . '   PARTITION BY d.city_code'
            . '   ORDER BY f.price_base + f.price_tax ASC, f.departure_time ASC'
            . '  ) AS rn'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Airports->value . ' o ON o.code = f.departure_airport'
            . ' JOIN ' . Table::Airports->value . ' d ON d.code = f.arrival_airport'
            // Both ends by the city's own name -- see CityRepository::namesSql().
            . ' JOIN (' . CityRepository::namesSql() . ') oc ON oc.code = o.city_code'
            . ' JOIN (' . CityRepository::namesSql() . ') dc ON dc.code = d.city_code'
            . ' WHERE f.departure_airport IN (' . $from . ')'
            . '  AND f.arrival_airport IN (' . $to . ')'
            . '  AND d.city_code <> o.city_code'
            . ($airline === null ? '' : '  AND f.airline = ?')
            . '  AND f.departure_utc >= NOW()'
            . '  AND (f.cabins & ?)'
            . ') x WHERE x.rn = 1 ORDER BY x.total ASC',
            [
                ...$fromAirports,
                ...$toAirports,
                ...($airline === null ? [] : [$airline]),
                $cabin->bit(),
            ],
        );

        return $rows;
    }

    /**
     * The cheapest total (base + tax) for one direction, or null when it has no
     * itineraries.
     *
     * Finding it by ranking every candidate costs as much as the search itself.
     * Instead the direct and one-stop branches — which are cheap to scan — give
     * a bound, and each two-stop branch is then asked only for itineraries that
     * beat it. Every leg of a cheaper itinerary must itself cost less than the
     * bound, and so must each running total, which prunes the join early. The
     * answer is exactly the same; it is only reached with far less work.
     */
    public function cheapestTotal(string $from, string $to, string $date, CabinClass $cabin, int $span = 1): ?float
    {
        $fromCodes = $this->resolveAirportCodes($from);
        $toCodes = $this->resolveAirportCodes($to);

        if ($fromCodes === [] || $toCodes === []) {
            return null;
        }

        [, , $parts, $partParams] = $this->candidateSql($fromCodes, $toCodes, $date, $cabin, $span);

        $cheap = [];
        $cheapParams = [];
        $deep = [];

        foreach ($parts as $i => $part) {
            // Branches are emitted in stop order, so the two-stop ones carry the
            // third leg; anything else is cheap enough to scan outright.
            if (str_contains($part, 'f3.id AS seg3')) {
                $deep[] = [$part, $partParams[$i]];
            } else {
                $cheap[] = '(' . $part . ')';
                $cheapParams = [...$cheapParams, ...$partParams[$i]];
            }
        }

        $best = $cheap === [] ? null : $this->minTotal(implode(' UNION ALL ', $cheap), $cheapParams);

        foreach ($deep as [$part, $params]) {
            if ($best !== null) {
                // Prune to itineraries that could still beat the bound.
                $part = $this->boundedByPrice($part, $best, $cabin);
            }

            $found = $this->minTotal('(' . $part . ')', $params);

            if ($found !== null && ($best === null || $found < $best)) {
                $best = $found;
            }
        }

        return $best;
    }

    /**
     * Cheapest base+tax across a candidate union, or null when it matches nothing.
     *
     * @param list<string> $params
     */
    private function minTotal(string $sql, array $params): ?float
    {
        // DECIMAL arithmetic stringifies, same as every other price_base +
        // price_tax expression in this file -- MIN() over it does not change
        // that.
        /** @var string|null $value */
        $value = $this->connection->fetchValue(
            'SELECT MIN(price_base + price_tax) FROM (' . $sql . ') candidates',
            $params,
        );

        return $value === null ? null : (float) $value;
    }

    /**
     * Restrict a two-stop branch to itineraries that could cost less than the
     * bound. The running totals are what prune the join: a partial itinerary
     * already at or above the bound cannot be completed into a cheaper one.
     * The bound is a float we computed, never user input.
     *
     * The running totals are priced for the searched cabin, because the bound
     * they are compared against was. Mixing the two would not admit a wrong
     * itinerary -- understated partials only prune less -- but it would quietly
     * stop the pruning from doing anything on a premium search.
     */
    private function boundedByPrice(string $part, float $bound, CabinClass $cabin): string
    {
        $leg1 = $this->fare('f1', 'price_base', $cabin) . ' + ' . $this->fare('f1', 'price_tax', $cabin);
        $leg2 = $leg1 . ' + ' . $this->fare('f2', 'price_base', $cabin) . ' + ' . $this->fare('f2', 'price_tax', $cabin);
        $leg3 = $leg2 . ' + ' . $this->fare('f3', 'price_base', $cabin) . ' + ' . $this->fare('f3', 'price_tax', $cabin);

        return $part . sprintf(
            ' AND %s < %F AND %s < %F AND %s < %F',
            $leg1,
            $bound,
            $leg2,
            $bound,
            $leg3,
            $bound,
        );
    }

    /**
     * Fetch display rows for the given leg ids, keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, LegRow>
     */
    private function hydrateLegs(array $ids, CabinClass $cabin): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $sql = 'SELECT ' . implode(', ', $this->legColumns($cabin))
            . ' FROM ' . Table::Flights->value . ' flight'
            . ' INNER JOIN ' . Table::Airports->value . ' depart_airport ON flight.departure_airport = depart_airport.code'
            . ' INNER JOIN ' . Table::Airports->value . ' arrive_airport ON flight.arrival_airport = arrive_airport.code'
            . ' INNER JOIN ' . Table::Airlines->value . ' airline ON flight.airline = airline.code'
            // LEFT, as AirportRepository already joins this table, because a
            // country supplies a label and a notice and nothing structural.
            // Joined INNER, an airport whose country_code matched no row took
            // the whole leg out of the result -- while the candidate it came
            // from, which joins none of these tables, went on counting and
            // pricing it.
            . ' LEFT JOIN ' . Table::Countries->value . ' depart_country ON depart_airport.country_code = depart_country.code'
            . ' LEFT JOIN ' . Table::Countries->value . ' arrive_country ON arrive_airport.country_code = arrive_country.code'
            // LEFT: a flight whose type code is missing from the aircraft
            // table should still return, just without a name.
            . ' LEFT JOIN ' . Table::Aircraft->value . ' aircraft_type ON flight.aircraft = aircraft_type.code'
            // The fitted cabin, for the cabin being searched. Also LEFT, and
            // for a second reason: a type may simply not have this cabin on
            // board, in which case there is no seat to describe. The code is
            // the enum's own, never user input.
            . sprintf(
                ' LEFT JOIN %s cabin_fit ON cabin_fit.aircraft = flight.aircraft'
                . " AND cabin_fit.cabin = '%s'",
                Table::AircraftCabins->value,
                $cabin->code(),
            )
            // The cabin has to be tested here as well as in the candidate
            // query. These are ids arriving from outside -- a checkout link, a
            // saved cookie -- and without it a leg would be priced for a cabin
            // its aircraft has never had fitted. Dropping the row is what makes
            // itineraryByIds() reject the selection.
            . ' WHERE flight.id IN (' . $this->placeholders($ids) . ')'
            . $this->offersCabin('flight', $cabin);

        $byId = [];

        // The one place legColumns()'s SELECT list is named as a row shape --
        // Connection::fetchAll() stays generic, the same reason searchDirection()
        // narrows its own raw fetch locally rather than in Connection.
        /** @var list<LegRow> $rows */
        $rows = $this->connection->fetchAll($sql, $ids);

        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Collect the non-null leg ids across a set of candidate/itinerary rows.
     *
     * @param list<Candidate> $candidates
     * @return list<int>
     */
    private function collectLegIds(array $candidates): array
    {
        $ids = [];

        foreach ($candidates as $candidate) {
            foreach (['seg1', 'seg2', 'seg3'] as $seg) {
                if (($candidate[$seg] ?? null) !== null) {
                    $ids[] = (int) $candidate[$seg];
                }
            }
        }

        return $ids;
    }

    /**
     * Turn a candidate row + hydrated legs into an itinerary (ordered legs and
     * the pre-aggregated totals).
     *
     * @param Candidate $candidate
     * @param array<int, LegRow> $legs
     * @param array<string, list<string>> $badges
     * @return Itinerary|null null when a leg could not be built
     */
    private function assembleItinerary(array $candidate, array $legs, array $badges = []): ?array
    {
        $ordered = [];

        foreach (['seg1', 'seg2', 'seg3'] as $seg) {
            $id = $candidate[$seg] ?? null;

            if ($id === null) {
                continue;
            }

            // A leg the hydration could not build -- a missing airport or
            // airline -- used to be skipped while the candidate's stop count
            // and price came through untouched, so the card showed fewer legs
            // than it claimed at a price for legs it was not showing. There is
            // nothing to sell here, so there is nothing to show.
            if (!isset($legs[(int) $id])) {
                return null;
            }

            $ordered[] = $legs[(int) $id];
        }

        return [
            'legs' => $ordered,
            'badges' => $badges[$this->candidateKey($candidate)] ?? [],
            'stops' => (int) $candidate['stops'],
            'price_base' => (float) $candidate['price_base'],
            'price_tax' => (float) $candidate['price_tax'],
            'duration' => (int) $candidate['duration'],
            'depart_time' => (string) $candidate['depart_time'],
            'arrive_time' => (string) $candidate['arrive_time'],
            'rating' => (float) $candidate['rating'],
            'co2_kg' => $candidate['co2_kg'] ?? null,
            'co2_typical' => $candidate['co2_typical'] ?? null,
        ];
    }

    /**
     * Decide which candidates earn a badge, returned as candidate key => slugs.
     *
     * Cheapest and fastest are plain extremes. "Best value" is the lowest
     * combined score once fare and elapsed time are each normalised across the
     * result set, weighted toward fare — the trade-off most travellers make.
     * A nonstop is only called out when it isn't already the cheapest.
     *
     * @param list<Candidate> $candidates
     * @return array<string, list<string>>
     */
    private function badgeKeys(array $candidates): array
    {
        $prices = [];
        $durations = [];

        foreach ($candidates as $candidate) {
            $prices[] = (float) $candidate['price_base'] + (float) $candidate['price_tax'];
            $durations[] = (int) $candidate['duration'];
        }

        $scores = $this->valueScores($candidates);

        $cheapest = null;
        $fastest = null;
        $value = null;
        $nonstop = null;
        $bestScore = null;

        foreach ($candidates as $i => $candidate) {
            if ($cheapest === null || $prices[$i] < $prices[$cheapest]) {
                $cheapest = $i;
            }

            if ($fastest === null || $durations[$i] < $durations[$fastest]) {
                $fastest = $i;
            }

            if ($bestScore === null || $scores[$i] < $bestScore) {
                $bestScore = $scores[$i];
                $value = $i;
            }

            if ((int) $candidate['stops'] === 0 && ($nonstop === null || $prices[$i] < $prices[$nonstop])) {
                $nonstop = $i;
            }
        }

        $map = [];

        foreach (['cheapest' => $cheapest, 'fastest' => $fastest, 'value' => $value] as $slug => $index) {
            if ($index !== null) {
                $map[$this->candidateKey($candidates[$index])][] = $slug;
            }
        }

        if ($nonstop !== null && $nonstop !== $cheapest) {
            $map[$this->candidateKey($candidates[$nonstop])][] = 'nonstop';
        }

        return $map;
    }

    /**
     * Identity of a candidate itinerary: its leg ids in order.
     *
     * @param Candidate $candidate
     */
    private function candidateKey(array $candidate): string
    {
        return implode('-', array_filter([
            $candidate['seg1'] ?? null,
            $candidate['seg2'] ?? null,
            $candidate['seg3'] ?? null,
        ], static fn($id): bool => $id !== null));
    }

    /**
     * Resolve a search input (an airport code or a city code) to the concrete
     * airport codes it covers, so the flight filter can use an indexed
     * `departure_airport IN (…)` equality instead of a non-sargable
     * `(code = ? OR city_code = ?)` predicate.
     *
     * @return list<string>
     */
    private function resolveAirportCodes(string $codeOrCity): array
    {
        // Only airports the network actually serves: one that carries no traffic
        // can never match a flight, and carrying it in the IN list turns an
        // index lookup into a range scan on the connection joins.
        /** @var list<AirportCodeRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT code FROM ' . Table::Airports->value
            . ' WHERE (code = ? OR city_code = ?) AND enabled = 1 AND traffic_weight > 0',
            [$codeOrCity, $codeOrCity],
        );

        return array_map(self::airportCode(...), $rows);
    }

    /**
     * @param AirportCodeRow $row
     */
    private static function airportCode(array $row): string
    {
        return $row['code'];
    }

    /**
     * Furthest an itinerary between these endpoints may fly, in km.
     *
     * The larger of a multiple of the direct distance and an absolute floor --
     * see the config block for why a ratio alone does not hold across scales.
     *
     * Returns null when the direct distance cannot be measured, in which case
     * the caller leaves connecting itineraries unbounded rather than capping
     * them against a number it does not have.
     *
     * @param list<string> $fromCodes
     * @param list<string> $toCodes
     */
    private function detourCapKm(array $fromCodes, array $toCodes): ?int
    {
        $span = $this->routeSpanKm($fromCodes, $toCodes);

        if ($span === null) {
            return null;
        }

        $ratio = (float) Config::get('search.connections.max_detour_ratio', 1.6);
        $floor = (int) Config::get('search.connections.min_detour_km', 2000);

        return max($floor, (int) ceil($span * $ratio));
    }

    /**
     * Direct distance between the searched cities, in km, or null.
     *
     * A city can resolve to several airports, so this takes the furthest pair:
     * the cap has to clear the longest legitimate version of the journey, not
     * the shortest. Measured by the database from the airports' own
     * coordinates, which is where every leg distance came from too.
     *
     * @param list<string> $fromCodes
     * @param list<string> $toCodes
     */
    private function routeSpanKm(array $fromCodes, array $toCodes): ?int
    {
        $airports = Table::Airports->value;

        // ST_Distance_Sphere(...) / 1000 is a genuine DOUBLE expression, not
        // DECIMAL arithmetic, and live-verified MAX() over it stays float --
        // unlike the price_base + price_tax DECIMAL sums elsewhere in this
        // file, it does not stringify.
        /** @var float|null $span */
        $span = $this->connection->fetchValue(
            sprintf(
                'SELECT MAX(ST_Distance_Sphere(POINT(a.longitude, a.latitude),'
                . ' POINT(b.longitude, b.latitude))) / 1000'
                . ' FROM %s a, %s b WHERE a.code IN (%s) AND b.code IN (%s)',
                $airports,
                $airports,
                $this->placeholders($fromCodes),
                $this->placeholders($toCodes),
            ),
            [...$fromCodes, ...$toCodes],
        );

        return $span === null ? null : (int) round($span);
    }

    /**
     * A flight's fare column priced for the searched cabin.
     *
     * Rounded per leg rather than once at the end, so a leg's own price and the
     * itinerary total it belongs to are summed from the same figures -- a total
     * computed in SQL and the same total summed from hydrated legs in PHP have
     * to agree to the cent.
     *
     * Economy returns the column untouched: its multiplier is 1.0 at every
     * distance, so the default cabin's SQL is exactly what it was before cabins
     * were priced at all.
     */
    private function fare(string $alias, string $column, CabinClass $cabin): string
    {
        $multiplier = $cabin->sqlPriceMultiplier($alias);

        return $multiplier === null
            ? sprintf('%s.%s', $alias, $column)
            : sprintf('ROUND(%s.%s * %s, 2)', $alias, $column, $multiplier);
    }

    /**
     * ` AND (alias.cabins & bit)` for a cabin that has to be on sale, or an
     * empty string for economy, which every flight sells.
     */
    private function offersCabin(string $alias, CabinClass $cabin): string
    {
        $offers = $cabin->sqlOffers($alias);

        return $offers === null ? '' : ' AND ' . $offers;
    }

    /**
     * Comma-separated `?` placeholders for an IN (…) list.
     *
     * @param list<string|int> $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * The SELECT list for one hydrated leg (plain, un-prefixed column names).
     *
     * @return list<string>
     */
    private function legColumns(CabinClass $cabin): array
    {
        return [
            'flight.id AS id',
            'flight.airline AS carrier',
            'airline.title AS carrier_name',
            'flight.number AS number',
            'depart_airport.code AS dep_code',
            'depart_airport.title AS dep_name',
            'depart_country.title AS dep_country',
            'depart_airport.city AS dep_city',
            'flight.departure_time AS dep_datetime',
            'arrive_airport.code AS arr_code',
            'arrive_airport.title AS arr_name',
            'arrive_country.title AS arr_country',
            'arrive_airport.city AS arr_city',
            'flight.arrival_time AS arr_datetime',
            'flight.aircraft AS aircraft_code',
            'aircraft_type.title AS aircraft_name',
            'aircraft_type.is_widebody AS aircraft_widebody',
            // What the seat is like in the cabin being priced, which is the
            // only cabin whose seat the traveller is being sold.
            'cabin_fit.layout AS seat_layout',
            'cabin_fit.pitch_inches AS seat_pitch',
            'cabin_fit.width_inches AS seat_width',
            'cabin_fit.is_flat_bed AS seat_flat_bed',
            // Capacity of the whole aircraft, not of the cabin being priced --
            // it belongs with the body type as a sense of the frame's size.
            // Correlated rather than joined: aircraft_cabins is 74 rows and a
            // page hydrates a few dozen legs, so this costs nothing and keeps
            // the cabin join above meaning one thing.
            sprintf(
                '(SELECT SUM(seats) FROM %s WHERE aircraft = flight.aircraft) AS aircraft_seats',
                Table::AircraftCabins->value,
            ),
            'flight.distance AS distance',
            'flight.duration AS duration',
            $this->fare('flight', 'price_base', $cabin) . ' AS price_base',
            $this->fare('flight', 'price_tax', $cabin) . ' AS price_tax',
            'flight.rating AS rating',
        ];
    }

    /**
     * Remove flights that had departed by `$beforeUtc`, in bounded batches.
     *
     * **`departure_utc`, and the parameter is an instant.** `departure_time` is
     * a wall-clock reading at the departure airport, and comparing it against a
     * UTC moment is out by the airport's offset -- up to eleven hours in this
     * data, always in the direction of deleting a flight before it has left
     * (E24.2, #192).
     *
     * The cutoff is the caller's rather than `NOW()` so that this can be tested
     * at all. A method whose contract is "delete everything before X" cannot be
     * checked against a database holding real rows unless X is something no
     * real row is before -- which is what `db:prune`'s sweep learned the
     * expensive way (E18, #176).
     *
     * Bounded per statement, the same way Realign deletes over-cap legs: one
     * open-ended DELETE holds row locks for its whole duration and every
     * concurrent search queues behind it.
     */
    public function forgetDepartedBefore(string $beforeUtc, int $batchSize = 5000): int
    {
        $deleted = 0;

        do {
            $removed = $this->connection->execute(
                'DELETE FROM ' . Table::Flights->value
                . ' WHERE departure_utc < ? LIMIT ' . $batchSize,
                [$beforeUtc],
            );

            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }
}
