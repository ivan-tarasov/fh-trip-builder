<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Http\RateLimit;

final readonly class RateLimitRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Count this request, and say whether it is one too many.
     *
     * The count happens either way. A refused request is still a request, so
     * a client that keeps going stays refused for the rest of the hour rather
     * than getting a fresh allowance for holding the door.
     *
     * `ON DUPLICATE KEY UPDATE` and not a `SELECT` then an `UPDATE`: two
     * requests arriving together both read the same count and both write it
     * back, which loses one of them. The primary key settles it instead.
     *
     * Written without the row-alias form MySQL 8.0.19 added -- MariaDB rejects
     * it, and testing only one engine let exactly that reach production once
     * before.
     */
    public function exceeded(RateLimit $scope, string $client): bool
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::RateLimits->value
            . ' (scope, client, window_start, hits) VALUES (?, ?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$scope->value, $client, self::window()],
        );

        $hits = (int) $this->connection->fetchValue(
            'SELECT hits FROM ' . Table::RateLimits->value
            . ' WHERE scope = ? AND client = ? AND window_start = ?',
            [$scope->value, $client, self::window()],
            0,
        );

        return $hits > $scope->perHour();
    }

    /**
     * Drop counters for hours that have passed.
     *
     * A row is never read again once its hour is over, so this is housekeeping
     * rather than correctness. E9 (#146) is the command that will call it; a
     * day of slack so a support question about "earlier today" still has rows
     * to look at.
     */
    public function prune(): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::RateLimits->value . ' WHERE window_start < (NOW() - INTERVAL 1 DAY)',
        );
    }

    /** The hour the current moment belongs to. */
    private static function window(): string
    {
        return date('Y-m-d H:00:00');
    }
}
