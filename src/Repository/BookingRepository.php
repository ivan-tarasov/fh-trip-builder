<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final class BookingRepository
{
    public function __construct(private readonly Connection $connection) {}

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
}
