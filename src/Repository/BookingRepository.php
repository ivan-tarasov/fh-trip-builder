<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use RuntimeException;
use TripBuilder\BookingStatus;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class BookingRepository
{
    /**
     * How long a booking is kept after the flight has gone.
     *
     * The retention policy, in one place and written into the README beside
     * the install steps -- a policy nobody can find is not one (E9, #146).
     *
     * Ninety days rather than a year, because nothing here needs a year.
     * `bookings` and `booking_passengers` hold an email, a phone number, names
     * and dates of birth, and every read of them is scoped by `session_id`:
     * once a visitor's session is gone there is no query in this application
     * that can reach the row again, and no admin panel to add one. So the only
     * purpose the data still serves after departure is a visitor coming back
     * to a trip they took, and ninety days is generous for that.
     */
    public const int KEEP_DAYS_AFTER_DEPARTURE = 90;

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
     * One booking by id, scoped to the session that made it -- an id from
     * somebody else's browser resolves to nothing.
     *
     * Keyed on the id rather than the reference because the rows written before
     * checkout issued references have none, and those bookings still belong to
     * whoever made them.
     *
     * @return array<string, mixed>|null
     */
    public function findForSession(int $bookingId, string $sessionId): ?array
    {
        $row = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE id = ? AND session_id = ? LIMIT 1',
            [$bookingId, $sessionId],
        );

        return $row[0] ?? null;
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
     * Cancel a booking scoped to its session; returns affected row count.
     *
     * The row stays. What used to sit here deleted it outright, which left a
     * traveller no way to tell a cancelled trip from one that had never been
     * booked. Cancelling twice affects nothing, so the caller can tell "not
     * yours" and "already cancelled" apart from a real cancellation.
     */
    public function cancelForSession(int $bookingId, string $sessionId): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::Bookings->value
            . ' SET status = ? WHERE id = ? AND session_id = ? AND status <> ?',
            [
                BookingStatus::Cancelled->value,
                $bookingId,
                $sessionId,
                BookingStatus::Cancelled->value,
            ],
        );
    }

    /**
     * Bookings whose flight left before the cutoff, oldest first.
     *
     * Read before deleting so the sweep can say what it is about to remove.
     * Only what identifies the booking comes back -- there is no reason for a
     * command that exists to destroy personal information to print any.
     *
     * @return list<array{id: int, reference: string, departure_time: string}>
     */
    public function departedBefore(string $cutoff): array
    {
        /** @var list<array{id: int, reference: string, departure_time: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, reference, departure_time FROM ' . Table::Bookings->value
            . ' WHERE departure_time < ? ORDER BY departure_time ASC',
            [$cutoff],
        );

        return $rows;
    }

    /**
     * Remove those bookings and everyone travelling on them.
     *
     * Passengers first. There is no foreign key between the two tables, so
     * deleting the parent first would leave the children pointing at a booking
     * that is gone -- which is the shape of a bug that keeps the personal
     * information and loses the thing that explains it.
     *
     * @return array{bookings: int, passengers: int}
     */
    public function forgetDepartedBefore(string $cutoff): array
    {
        $expired = 'SELECT id FROM ' . Table::Bookings->value . ' WHERE departure_time < ?';

        $passengers = $this->connection->execute(
            'DELETE FROM ' . Table::BookingPassengers->value . ' WHERE booking_id IN (' . $expired . ')',
            [$cutoff],
        );

        $bookings = $this->connection->execute(
            'DELETE FROM ' . Table::Bookings->value . ' WHERE departure_time < ?',
            [$cutoff],
        );

        return ['bookings' => $bookings, 'passengers' => $passengers];
    }
}
