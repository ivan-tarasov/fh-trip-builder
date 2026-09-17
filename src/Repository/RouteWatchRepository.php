<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * @phpstan-type RouteWatchRow array{
 *     id: int, email: string, threshold: float, notified_price: ?float,
 * }
 */
final readonly class RouteWatchRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Register a watch, or update one already there for this exact address
     * and route.
     *
     * A changed threshold clears `notified_price`: a visitor who lowers what
     * they are willing to pay should be evaluated fresh against it, not
     * silenced by an alert sent for the old, higher one.
     */
    public function subscribe(string $email, string $from, string $to, CabinClass $cabin, float $threshold): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::RouteWatches->value
            . ' (email, from_code, to_code, cabin, threshold) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE threshold = ?, notified_price = NULL',
            [$email, $from, $to, $cabin->value, $threshold, $threshold],
        );
    }

    /**
     * Every distinct route being watched -- what `alerts:check` groups by,
     * so a busy route with a hundred watches costs one `cheapest()` read
     * rather than a hundred.
     *
     * @return list<array{from_code: string, to_code: string, cabin: string}>
     */
    public function routes(): array
    {
        /** @var list<array{from_code: string, to_code: string, cabin: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT from_code, to_code, cabin FROM ' . Table::RouteWatches->value,
        );

        return $rows;
    }

    /**
     * Every watch on one route.
     *
     * @return list<RouteWatchRow>
     */
    public function forRoute(string $from, string $to, CabinClass $cabin): array
    {
        /** @var list<array{id: int|string, email: string, threshold: float|string, notified_price: float|string|null}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, email, threshold, notified_price FROM ' . Table::RouteWatches->value
            . ' WHERE from_code = ? AND to_code = ? AND cabin = ?',
            [$from, $to, $cabin->value],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'threshold' => (float) $row['threshold'],
            'notified_price' => $row['notified_price'] === null ? null : (float) $row['notified_price'],
        ], $rows);
    }

    /** Record the price a watch was just alerted at. */
    public function markNotified(int $id, float $price): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::RouteWatches->value . ' SET notified_price = ? WHERE id = ?',
            [$price, $id],
        );
    }

    /** The price has risen back above threshold -- the next dip should alert fresh. */
    public function clearNotified(int $id): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::RouteWatches->value . ' SET notified_price = NULL WHERE id = ?',
            [$id],
        );
    }
}
