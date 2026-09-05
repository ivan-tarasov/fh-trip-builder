<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The travellers on a booking.
 *
 * A booking's own row keeps the lead passenger, so the bookings list can render
 * a name without a join. Everyone, the lead included, is here.
 */
final readonly class BookingPassengerRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Write a booking's travellers in the order they were entered.
     *
     * The caller owns the transaction: this runs one statement per passenger
     * and a booking with half its party recorded is worse than no booking.
     *
     * @param list<array<string, mixed>> $passengers
     */
    public function createFor(int $bookingId, array $passengers): void
    {
        $sql = 'INSERT INTO ' . Table::BookingPassengers->value
            . ' (booking_id, position, type, first_name, last_name, dob, gender)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)';

        foreach (array_values($passengers) as $index => $passenger) {
            $this->connection->execute($sql, [
                $bookingId,
                $index + 1,
                $passenger['type'],
                $passenger['first_name'],
                $passenger['last_name'],
                $passenger['dob'],
                $passenger['gender'],
            ]);
        }
    }

    /**
     * A booking's travellers, lead first.
     *
     * @return list<array<string, mixed>>
     */
    public function forBooking(int $bookingId): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM ' . Table::BookingPassengers->value
            . ' WHERE booking_id = ? ORDER BY position ASC',
            [$bookingId],
        );
    }
}
