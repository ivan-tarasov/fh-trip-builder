<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Api\Flights\SortMethod;
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
    private const int PER_PAGE = 10;

    // Cap the one-way pagination count so a very connective route can't make the
    // COUNT scan every 2-stop combination; results past this show as "N+".
    private const int COUNT_CAP = 500;

    // A connecting leg departs within this many days of the search date. This
    // constant bound lets the index seek the connecting leg by date (the exact
    // correlated layover window still filters on top); without it MySQL would
    // scan every flight on that route across the whole schedule.
    private const int CONNECT_DATE_BUFFER_DAYS = 3;

    public function __construct(private Connection $connection) {}

    /**
     * Itineraries for one direction of a trip (origin -> destination on a date),
     * ranked and paginated. A round trip searches each direction separately —
     * the outbound first, then the return — rather than pairing every outbound
     * with every return.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function searchDirection(string $from, string $to, string $departDate, SortMethod $sort, int $page): array
    {
        $fromCodes = $this->resolveAirportCodes($from);
        $toCodes = $this->resolveAirportCodes($to);

        if ($fromCodes === [] || $toCodes === []) {
            return ['rows' => [], 'total' => 0];
        }

        [$candidateSql, $params] = $this->candidateSql($fromCodes, $toCodes, $departDate);

        // One ranked pass over the candidates (lightweight rows), capped so a very
        // connective route can't sort an unbounded set. The page and total both
        // come from this single result; only the page's legs are then hydrated.
        $candidates = $this->connection->fetchAll(
            $candidateSql . ' ORDER BY ' . $sort->candidateOrderBy() . ' LIMIT ' . (self::COUNT_CAP + 1),
            $params,
        );

        if ($candidates === []) {
            return ['rows' => [], 'total' => 0];
        }

        $total = min(count($candidates), self::COUNT_CAP);

        $pageItems = array_slice($candidates, $this->offset($page), self::PER_PAGE);

        $legs = $this->hydrateLegs($this->collectLegIds($pageItems));

        $rows = array_map(fn(array $c): array => $this->assembleItinerary($c, $legs), $pageItems);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The cheapest total (base + tax) available for one direction, or null when
     * the direction has no itineraries. Used to price a round-trip outbound as
     * "from $X" — the least it can cost once a return is added.
     */
    public function cheapestTotal(string $from, string $to, string $date): ?float
    {
        $fromCodes = $this->resolveAirportCodes($from);
        $toCodes = $this->resolveAirportCodes($to);

        if ($fromCodes === [] || $toCodes === []) {
            return null;
        }

        [$sql, $params] = $this->candidateSql($fromCodes, $toCodes, $date);

        $row = $this->connection->fetchOne(
            $sql . ' ORDER BY (price_base + price_tax) ASC LIMIT 1',
            $params,
        );

        return $row === null ? null : (float) $row['price_base'] + (float) $row['price_tax'];
    }

    /**
     * Rebuild a chosen itinerary from its ordered leg ids, with the aggregates
     * the display needs. Returns null unless every id resolves and the legs form
     * a connected chain — so a stale or tampered selection is rejected.
     *
     * @param list<int> $ids
     * @return array<string, mixed>|null
     */
    public function itineraryByIds(array $ids): ?array
    {
        $legs = $this->legsByIds($ids);

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
    public function findById(int $flightId): ?array
    {
        return $this->hydrateLegs([$flightId])[$flightId] ?? null;
    }

    /**
     * Hydrated legs for an ordered id list, preserving order (booking flow).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function legsByIds(array $ids): array
    {
        $byId = $this->hydrateLegs($ids);

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
     * @param list<string> $fromCodes
     * @param list<string> $toCodes
     * @return array{0: string, 1: list<string>}
     */
    private function candidateSql(array $fromCodes, array $toCodes, string $date): array
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

        $parts = [];
        $params = [];

        // Direct.
        $parts[] = "SELECT f1.id AS seg1, NULL AS seg2, NULL AS seg3, 0 AS stops,
            f1.price_base AS price_base, f1.price_tax AS price_tax,
            f1.duration AS duration,
            f1.departure_time AS depart_time, f1.arrival_time AS arrive_time,
            f1.rating AS rating
            FROM {$flights} f1
            WHERE f1.departure_airport IN ({$fromPh}) AND f1.arrival_airport IN ({$toPh})
              AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL 1 DAY";
        $params = [...$params, ...$fromCodes, ...$toCodes, $date, $date];

        // 1-stop: f1 -> f2, connecting at f1.arrival within the layover window.
        if ($maxStops >= 1) {
            $parts[] = "SELECT f1.id, f2.id, NULL, 1,
                f1.price_base + f2.price_base, f1.price_tax + f2.price_tax,
                f1.duration + f2.duration + TIMESTAMPDIFF(MINUTE, f1.arrival_time, f2.departure_time),
                f1.departure_time, f2.arrival_time,
                (f1.rating + f2.rating) / 2
                FROM {$flights} f1
                INNER JOIN {$flights} f2 ON f2.departure_airport = f1.arrival_airport
                    AND f2.departure_time >= f1.arrival_time + INTERVAL {$minc} MINUTE
                    AND f2.departure_time <= f1.arrival_time + INTERVAL {$maxc} MINUTE
                WHERE f1.departure_airport IN ({$fromPh})
                  AND f1.departure_time >= ? AND f1.departure_time < ? + INTERVAL 1 DAY
                  AND f2.arrival_airport IN ({$toPh})
                  AND f2.departure_time >= ? AND f2.departure_time < ? + INTERVAL {$buffer} DAY";
            // No `f1.arrival NOT IN (endpoints)` here: for a single connection it
            // is redundant (a hop through the origin/destination yields no valid
            // second leg) and it would stop f1 from seeking on departure_airport_time.
            $params = [...$params, ...$fromCodes, $date, $date, ...$toCodes, $date, $date];
        }

        // 2-stop: f1 -> f2 -> f3, two valid connections, distinct intermediates.
        if ($maxStops >= 2) {
            $parts[] = "SELECT f1.id, f2.id, f3.id, 2,
                f1.price_base + f2.price_base + f3.price_base, f1.price_tax + f2.price_tax + f3.price_tax,
                f1.duration + f2.duration + f3.duration
                    + TIMESTAMPDIFF(MINUTE, f1.arrival_time, f2.departure_time)
                    + TIMESTAMPDIFF(MINUTE, f2.arrival_time, f3.departure_time),
                f1.departure_time, f3.arrival_time,
                (f1.rating + f2.rating + f3.rating) / 3
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
                  AND f3.arrival_airport IN ({$toPh})
                  AND f1.arrival_airport NOT IN ({$endPh})
                  AND f2.arrival_airport NOT IN ({$endPh})
                  AND f2.arrival_airport <> f1.arrival_airport";
            $params = [...$params, ...$fromCodes, $date, $date, $date, $date, $date, $date, ...$toCodes, ...$endpoints, ...$endpoints];
        }

        $sql = implode(' UNION ALL ', array_map(static fn(string $p): string => '(' . $p . ')', $parts));

        return [$sql, $params];
    }

    /**
     * Fetch display rows for the given leg ids, keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function hydrateLegs(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $sql = 'SELECT ' . implode(', ', $this->legColumns())
            . ' FROM ' . Table::Flights->value . ' flight'
            . ' INNER JOIN ' . Table::Airports->value . ' depart_airport ON flight.departure_airport = depart_airport.code'
            . ' INNER JOIN ' . Table::Airports->value . ' arrive_airport ON flight.arrival_airport = arrive_airport.code'
            . ' INNER JOIN ' . Table::Airlines->value . ' airline ON flight.airline = airline.code'
            . ' INNER JOIN ' . Table::Countries->value . ' depart_country ON depart_airport.country_code = depart_country.code'
            . ' INNER JOIN ' . Table::Countries->value . ' arrive_country ON arrive_airport.country_code = arrive_country.code'
            . ' WHERE flight.id IN (' . $this->placeholders($ids) . ')';

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
     * @return array<string, mixed>
     */
    private function assembleItinerary(array $candidate, array $legs): array
    {
        $ordered = [];

        foreach (['seg1', 'seg2', 'seg3'] as $seg) {
            $id = $candidate[$seg] ?? null;

            if ($id !== null && isset($legs[(int) $id])) {
                $ordered[] = $legs[(int) $id];
            }
        }

        return [
            'legs' => $ordered,
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
     * Resolve a search input (an airport code or a city code) to the concrete
     * airport codes it covers, so the flight filter can use an indexed
     * `departure_airport IN (…)` equality instead of a non-sargable
     * `(code = ? OR city_code = ?)` predicate.
     *
     * @return list<string>
     */
    private function resolveAirportCodes(string $codeOrCity): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT code FROM ' . Table::Airports->value . ' WHERE code = ? OR city_code = ?',
            [$codeOrCity, $codeOrCity],
        );

        return array_map(static fn(array $row): string => (string) $row['code'], $rows);
    }

    private function offset(int $page): int
    {
        return ($page - 1) * self::PER_PAGE;
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
    private function legColumns(): array
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
            'flight.distance AS distance',
            'flight.duration AS duration',
            'flight.price_base AS price_base',
            'flight.price_tax AS price_tax',
            'flight.rating AS rating',
        ];
    }
}
