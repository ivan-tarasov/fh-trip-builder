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
     * The most recent bookings, newest first, for the panel.
     *
     * **The first read here that is not scoped to one session**, and that is
     * the whole of what makes it new. Every other read answers "what has this
     * browser bought", because that is all the public site ever needs to know;
     * an operator looking one up is a different question and a different
     * responsibility (A3.8, #233).
     *
     * `created` and not `departure_time`: the panel is asked about bookings in
     * the order they were made, where the traveller's own list is about the
     * order they will be flown.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit, int $offset = 0): array
    {
        return $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value
            // Id second, so two bookings made in the same second come back in a
            // fixed order rather than whichever the engine offers.
            . ' ORDER BY created DESC, id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        );
    }

    /** How many there are, so the panel can page through them. */
    public function countAll(): int
    {
        return (int) $this->connection->fetchValue('SELECT COUNT(*) FROM ' . Table::Bookings->value);
    }

    /**
     * One booking, whoever made it.
     *
     * The unscoped twin of `findForSession()`. Kept apart rather than made a
     * nullable argument on that one, deliberately: an optional session is one
     * forgotten argument away from serving somebody else's booking to a
     * stranger, and this is only reachable from behind the panel's guard.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $bookingId): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE id = ?',
            [$bookingId],
        );
    }

    /**
     * Move one booking's status from the panel.
     *
     * Unscoped, like `find()`, and narrowed the same way `cancelForSession()`
     * narrows: the update names the status it expects to find, so asking to
     * cancel an already-cancelled booking changes nothing and returns zero.
     * The caller logs only when a row actually moved -- a log of a change that
     * did not happen is worse than no log.
     */
    public function setStatus(int $bookingId, BookingStatus $to, BookingStatus $from): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::Bookings->value
            . ' SET status = ? WHERE id = ? AND status = ?',
            [$to->value, $bookingId, $from->value],
        );
    }

    /**
     * Bookings matching a search, newest first.
     *
     * What an operator has in front of them when somebody calls: a reference
     * off an email, a name, or the address they wrote from. The address is
     * searched and never listed -- see `AdminController::bookings()` on what a
     * list is for.
     *
     * `LIKE` with the term in the middle rather than a prefix, because a
     * surname typed into a support ticket is as often the second word as the
     * first. It scans; on a table this size that is nothing, and on one where
     * it is not, this is where a full-text index would go.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit, int $offset = 0): array
    {
        $like = self::like($term);

        return $this->connection->fetchAll(
            'SELECT b.* FROM ' . Table::Bookings->value . ' b'
            . ' WHERE b.reference LIKE ? OR b.contact_email LIKE ?'
            . '  OR CONCAT(b.passenger_first, \' \', b.passenger_last) LIKE ?'
            . '  OR EXISTS ('
            . '   SELECT 1 FROM ' . Table::BookingPassengers->value . ' p'
            . '   WHERE p.booking_id = b.id'
            . '    AND CONCAT(p.first_name, \' \', p.last_name) LIKE ?'
            . '  )'
            . ' ORDER BY b.created DESC, b.id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$like, $like, $like, $like],
        );
    }

    /** How many bookings a search matches, for the pager. */
    public function countMatching(string $term): int
    {
        $like = self::like($term);

        return (int) $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::Bookings->value . ' b'
            . ' WHERE b.reference LIKE ? OR b.contact_email LIKE ?'
            . '  OR CONCAT(b.passenger_first, \' \', b.passenger_last) LIKE ?'
            . '  OR EXISTS ('
            . '   SELECT 1 FROM ' . Table::BookingPassengers->value . ' p'
            . '   WHERE p.booking_id = b.id'
            . '    AND CONCAT(p.first_name, \' \', p.last_name) LIKE ?'
            . '  )',
            [$like, $like, $like, $like],
        );
    }

    /**
     * A search term as a `LIKE` pattern, with its wildcards as characters.
     *
     * `%` and `_` mean something to `LIKE`, and somebody searching for a
     * reference with an underscore in it means the underscore. Not a safety
     * matter -- the term is bound, never concatenated -- but a search that
     * quietly matches everything for a term of `%` is a search that lies about
     * what it did.
     */
    private static function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
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
     * @return array{bookings: int, passengers: int, events: int}
     */
    public function forgetDepartedBefore(string $cutoff): array
    {
        $expired = 'SELECT id FROM ' . Table::Bookings->value . ' WHERE departure_time < ?';

        $passengers = $this->connection->execute(
            'DELETE FROM ' . Table::BookingPassengers->value . ' WHERE booking_id IN (' . $expired . ')',
            [$cutoff],
        );

        // The log goes with it. The events carry no personal data, but a log of
        // a booking that has been forgotten is a record of a booking that has
        // been forgotten -- which is the thing this sweep exists to prevent
        // (A3.8, #233).
        $events = $this->connection->execute(
            'DELETE FROM ' . Table::BookingEvents->value . ' WHERE booking_id IN (' . $expired . ')',
            [$cutoff],
        );

        $bookings = $this->connection->execute(
            'DELETE FROM ' . Table::Bookings->value . ' WHERE departure_time < ?',
            [$cutoff],
        );

        return ['bookings' => $bookings, 'passengers' => $passengers, 'events' => $events];
    }
}
