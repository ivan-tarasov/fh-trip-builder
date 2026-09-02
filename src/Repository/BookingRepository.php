<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use RuntimeException;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class BookingRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Bookings for a session, earliest departure first.
     *
     * @return list<array<string, mixed>>
     */
    public function forSession(string $sessionId): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE session_id = ? ORDER BY departure_time ASC',
            [$sessionId],
        );
    }

    /**
     * Insert a booking from a column => value map; returns the new id.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $columns = array_keys($data);

        $sql = 'INSERT INTO ' . Table::Bookings->value
            . ' (' . implode(', ', array_map(static fn(string $c): string => "`$c`", $columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return $this->connection->insert($sql, array_values($data));
    }

    /**
     * One booking by its reference, scoped to the session that made it — the
     * confirmation page is reachable by URL and a reference is short enough to
     * guess.
     *
     * @return array<string, mixed>|null
     */
    public function findByReference(string $reference, string $sessionId): ?array
    {
        $row = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE reference = ? AND session_id = ? LIMIT 1',
            [$reference, $sessionId],
        );

        return $row[0] ?? null;
    }

    /**
     * A booking reference nobody else holds.
     *
     * Six characters from an alphabet with no O/0 or I/1, so a reference read
     * off a screen and typed back in cannot land on a different booking.
     */
    public function unusedReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        // A collision is vanishingly unlikely; looping is still cheaper than
        // explaining a duplicate key to whoever hits one.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $reference = '';

            for ($i = 0; $i < 6; $i++) {
                $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $taken = $this->connection->fetchValue(
                'SELECT COUNT(*) FROM ' . Table::Bookings->value . ' WHERE reference = ?',
                [$reference],
            );

            if ((int) $taken === 0) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not allocate a booking reference.');
    }

    /**
     * Delete a booking scoped to its session; returns affected row count.
     */
    public function deleteForSession(int $bookingId, string $sessionId): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::Bookings->value . ' WHERE id = ? AND session_id = ?',
            [$bookingId, $sessionId],
        );
    }
}
