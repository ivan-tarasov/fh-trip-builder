<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use Throwable;
use TripBuilder\BookingActor;
use TripBuilder\BookingEvent;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * What has happened to a booking, in order.
 *
 * Append-only: there is no update here and no delete except with the booking
 * itself. A log somebody can edit is not a log (A3.8, #233).
 *
 * **Writing never fails a booking.** Every call is swallowed on error, for the
 * same reason the rate-limit check fails open: this table records what happened
 * and is not the thing that happens. A checkout that rolled back because the
 * log was unavailable would turn a note into an outage.
 */
final readonly class BookingEventRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Record one thing that happened.
     *
     * @param string $note the one detail the kind cannot carry -- which way a
     *     status went, or what the panel was doing. Never personal data.
     */
    public function record(int $bookingId, BookingEvent $event, BookingActor $actor, string $note = ''): void
    {
        try {
            $this->connection->execute(
                'INSERT INTO ' . Table::BookingEvents->value
                . ' (booking_id, event, actor, note, at) VALUES (?, ?, ?, ?, NOW())',
                [$bookingId, $event->value, $actor->value, mb_substr($note, 0, 190)],
            );
        } catch (Throwable) {
            // Nothing to do about it, and nothing worth failing a booking for.
        }
    }

    /**
     * One booking's events, oldest first.
     *
     * `event` comes back as the case where this version knows it and as the
     * raw word where it does not, so a row written by a later version is shown
     * rather than hidden.
     *
     * @return list<array{event: ?BookingEvent, raw: string, actor: ?BookingActor, note: string, at: string}>
     */
    public function forBooking(int $bookingId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT event, actor, note, at FROM ' . Table::BookingEvents->value
            // Id second: two events in the same second are ordered by the order
            // they were written, which is the order they happened.
            . ' WHERE booking_id = ? ORDER BY at ASC, id ASC',
            [$bookingId],
        );

        return array_map(static fn(array $row): array => [
            'event' => BookingEvent::tryFrom((string) $row['event']),
            'raw' => (string) $row['event'],
            'actor' => BookingActor::tryFrom((string) $row['actor']),
            'note' => (string) ($row['note'] ?? ''),
            'at' => (string) $row['at'],
        ], $rows);
    }

    /**
     * When the first thing was ever recorded, on any booking.
     *
     * The log begins the day it is installed, and a booking older than that has
     * no events for the same reason a diary has nothing in it about last year.
     * Without this the page would show an empty log and let somebody read it as
     * "nothing ever happened to this booking", which is a different and untrue
     * statement (A3.8, #233).
     *
     * Null while nothing has been recorded at all.
     */
    public function startedAt(): ?string
    {
        $first = $this->connection->fetchValue(
            'SELECT MIN(at) FROM ' . Table::BookingEvents->value,
        );

        return $first === null ? null : (string) $first;
    }

    /**
     * Remove the events with the booking they belong to.
     *
     * Called by the retention sweep. They carry no personal data, but a log of
     * a booking that has been forgotten is a record of a booking that has been
     * forgotten -- which is the thing `db:prune` exists to prevent.
     */
    public function forgetFor(int $bookingId): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::BookingEvents->value . ' WHERE booking_id = ?',
            [$bookingId],
        );
    }
}
