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
     * Bump the search counter for the given departure/arrival airports,
     * matched by airport code or city code.
     */
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

    /**
     * Autofill suggestions for the search form, across code/title/city.
     *
     * Restricted to major airports (is_major = 1), because flights are only
     * generated between major airports (see Noah\Flights\Generate) — suggesting
     * a minor airport would always yield an empty search. The match group is
     * parenthesised so the enabled/major guards gate every match; without the
     * parentheses, AND binding tighter than OR would apply them only to the
     * code match.
     *
     * @return list<array<string, mixed>>
     */
    public function autofill(string $query): array
    {
        $like = '%' . $query . '%';

        $sql = 'SELECT ' . self::COLUMNS
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1 AND a.is_major = 1'
            . ' AND (a.code LIKE ? OR a.title LIKE ? OR a.city_code LIKE ? OR a.city LIKE ?)'
            . ' ORDER BY a.title ASC';

        return $this->connection->fetchAll($sql, [$like, $like, $like, $like]);
    }
}
