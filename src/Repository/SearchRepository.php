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

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM ' . Table::Search->value . ' WHERE hash = ? LIMIT 1',
            [$hash],
        );
    }

    /**
     * Record a search: insert it, or bump its count + last_search on repeat.
     * (`return` is a reserved word, hence the backticks.)
     */
    public function record(
        string $hash,
        string $fromCode,
        string $fromName,
        string $toCode,
        string $toName,
        string $depart,
        ?string $return,
        string $triptype,
    ): void {
        $this->connection->execute(
            'INSERT INTO ' . Table::Search->value
            . ' (hash, from_code, from_name, to_code, to_name, depart, `return`, triptype)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE search_count = search_count + 1, last_search = NOW()',
            [$hash, $fromCode, $fromName, $toCode, $toName, $depart, $return, $triptype],
        );
    }
}
