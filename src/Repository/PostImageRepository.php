<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * How big each Airside picture is.
 *
 * A lookup and not a measurement. The page used to measure -- `getimagesize()`
 * against the staging directory -- which stopped being possible when A8.6 sent
 * the files to a bucket instead of committing them. The importer is holding
 * the bytes anyway, so it records the answer once and the page reads it.
 */
final readonly class PostImageRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Record one file's size, or correct it.
     *
     * `ON DUPLICATE KEY` because a body image keeps its name when its contents
     * change -- only heroes are hashed -- so re-importing a replaced picture
     * has to move the existing row rather than fail on the key.
     */
    public function store(string $file, int $width, int $height): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::PostImages->value . ' (file, width, height)'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE width = VALUES(width), height = VALUES(height)',
            [$file, $width, $height],
        );
    }

    /**
     * Every size there is, keyed by file name.
     *
     * The whole table rather than the handful one page needs. It holds one row
     * per picture in the section -- tens, not thousands -- and a page draws a
     * hero plus whatever its body carries, so fetching them individually would
     * be several queries to save reading a few dozen rows.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function all(): array
    {
        $sizes = [];

        foreach ($this->connection->fetchAll(
            'SELECT file, width, height FROM ' . Table::PostImages->value,
        ) as $row) {
            $sizes[(string) $row['file']] = [(int) $row['width'], (int) $row['height']];
        }

        return $sizes;
    }

    /** Forget one file, for when nothing names it any more. */
    public function delete(string $file): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::PostImages->value . ' WHERE file = ?',
            [$file],
        );
    }
}
