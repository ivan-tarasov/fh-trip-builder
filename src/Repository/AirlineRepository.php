<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Airlines, which like countries are a thin table read two ways.
 *
 * Most of what an airline page says is here and is curated rather than
 * counted: where it is based, which airports it flies out of, its website and
 * its phone number. What the flights table can add to that is thinner than it
 * looks -- see AirlineController for the two facts that were measured and left
 * out.
 */
final readonly class AirlineRepository
{
    /**
     * The same filter the rest of the site sells by.
     *
     * `is_major` is exactly "we sell seats on it" and not an approximation of
     * it: measured on this data, all 105 major airlines have flights and no
     * other airline has a single one. So the flag can be trusted on its own,
     * without a join to the 683,760 rows that would prove it.
     */
    private const string ONLY_SELLABLE = ' al.is_major = 1';

    public function __construct(private Connection $connection) {}

    /**
     * One airline, with the country it is based in.
     *
     * LEFT JOIN because `country` is nullable, even though all 105 airlines we
     * sell have one -- a null there should cost a page its "based in" tile, not
     * the whole page.
     *
     * @return array<string, mixed>|null
     */
    public function byCode(string $code): ?array
    {
        return $this->connection->fetchOne(
            'SELECT al.code, al.title AS name, al.url, al.phone, al.hubs,'
            . ' al.country AS country_code, c.title AS country'
            . ' FROM ' . Table::Airlines->value . ' al'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON c.code = al.country'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND al.code = ?',
            [strtoupper($code)],
        );
    }

    /**
     * Every airline we sell a seat on, name-ordered for the directory.
     *
     * @return list<array<string, mixed>>
     */
    public function sellable(): array
    {
        return $this->connection->fetchAll(
            'SELECT al.code, al.title AS name, c.title AS country'
            . ' FROM ' . Table::Airlines->value . ' al'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON c.code = al.country'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' ORDER BY name ASC',
        );
    }

    /**
     * The airport codes an airline is based at.
     *
     * The column is a space-separated list -- "YYZ YUL YVR" -- because it is
     * seed data written to be read rather than a join table. Split here, so
     * the one place that knows the format is the one that selects it.
     *
     * Every code listed for every airline we sell is a sellable airport, so
     * nothing here needs to allow for a hub with no airport behind it. Checked
     * across all 105 rather than assumed.
     *
     * @return list<string>
     */
    public static function hubCodes(string $hubs): array
    {
        return preg_split('/\s+/', trim($hubs), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Airlines ordered by title, optionally filtered to specific IATA codes
     * and/or to major carriers only.
     *
     * @param list<string>|null $codes
     * @return list<array<string, mixed>>
     */
    public function search(?array $codes, bool $majorOnly): array
    {
        $sql = 'SELECT * FROM ' . Table::Airlines->value;
        $conditions = [];
        $params = [];

        if ($codes !== null && $codes !== []) {
            $placeholders = implode(', ', array_fill(0, count($codes), '?'));
            $conditions[] = "code IN ($placeholders)";
            $params = array_merge($params, array_values($codes));
        }

        if ($majorOnly) {
            $conditions[] = 'is_major = 1';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY title ASC';

        return $this->connection->fetchAll($sql, $params);
    }

    /**
     * Bump the booking counter for the given airline IATA codes.
     */
    public function recordBooking(string ...$codes): void
    {
        $in = implode(', ', array_fill(0, count($codes), '?'));

        $this->connection->execute(
            'UPDATE ' . Table::Airlines->value
            . ' SET book_count = book_count + 1, last_search = NOW()'
            . " WHERE code IN ($in)",
            array_values($codes),
        );
    }

    /**
     * The most-booked airlines first (matches the legacy `orderBy('book_count')`
     * default DESC direction).
     *
     * @return list<array<string, mixed>>
     */
    public function mostBooked(int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Airlines->value . ' ORDER BY book_count DESC LIMIT ' . $limit,
        );
    }
}
