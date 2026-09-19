<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * One cached Wikipedia answer per city -- see
 * `config/noah/db/tables/city_images.php` for why this exists as a table
 * rather than a live call, and `Noah\Cities\Images` for what fills and
 * refreshes it.
 *
 * @phpstan-type CityImageRow array{image_key: string|null, image_source_url: string|null, extract: string|null, fetched_at: string}
 */
final readonly class CityImageRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Our own S3 key for the city's photo, or null when there is none --
     * either because nothing has looked yet, or because Wikipedia had
     * nothing usable. A caller renders this through `cdn()`, the same way
     * `poi.image` already is; it is never a Wikipedia URL.
     */
    public function imageKeyFor(string $cityCode): ?string
    {
        return $this->find($cityCode)['image_key'] ?? null;
    }

    /**
     * Wikipedia's own summary paragraph for the city, or null -- either
     * because nothing has looked yet, or because Wikipedia had nothing
     * usable (a real 404, or a disambiguation page -- see `Images`).
     * C15 (#405)'s city-page reader.
     */
    public function extractFor(string $cityCode): ?string
    {
        return $this->find($cityCode)['extract'] ?? null;
    }

    /** @return CityImageRow|null null when this city has never been looked up */
    public function find(string $cityCode): ?array
    {
        /** @var CityImageRow|null $row */
        $row = $this->connection->fetchOne(
            'SELECT image_key, image_source_url, extract, fetched_at FROM ' . Table::CityImages->value
            . ' WHERE city_code = ?',
            [$cityCode],
        );

        return $row;
    }

    /**
     * The given codes that have never been looked up, or were looked up
     * more than `$staleAfterDays` ago -- one query rather than one per
     * city, since `Images` runs this against the whole city list every
     * time it starts.
     *
     * @param list<string> $cityCodes
     * @return list<string>
     */
    public function staleOrMissing(array $cityCodes, int $staleAfterDays): array
    {
        if ($cityCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cityCodes), '?'));

        $fresh = array_column($this->connection->fetchAll(
            'SELECT city_code FROM ' . Table::CityImages->value
            . " WHERE city_code IN ($placeholders) AND fetched_at >= NOW() - INTERVAL ? DAY",
            [...$cityCodes, $staleAfterDays],
        ), 'city_code');

        return array_values(array_diff($cityCodes, $fresh));
    }

    /**
     * Record what this run found. All three fields together, since `Images`
     * always answers them from the same Wikipedia response and the same
     * decision about whether the photo changed -- there is no scenario
     * where only one of them is worth writing on its own.
     */
    public function store(string $cityCode, ?string $imageKey, ?string $imageSourceUrl, ?string $extract): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::CityImages->value
            . ' (city_code, image_key, image_source_url, extract, fetched_at)'
            . ' VALUES (?, ?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE image_key = VALUES(image_key),'
            . ' image_source_url = VALUES(image_source_url), extract = VALUES(extract),'
            . ' fetched_at = VALUES(fetched_at)',
            [$cityCode, $imageKey, $imageSourceUrl, $extract],
        );
    }
}
