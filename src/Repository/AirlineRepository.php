<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class AirlineRepository
{
    public function __construct(private Connection $connection) {}

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
