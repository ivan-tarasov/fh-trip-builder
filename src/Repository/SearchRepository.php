<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class SearchRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * The most-searched routes first (matches the legacy `orderBy('search_count')`
     * default DESC direction).
     *
     * @return list<array<string, mixed>>
     */
    public function topSearches(int $limit): array
    {
        // Only trips that can still be taken. A row is identified partly by its
        // departure date, so a popular search ages into a dead one: the most
        // searched route on the home page was offering a date two days before
        // the earliest flight in the table, and returned nothing when clicked.
        // The card prints the date without a year, so it read as an upcoming
        // trip rather than a stale one.
        //
        // CURDATE(), now that `depart` is a DATE: the comparison is between two
        // dates rather than between two strings that happen to sort like them,
        // and the cutoff is the database's own day rather than PHP's.
        return $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Search->value
            . ' WHERE depart >= CURDATE()'
            . ' ORDER BY search_count DESC LIMIT ' . $limit,
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
     * The hash identifying one search: the route, the dates, the trip type and
     * the cabin. Two searches differing in any of those are different rows,
     * which is what lets a hash link resolve back to the search that made it.
     *
     * Economy is left out of the digest on purpose. It is the default cabin, so
     * folding it in would change the hash of every search already recorded --
     * orphaning those rows and restarting the counts the homepage ranks by.
     * Any other cabin is appended, so it gets a row of its own.
     */
    public static function hashFor(
        string $fromCode,
        string $toCode,
        string $depart,
        ?string $return,
        string $triptype,
        CabinClass $cabin,
    ): string {
        $identity = sprintf(
            '%s:%s:%s:%s:%s',
            $fromCode,
            $toCode,
            $depart,
            $return,
            $triptype,
        );

        if ($cabin !== CabinClass::Economy) {
            $identity .= ':' . $cabin->value;
        }

        return md5($identity);
    }

    /**
     * Record a search: insert it, or bump its count + last_search on repeat.
     * (`return` and `class` are reserved words, hence the backticks.)
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
        CabinClass $cabin,
    ): void {
        $this->connection->execute(
            'INSERT INTO ' . Table::Search->value
            . ' (hash, from_code, from_name, to_code, to_name, depart, `return`, triptype, `class`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE search_count = search_count + 1, last_search = NOW()',
            [$hash, $fromCode, $fromName, $toCode, $toName, $depart, $return, $triptype, $cabin->value],
        );
    }
}
