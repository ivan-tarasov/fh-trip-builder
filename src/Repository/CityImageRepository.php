<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * One photo per city, cached from Wikipedia -- see
 * `config/noah/db/tables/city_images.php` for why this exists as a table
 * rather than a live call, and `Noah\Cities\Images` for what fills it.
 *
 * @phpstan-type CityImageRow array{city_code: string, image_url: string|null, fetched_at: string}
 */
final readonly class CityImageRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * The cached photo for a city, or null when there is none -- either
     * because nothing has looked yet, or because `Images` looked and
     * Wikipedia had nothing usable. Callers that need to tell those two
     * apart want {@see has()} first.
     */
    public function imageFor(string $cityCode): ?string
    {
        /** @var array{image_url: string|null}|null $row */
        $row = $this->connection->fetchOne(
            'SELECT image_url FROM ' . Table::CityImages->value . ' WHERE city_code = ?',
            [$cityCode],
        );

        return $row['image_url'] ?? null;
    }

    /**
     * Whether this city has ever been looked up, successfully or not --
     * what `Images` reads before spending a request on it again.
     */
    public function has(string $cityCode): bool
    {
        return $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::CityImages->value . ' WHERE city_code = ?',
            [$cityCode],
        ) > 0;
    }

    /**
     * Record what Wikipedia answered -- a real URL, or null when it had
     * nothing. Both are worth storing: a null recorded now is a request
     * `Images` does not spend again next run.
     */
    public function store(string $cityCode, ?string $imageUrl): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::CityImages->value . ' (city_code, image_url, fetched_at)'
            . ' VALUES (?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE image_url = VALUES(image_url), fetched_at = VALUES(fetched_at)',
            [$cityCode, $imageUrl],
        );
    }
}
