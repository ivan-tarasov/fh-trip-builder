<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final class SearchRepository
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * The most-searched routes first (matches the legacy `orderBy('search_count')`
     * default DESC direction).
     *
     * @return list<array<string, mixed>>
     */
    public function topSearches(int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Search->value . ' ORDER BY search_count DESC LIMIT ' . $limit,
        );
    }
}
