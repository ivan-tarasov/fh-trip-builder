<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

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
    public function read(string $from, string $to, string $since, string $until): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT depart_date, price FROM ' . Table::RouteDayPrice->value
            . ' WHERE from_code = ? AND to_code = ? AND depart_date >= ? AND depart_date < ?',
            [$from, $to, $since, $until],
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
    public function builtAt(string $from, string $to): ?string
    {
        $row = $this->connection->fetchOne(
            'SELECT built_at FROM ' . Table::RoutePriceBuild->value
            . ' WHERE from_code = ? AND to_code = ? LIMIT 1',
            [$from, $to],
        );

        return $row === null ? null : (string) $row['built_at'];
    }

    /**
     * Whether the route needs working out, given how old an answer is allowed.
     */
    public function isStale(string $from, string $to, int $maxAgeHours): bool
    {
        $built = $this->builtAt($from, $to);

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
    public function build(string $from, string $to, string $since, string $until): void
    {
        $fromCodes = $this->airportsFor($from);
        $toCodes = $this->airportsFor($to);

        $this->connection->execute(
            'DELETE FROM ' . Table::RouteDayPrice->value . ' WHERE from_code = ? AND to_code = ?',
            [$from, $to],
        );

        if ($fromCodes !== [] && $toCodes !== []) {
            $this->store($from, $to, $this->cheapestPerDay($fromCodes, $toCodes, $since, $until));
        }

        // Recorded even when nothing was found, so an empty route is not
        // rebuilt by every visitor who opens its calendar.
        $this->connection->execute(
            'INSERT INTO ' . Table::RoutePriceBuild->value . ' (from_code, to_code, built_at, covers_until)'
            . ' VALUES (?, ?, NOW(), ?)'
            . ' ON DUPLICATE KEY UPDATE built_at = NOW(), covers_until = VALUES(covers_until)',
            [$from, $to, $until],
        );
    }

    /**
     * @param list<string> $fromCodes
     * @param list<string> $toCodes
     *
     * @return array<string, float>
     */
    private function cheapestPerDay(array $fromCodes, array $toCodes, string $since, string $until): array
    {
        $fromIn = $this->placeholders($fromCodes);
        $toIn = $this->placeholders($toCodes);

        /** @var array{min_connect_minutes: int, max_connect_minutes: int} $connections */
        $connections = (array) Config::get('search.connections');
        $minConnect = (int) $connections['min_connect_minutes'];
        $maxConnect = (int) $connections['max_connect_minutes'];

        $rows = $this->connection->fetchAll(
            'SELECT d, MIN(p) AS price FROM ('
            . " SELECT DATE(departure_time) AS d, price_base + price_tax AS p"
            . ' FROM ' . Table::Flights->value
            . " WHERE departure_airport IN ($fromIn) AND arrival_airport IN ($toIn)"
            . ' AND departure_time >= ? AND departure_time < ?'
            . ' UNION ALL'
            . ' SELECT DATE(a.departure_time) AS d,'
            . ' a.price_base + a.price_tax + b.price_base + b.price_tax AS p'
            . ' FROM ' . Table::Flights->value . ' a'
            . ' JOIN ' . Table::Flights->value . ' b'
            . ' ON b.departure_airport = a.arrival_airport'
            . " AND b.arrival_airport IN ($toIn)"
            . " AND b.departure_time >= a.arrival_time + INTERVAL $minConnect MINUTE"
            . " AND b.departure_time <= a.arrival_time + INTERVAL $maxConnect MINUTE"
            . " WHERE a.departure_airport IN ($fromIn) AND a.arrival_airport NOT IN ($toIn)"
            . ' AND a.departure_time >= ? AND a.departure_time < ?'
            . ') AS legs GROUP BY d',
            [...$fromCodes, ...$toCodes, $since, $until, ...$toCodes, ...$fromCodes, ...$toCodes, $since, $until],
        );

        $prices = [];

        foreach ($rows as $row) {
            $prices[(string) $row['d']] = (float) $row['price'];
        }

        return $prices;
    }

    /** @param array<string, float> $prices */
    private function store(string $from, string $to, array $prices): void
    {
        if ($prices === []) {
            return;
        }

        $values = [];
        $args = [];

        foreach ($prices as $date => $price) {
            $values[] = '(?, ?, ?, ?)';
            array_push($args, $from, $to, $date, $price);
        }

        // One statement: ninety single-row inserts inside a request is the sort
        // of thing that makes a background job look slow for no reason.
        $this->connection->execute(
            'INSERT INTO ' . Table::RouteDayPrice->value . ' (from_code, to_code, depart_date, price)'
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
