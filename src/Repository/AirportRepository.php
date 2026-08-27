<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final class AirportRepository
{
    private const COLUMNS = 'a.code, a.title, c.title AS country, a.city_code, a.city, '
        . 'a.timezone, a.timezone_name, a.latitude, a.longitude, a.altitude';

    public function __construct(private readonly Connection $connection) {}

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
     * Autofill search across code/title/city.
     *
     * NOTE: the WHERE clause deliberately has no parentheses, reproducing the
     * legacy MysqliDb precedence exactly — because AND binds before OR, the
     * `enabled = 1` guard applies only to the code match. Preserved for
     * behaviour parity; tightening it is a separate behavioural change.
     *
     * @return list<array<string, mixed>>
     */
    public function autofill(string $query): array
    {
        $like = '%' . $query . '%';

        $sql = 'SELECT ' . self::COLUMNS
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1 AND a.code LIKE ? OR a.title LIKE ? OR a.city_code LIKE ? OR a.city LIKE ?'
            . ' ORDER BY a.title ASC';

        return $this->connection->fetchAll($sql, [$like, $like, $like, $like]);
    }
}
