<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The cheapest fare on each day of a route, for the calendar to print under
 * its days.
 *
 * Built per route the first time one is asked for, and read back after. Working
 * it out live is not an option: measured on this data, the cheapest one-stop
 * fare across sixty days runs 33ms on a quiet route and 2.8s on a busy one, and
 * the query will not behave -- MySQL drives it from a full scan of the flights
 * table, and pinning the other join order with STRAIGHT_JOIN made it 2.9s
 * rather than faster. Direct flights alone cost under a millisecond and answer
 * on a quarter of the days, which is a calendar that looks broken.
 *
 * So the cost is paid once, in the background, by whoever opens a calendar on a
 * route nobody has opened yet. Reading is a range scan on the primary key.
 *
 * These are "from" prices and are not the search's own answer: the search
 * assembles up to two connections and prices the whole trip for the party,
 * where this is the cheapest single seat over at most one. It is a signpost
 * towards a cheaper day, not a quote.
 */
final readonly class RoutePriceRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Prices by date, for a window.
     *
     * @return array<string, float> "YYYY-MM-DD" => cheapest fare
     */
    public function read(
        string $from,
        string $to,
        CabinClass $cabin,
        string $since,
        string $until,
    ): array {
        $rows = $this->connection->fetchAll(
            'SELECT depart_date, price FROM ' . Table::RouteDayPrice->value
            . ' WHERE from_code = ? AND to_code = ? AND cabin = ?'
            . ' AND depart_date >= ? AND depart_date < ?',
            [$from, $to, $cabin->value, $since, $until],
        );

        $prices = [];

        foreach ($rows as $row) {
            $prices[(string) $row['depart_date']] = (float) $row['price'];
        }

        return $prices;
    }

    /**
     * When this route was last worked out, or null if it never has been.
     *
     * The prices table cannot answer this. A route with no fares at all and a
     * route nobody has asked for both have no rows, and only one of them is
     * worth the cost of asking again.
     */
    public function builtAt(string $from, string $to, CabinClass $cabin): ?string
    {
        $row = $this->connection->fetchOne(
            'SELECT built_at FROM ' . Table::RoutePriceBuild->value
            . ' WHERE from_code = ? AND to_code = ? AND cabin = ? LIMIT 1',
            [$from, $to, $cabin->value],
        );

        return $row === null ? null : (string) $row['built_at'];
    }

    /**
     * Whether the route needs working out, given how old an answer is allowed.
     */
    public function isStale(string $from, string $to, CabinClass $cabin, int $maxAgeHours): bool
    {
        $built = $this->builtAt($from, $to, $cabin);

        return $built === null || strtotime($built) < time() - $maxAgeHours * 3600;
    }

    /**
     * Work out every day on the route and store it.
     *
     * Direct fares and one-connection fares in one pass, taking whichever is
     * cheaper per day. Two connections are left out on purpose: the search
     * explores them, but they are rarely the cheapest and the third join is
     * what turns seconds into minutes.
     *
     * The codes are whatever the form submitted, city or airport, and each is
     * expanded the way the search expands it -- a price filed under LON has to
     * be the price a search for LON would find.
     */
    public function build(
        string $from,
        string $to,
        CabinClass $cabin,
        string $since,
        string $until,
    ): void {
        $fromCodes = $this->airportsFor($from);
        $toCodes = $this->airportsFor($to);

        $this->connection->execute(
            'DELETE FROM ' . Table::RouteDayPrice->value
            . ' WHERE from_code = ? AND to_code = ? AND cabin = ?',
            [$from, $to, $cabin->value],
        );

        if ($fromCodes !== [] && $toCodes !== []) {
            $this->store($from, $to, $cabin, $this->cheapestPerDay($fromCodes, $toCodes, $cabin, $since, $until));
        }

        // Recorded even when nothing was found, so an empty route is not
        // rebuilt by every visitor who opens its calendar.
        $this->connection->execute(
            'INSERT INTO ' . Table::RoutePriceBuild->value
            . ' (from_code, to_code, cabin, built_at, covers_until)'
            . ' VALUES (?, ?, ?, NOW(), ?)'
            . ' ON DUPLICATE KEY UPDATE built_at = NOW(), covers_until = VALUES(covers_until)',
            [$from, $to, $cabin->value, $until],
        );
    }

    /**
     * @param list<string> $fromCodes
     * @param list<string> $toCodes
     *
     * @return array<string, float>
     */
    private function cheapestPerDay(
        array $fromCodes,
        array $toCodes,
        CabinClass $cabin,
        string $since,
        string $until,
    ): array {
        $fromIn = $this->placeholders($fromCodes);
        $toIn = $this->placeholders($toCodes);

        /** @var array{min_connect_minutes: int, max_connect_minutes: int} $connections */
        $connections = (array) Config::get('search.connections');
        $minConnect = (int) $connections['min_connect_minutes'];
        $maxConnect = (int) $connections['max_connect_minutes'];

        $rows = $this->connection->fetchAll(
            'SELECT d, MIN(p) AS price FROM ('
            . ' SELECT DATE(f.departure_time) AS d, ' . $this->fare('f', $cabin) . ' AS p'
            . ' FROM ' . Table::Flights->value . ' f'
            . " WHERE f.departure_airport IN ($fromIn) AND f.arrival_airport IN ($toIn)"
            . ' AND f.departure_time >= ? AND f.departure_time < ?'
            . $this->offers('f', $cabin)
            . ' UNION ALL'
            . ' SELECT DATE(a.departure_time) AS d,'
            . ' ' . $this->fare('a', $cabin) . ' + ' . $this->fare('b', $cabin) . ' AS p'
            . ' FROM ' . Table::Flights->value . ' a'
            . ' JOIN ' . Table::Flights->value . ' b'
            . ' ON b.departure_airport = a.arrival_airport'
            . " AND b.arrival_airport IN ($toIn)"
            . " AND b.departure_time >= a.arrival_time + INTERVAL $minConnect MINUTE"
            . " AND b.departure_time <= a.arrival_time + INTERVAL $maxConnect MINUTE"
            . $this->offers('b', $cabin)
            . " WHERE a.departure_airport IN ($fromIn) AND a.arrival_airport NOT IN ($toIn)"
            . ' AND a.departure_time >= ? AND a.departure_time < ?'
            . $this->offers('a', $cabin)
            . ') AS legs GROUP BY d',
            [...$fromCodes, ...$toCodes, $since, $until, ...$toCodes, ...$fromCodes, ...$toCodes, $since, $until],
        );

        $prices = [];

        foreach ($rows as $row) {
            $prices[(string) $row['d']] = (float) $row['price'];
        }

        return $prices;
    }

    /**
     * One leg's fare in a cabin.
     *
     * The uplift is not flat -- short-haul business is a wider seat and
     * long-haul business is a bed -- so CabinClass scales it by distance, and
     * the same expression it gives the search is used here. Economy has no
     * uplift at any distance, and there the column is left alone rather than
     * multiplied by one.
     */
    private function fare(string $alias, CabinClass $cabin): string
    {
        $sum = sprintf('(%s.price_base + %s.price_tax)', $alias, $alias);
        $multiplier = $cabin->sqlPriceMultiplier($alias);

        return $multiplier === null ? $sum : $sum . ' * ' . $multiplier;
    }

    /**
     * "and this flight sells that cabin", where that means anything.
     *
     * Every flight sells economy, so the test is left off there -- it would
     * exclude nothing while denying the optimiser an index.
     */
    private function offers(string $alias, CabinClass $cabin): string
    {
        $predicate = $cabin->sqlOffers($alias);

        return $predicate === null ? '' : ' AND ' . $predicate;
    }

    /** @param array<string, float> $prices */
    private function store(string $from, string $to, CabinClass $cabin, array $prices): void
    {
        if ($prices === []) {
            return;
        }

        $values = [];
        $args = [];

        foreach ($prices as $date => $price) {
            $values[] = '(?, ?, ?, ?, ?)';
            array_push($args, $from, $to, $cabin->value, $date, $price);
        }

        // One statement: ninety single-row inserts inside a request is the sort
        // of thing that makes a background job look slow for no reason.
        $this->connection->execute(
            'INSERT INTO ' . Table::RouteDayPrice->value . ' (from_code, to_code, cabin, depart_date, price)'
            . ' VALUES ' . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE price = VALUES(price)',
            $args,
        );
    }

    /**
     * The airports a code stands for, the way the search resolves it: a city
     * code is every airport in that city, and only ones carrying traffic.
     *
     * @return list<string>
     */
    private function airportsFor(string $code): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT code FROM ' . Table::Airports->value
            . ' WHERE (code = ? OR city_code = ?) AND enabled = 1 AND traffic_weight > 0',
            [$code, $code],
        );

        return array_map(static fn(array $row): string => (string) $row['code'], $rows);
    }

    /** @param list<string> $values */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
