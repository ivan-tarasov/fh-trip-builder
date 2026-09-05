<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

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
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>, option_prices: array<string, array<array-key, float>>, bounds: array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>, highlights: array<string, array{price: float, duration: int}>}
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
    ): array {
        $filters ??= new FlightFilters();
        $empty = ['rows' => [], 'total' => 0, 'cheapest' => null, 'available' => [], 'option_prices' => [], 'bounds' => [], 'highlights' => []];

        $fromCodes = $this->resolveAirportCodes($from);
        $toCodes = $this->resolveAirportCodes($to);

        if ($fromCodes === [] || $toCodes === []) {
            return $empty;
        }

        [$candidateSql, $params] = $this->candidateSql($fromCodes, $toCodes, $departDate, $cabin);

        // One ranked pass over the candidates (lightweight rows), capped so a very
        // connective route can't sort an unbounded set. The page and total both
        // come from this single result; only the page's legs are then hydrated.
        $candidates = $this->connection->fetchAll(
            $candidateSql . ' ORDER BY ' . $sort->candidateOrderBy() . ' LIMIT ' . (self::COUNT_CAP + 1),
            $params,
        );

        if ($candidates === []) {
            return $empty;
        }

        // Filters are applied here rather than in the SQL above: that query is
        // the hot path and its joins were tuned around a fixed shape, while a
        // candidate row already carries everything a filter asks about.
        $candidates = $this->withLayoverCountries($candidates);
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
            $matching = $this->rankByValue($matching);
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
        // call its own first row the cheapest.
        $cheapest = min(array_map(
            static fn(array $c): float => (float) $c['price_base'] + (float) $c['price_tax'],
            $matching,
        ));

        return ['rows' => $rows, 'total' => $total, 'cheapest' => $cheapest, 'available' => $available, 'option_prices' => $optionPrices,
            'bounds' => $bounds, 'highlights' => $this->highlights($matching)];
    }

    /**
     * Which value of each dimension would still return something.
     *
     * A dimension is measured with its own filter lifted, so the options on
     * offer narrow as you choose elsewhere without the dimension you are
     * choosing in collapsing to the one value you picked.
     *
     * @param list<array<string, mixed>> $candidates
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
        foreach ([
            FlightFilters::DIM_SINGLE_CARRIER => static fn(array $c): bool => count(array_unique(explode(',', (string) $c['carriers']))) <= 1,
            FlightFilters::DIM_NO_NIGHT => static fn(array $c): bool => new FlightFilters(noNightLayover: true)->matches($c),
            FlightFilters::DIM_NO_GULF => static fn(array $c): bool => new FlightFilters(noGulfLayover: true)->matches($c),
            FlightFilters::DIM_NO_VISA => static fn(array $c): bool => new FlightFilters(noVisaLayover: true)->matches($c),
        ] as $dimension => $wouldKeep) {
            $available[$dimension] = false;
            $cheapest = null;

            foreach ($candidates as $candidate) {
                if ($filters->matches($candidate, $dimension) && $wouldKeep($candidate)) {
                    $available[$dimension] = true;
                    $total = $this->displayTotal($candidate);
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
     * What each sort option would put at the top: its price and travel time.
     *
     * Sorting is a trade — cheapest is rarely quickest — and the choice is
     * blind unless both numbers are on the control. One pass per option over
     * the same rows the page was built from, so this costs nothing extra.
     *
     * @param list<array<string, mixed>> $candidates
     * @return array<string, array{price: float, duration: int}>
     */
    private function highlights(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $scores = $this->valueScores($candidates);
        $total = static fn(array $c): float => (float) $c['price_base'] + (float) $c['price_tax'];

        // How each option decides which itinerary wins. Lower is better in all
        // of them except rating, which is negated to keep one comparison.
        $rank = [
            SortMethod::Recommended->value => static fn(array $c, int $i): float => $scores[$i],
            SortMethod::Price->value => static fn(array $c, int $i): float => $total($c),
            SortMethod::Duration->value => static fn(array $c, int $i): float => (float) $c['duration'],
            SortMethod::LayoverShort->value => static fn(array $c, int $i): float => (float) $c['layover_minutes'],
            SortMethod::Rating->value => static fn(array $c, int $i): float => -(float) $c['rating'],
            SortMethod::Depart->value => static fn(array $c, int $i): float => (float) strtotime((string) $c['depart_time']),
            SortMethod::Arrive->value => static fn(array $c, int $i): float => (float) strtotime((string) $c['arrive_time']),
        ];

        $highlights = [];

        foreach ($rank as $sort => $key) {
            $winner = null;
            $best = null;

            foreach ($candidates as $i => $candidate) {
                // Same tie-break as the SQL ordering, or a sort with many equal
                // rows would advertise a different one than it returns.
                $value = [$key($candidate, $i), $total($candidate), (int) $candidate['seg1']];

                if ($best === null || $value < $best) {
                    $best = $value;
                    $winner = $candidate;
                }
            }

            if ($winner !== null) {
                $highlights[$sort] = [
                    'price' => $total($winner),
                    'duration' => (int) $winner['duration'],
                ];
            }
        }

        return $highlights;
    }

    /**
     * The span each slider should cover: the smallest and largest value still
     * reachable, with that slider's own filter lifted so dragging it never
     * shrinks its own track out from under the handle.
     *
     * @param list<array<string, mixed>> $candidates
     * @return array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>
     */
    private function bounds(array $candidates, FlightFilters $filters): array
    {
        $measures = [
            // Includes the offset, so the slider spans what the cards say.
            FlightFilters::DIM_PRICE => static fn(array $c): array => [
                (float) $c['price_base'] + (float) $c['price_tax'] + (float) ($c['price_offset'] ?? 0),
            ],
            FlightFilters::DIM_DURATION => static fn(array $c): array => [(float) $c['duration']],
            // Every wait, not their total: the slider constrains connections
            // one at a time, so its ends have to span single waits.
            FlightFilters::DIM_LAYOVER_RANGE => static fn(array $c): array => array_map(
                floatval(...),
                FlightFilters::waits($c),
            ),
        ];

        $bounds = [];

        foreach ($measures as $dimension => $measure) {
            $lows = [];
            $highs = [];
            // A direct flight has no wait to fall outside a range, so it meets
            // any ceiling — while it is on offer the ceiling handle cannot
            // empty the results. It cannot meet a floor, though, so the floor's
            // reach is still measured from the itineraries that connect.
            $meetsAnyCeiling = false;

            foreach ($candidates as $candidate) {
                if (!$filters->matches($candidate, $dimension)) {
                    continue;
                }

                $values = $measure($candidate);

                if ($values === []) {
                    $meetsAnyCeiling = true;

                    continue;
                }

                $lows[] = min($values);
                $highs[] = max($values);
            }

            if ($lows === []) {
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
            $bounds[$dimension] = [
                'min' => $min,
                'max' => $max,
                'floor_max' => (int) floor(max($lows)),
                'ceiling_min' => $meetsAnyCeiling ? $min : (int) ceil(min($highs)),
            ];
        }

        return $bounds;
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
     * @param list<array<string, mixed>> $candidates
     * @return list<float>
     */
    private function valueScores(array $candidates): array
    {
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
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
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
     * @param list<array<string, mixed>> $candidates
     * @param callable(array<string, mixed>): list<string> $values
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

            $total = $this->displayTotal($candidate);

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
     * @param array<string, mixed> $candidate
     */
    private function displayTotal(array $candidate): float
    {
        return (float) $candidate['price_base']
            + (float) $candidate['price_tax']
            + (float) ($candidate['price_offset'] ?? 0);
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
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
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
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
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
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach ($this->connection->fetchAll('SELECT code, country_code FROM ' . Table::Airports->value) as $row) {
            $map[(string) $row['code']] = (string) $row['country_code'];
        }

        return $map;
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
     * @return array<string, mixed>|null
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
        ];
    }

    /**
     * A single flight leg by id (hydrated), or null. Used by the booking flow.
     *
     * @return array<string, mixed>|null
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

        $rows = $this->connection->fetchAll(
            'SELECT id, fare_brand FROM ' . Table::Flights->value
            . ' WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row['fare_brand'] === null ? null : (string) $row['fare_brand'];
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
     * @return list<array<string, mixed>>
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
    private function candidateSql(array $fromCodes, array $toCodes, string $date, CabinClass $cabin): array
    {
        $flights = Table::Flights->value;
        $minc = (int) Config::get('search.connections.min_connect_minutes', 45);
        $maxc = (int) Config::get('search.connections.max_connect_minutes', 360);
        $maxStops = (int) Config::get('search.connections.max_stops', 2);
        $buffer = self::CONNECT_DATE_BUFFER_DAYS;

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
            f1.departure_airport AS dep_airport, f1.arrival_airport AS arr_airport,
            NULL AS stops_at, 0 AS layover_minutes,
            NULL AS stop1_in, NULL AS stop1_out, NULL AS stop2_in, NULL AS stop2_out
            FROM {$flights} f1
            WHERE f1.departure_airport IN ({$fromPh}) AND f1.arrival_airport IN ({$toPh})
              AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL 1 DAY
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
                      AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL 1 DAY
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
                      AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL 1 DAY
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
    public function cheapestTotal(string $from, string $to, string $date, CabinClass $cabin): ?float
    {
        $fromCodes = $this->resolveAirportCodes($from);
        $toCodes = $this->resolveAirportCodes($to);

        if ($fromCodes === [] || $toCodes === []) {
            return null;
        }

        [, , $parts, $partParams] = $this->candidateSql($fromCodes, $toCodes, $date, $cabin);

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
     * @return array<int, array<string, mixed>>
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

        foreach ($this->connection->fetchAll($sql, $ids) as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Collect the non-null leg ids across a set of candidate/itinerary rows.
     *
     * @param list<array<string, mixed>> $candidates
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
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $legs
     * @param array<string, list<string>> $badges
     * @return array<string, mixed>|null null when a leg could not be built
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
     * @param list<array<string, mixed>> $candidates
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
     * @param array<string, mixed> $candidate
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
        $rows = $this->connection->fetchAll(
            'SELECT code FROM ' . Table::Airports->value
            . ' WHERE (code = ? OR city_code = ?) AND enabled = 1 AND traffic_weight > 0',
            [$codeOrCity, $codeOrCity],
        );

        return array_map(static fn(array $row): string => (string) $row['code'], $rows);
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

        return $span === null ? null : (int) round((float) $span);
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
}
