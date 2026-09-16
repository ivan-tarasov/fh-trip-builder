<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\BookingActor;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\RemarkTone;

/**
 * Notes an operator leaves for whoever looks at a booking next.
 *
 * Append-only, same as `booking_events`: there is no update here and no
 * delete except with the booking itself (G8.2, #337).
 *
 * **Unlike `BookingEventRepository::record()`, a write here is not
 * swallowed on error.** The event log sits beside a checkout or a status
 * change that must not fail because a log could not be written; a remark is
 * not beside anything -- it is the whole point of the request, so its own
 * caller has to know if it did not happen.
 *
 * @phpstan-type BookingRemarkRow array{actor: string, tone: string, body: string, created: string}
 */
final readonly class BookingRemarkRepository
{
    public function __construct(private Connection $connection) {}

    public function record(int $bookingId, BookingActor $actor, RemarkTone $tone, string $body): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::BookingRemarks->value
            . ' (booking_id, actor, tone, body, created) VALUES (?, ?, ?, ?, NOW())',
            [$bookingId, $actor->value, $tone->value, $body],
        );
    }

    /**
     * One booking's remarks, newest first -- unlike the event log's
     * oldest-first timeline, this is a running note an operator wants the
     * latest of without scrolling past the rest.
     *
     * `tone` comes back as the case where this version knows it and as the
     * raw word where it does not, so a remark written by a later version is
     * shown rather than hidden.
     *
     * @return list<array{tone: ?RemarkTone, raw_tone: string, actor: ?BookingActor, body: string, created: string}>
     */
    public function forBooking(int $bookingId): array
    {
        /** @var list<BookingRemarkRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT actor, tone, body, created FROM ' . Table::BookingRemarks->value
            . ' WHERE booking_id = ? ORDER BY created DESC, id DESC',
            [$bookingId],
        );

        return array_map(static fn(array $row): array => [
            'tone' => RemarkTone::tryFrom($row['tone']),
            'raw_tone' => $row['tone'],
            'actor' => BookingActor::tryFrom($row['actor']),
            'body' => $row['body'],
            'created' => $row['created'],
        ], $rows);
    }
}
