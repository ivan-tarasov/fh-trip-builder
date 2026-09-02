<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Aircraft types. A flight stores only the IATA type code, so the search never
 * joins this table — the sidebar looks the titles up once to label the codes a
 * search turned out to offer.
 */
final readonly class AircraftRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Every type, keyed by code.
     *
     * @return array<string, array{code: string, title: string, max_range_km: int, is_widebody: bool}>
     */
    public function all(): array
    {
        $types = [];

        foreach ($this->connection->fetchAll(
            'SELECT code, title, max_range_km, is_widebody FROM ' . Table::Aircraft->value . ' ORDER BY title ASC',
        ) as $row) {
            $types[(string) $row['code']] = [
                'code' => (string) $row['code'],
                'title' => (string) $row['title'],
                'max_range_km' => (int) $row['max_range_km'],
                'is_widebody' => (bool) $row['is_widebody'],
            ];
        }

        return $types;
    }
}
