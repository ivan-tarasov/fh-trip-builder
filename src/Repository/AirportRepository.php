<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class AirportRepository
{
    private const string COLUMNS = 'a.code, a.title, c.title AS country, a.city_code, a.city, '
        . 'a.timezone, a.timezone_name, a.latitude, a.longitude, a.altitude';

    public function __construct(private Connection $connection) {}

    /**
     * Enabled airports (optionally major only), joined to their country,
     * ordered by title.
     *
     * @return list<array<string, mixed>>
     */
    public function enabled(bool $majorOnly): array
    {
        $sql = 'SELECT ' . self::COLUMNS
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1';

        if ($majorOnly) {
            $sql .= ' AND is_major = 1';
        }

        $sql .= ' ORDER BY a.title ASC';

        return $this->connection->fetchAll($sql);
    }

    /**
     * Everywhere a search can start or end: the cities the network serves and
     * the airports inside them, as one ordered list.
     *
     * Small enough to ship whole -- 254 major airports across 231 cities, which
     * is every airport that has a departure. That is what lets the form use a
     * plain select the browser filters instantly, instead of asking the server
     * after every third keystroke.
     *
     * A city sorts immediately above its own airports, so picking "London"
     * (which resolveAirportCodes() expands to all three) sits with the three it
     * expands to rather than somewhere else alphabetically.
     *
     * @return list<array<string, mixed>>
     */
    public function pickable(): array
    {
        $sql = 'SELECT code, label, sub, city, is_city FROM ('
            . ' SELECT a.city_code AS code, a.city AS label, c.title AS sub, a.city AS city,'
            . '  1 AS is_city, a.city AS in_city, 0 AS depth'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1 AND a.is_major = 1'
            . ' GROUP BY a.city_code, a.city, c.title'
            // Only where it means something. A city with one airport shares that
            // airport's code, so the row would be a second way to pick the same
            // place -- and two options carrying one value is a list that looks
            // like it offers a choice it does not.
            . ' HAVING COUNT(*) > 1'
            . ' UNION ALL'
            . ' SELECT a.code, a.title, CONCAT(a.city, \', \', c.title), a.city,'
            . '  0, a.city, 1'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1 AND a.is_major = 1'
            // In a multi-airport city, one airport often carries the city's own
            // code -- BER, BKK, BRU, CTU, IST, SHA. resolveAirportCodes()
            // matches `code = ? OR city_code = ?`, so picking that airport
            // already searches every airport in the city: the row offers one
            // airport and delivers all of them. The city row above says so
            // honestly, and this drops the row that does not.
            //
            // Only where a city row exists. In a single-airport city the
            // airport's code IS the city code, and dropping those would empty
            // the list of most of its airports.
            . ' AND NOT (a.code = a.city_code AND EXISTS ('
            . '  SELECT 1 FROM ' . Table::Airports->value . ' peer'
            . '  WHERE peer.city_code = a.city_code AND peer.enabled = 1'
            . '   AND peer.is_major = 1 AND peer.code <> a.code'
            . ' ))'
            . ') places ORDER BY in_city ASC, depth ASC, label ASC';

        return $this->connection->fetchAll($sql);
    }

    /**
     * Bump the search counter for the given departure/arrival airports,
     * matched by airport code or city code.
     */
    /**
     * Airports for a set of codes, in the same shape as enabled(). Used to
     * label the codes a search offered, so the sidebar can show a city and
     * country rather than three letters.
     *
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    public function byCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($codes), '?'));

        return $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . " WHERE a.code IN ($placeholders)"
            . ' ORDER BY a.city ASC, a.title ASC',
            array_values($codes),
        );
    }

    public function recordSearch(string ...$codes): void
    {
        $in = implode(', ', array_fill(0, count($codes), '?'));

        $this->connection->execute(
            'UPDATE ' . Table::Airports->value
            . ' SET search_count = search_count + 1, last_search = NOW()'
            . " WHERE code IN ($in) OR city_code IN ($in)",
            [...$codes, ...$codes],
        );
    }

    /**
     * City name for an airport code or city code (first match), or null.
     */
    public function cityByCode(string $code): ?string
    {
        $city = $this->connection->fetchValue(
            'SELECT city FROM ' . Table::Airports->value . ' WHERE code = ? OR city_code = ? LIMIT 1',
            [$code, $code],
        );

        return $city === null ? null : (string) $city;
    }

}
