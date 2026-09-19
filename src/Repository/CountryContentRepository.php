<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * One cached Wikipedia summary per country -- see
 * `config/noah/db/tables/country_content.php` for why this exists as a
 * table rather than a live call, and `Noah\Countries\Content` for what
 * fills and refreshes it. C17 (#409)'s reader, on the same shape
 * `CityImageRepository` already uses for cities (C10, #395).
 *
 * @phpstan-type CountryContentRow array{extract: string|null, fetched_at: string}
 */
final readonly class CountryContentRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Wikipedia's own summary paragraph for the country, or null --
     * either because nothing has looked yet, or because Wikipedia had
     * nothing usable.
     */
    public function extractFor(string $countryCode): ?string
    {
        return $this->find($countryCode)['extract'] ?? null;
    }

    /** @return CountryContentRow|null null when this country has never been looked up */
    public function find(string $countryCode): ?array
    {
        /** @var CountryContentRow|null $row */
        $row = $this->connection->fetchOne(
            'SELECT extract, fetched_at FROM ' . Table::CountryContent->value . ' WHERE country_code = ?',
            [$countryCode],
        );

        return $row;
    }

    /**
     * The given codes that have never been looked up, or were looked up
     * more than `$staleAfterDays` ago -- one query rather than one per
     * country, the same reason `CityImageRepository::staleOrMissing()`
     * is.
     *
     * @param list<string> $countryCodes
     * @return list<string>
     */
    public function staleOrMissing(array $countryCodes, int $staleAfterDays): array
    {
        if ($countryCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($countryCodes), '?'));

        $fresh = array_column($this->connection->fetchAll(
            'SELECT country_code FROM ' . Table::CountryContent->value
            . " WHERE country_code IN ($placeholders) AND fetched_at >= NOW() - INTERVAL ? DAY",
            [...$countryCodes, $staleAfterDays],
        ), 'country_code');

        return array_values(array_diff($countryCodes, $fresh));
    }

    /** Record what this run found -- a real summary, or null when Wikipedia had nothing. */
    public function store(string $countryCode, ?string $extract): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::CountryContent->value . ' (country_code, extract, fetched_at)'
            . ' VALUES (?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE extract = VALUES(extract), fetched_at = VALUES(fetched_at)',
            [$countryCode, $extract],
        );
    }
}
