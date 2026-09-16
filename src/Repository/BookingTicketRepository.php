<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\DocumentType;
use TripBuilder\TicketStatus;

/**
 * Travel documents, one row per ticket or EMD, each belonging to a
 * traveller rather than to the booking itself -- a passenger can hold more
 * than one (G8.3, #338).
 *
 * `setStatus()` and `remove()` both join back to `booking_passengers` and
 * check its `booking_id`, the same way `BookingRepository::cancelForSession()`
 * scopes a write: the form only ever offers a row from the booking whose
 * page it is on, but a tampered POST could name a ticket that belongs to a
 * different one.
 *
 * @phpstan-type TicketRow array{
 *     id: int, booking_passenger_id: int, first_name: string, last_name: string,
 *     passenger_type: string, document_type: string, document_number: string,
 *     status: string, issue_date: string,
 * }
 */
final readonly class BookingTicketRepository
{
    /** What `booking_passengers.type` spells out on this page. */
    private const array PASSENGER_TYPES = ['A' => 'Adult', 'C' => 'Child', 'I' => 'Infant'];

    public function __construct(private Connection $connection) {}

    public function create(
        int $bookingPassengerId,
        DocumentType $type,
        string $number,
        TicketStatus $status,
        string $issueDate,
    ): int {
        return $this->connection->insert(
            'INSERT INTO ' . Table::BookingTickets->value
            . ' (booking_passenger_id, document_type, document_number, status, issue_date, created)'
            . ' VALUES (?, ?, ?, ?, ?, NOW())',
            [$bookingPassengerId, $type->value, $number, $status->value, $issueDate],
        );
    }

    /**
     * One booking's tickets, passenger order then oldest first -- everyone's
     * documents grouped together rather than interleaved by when each was
     * added.
     *
     * `document_type` and `status` come back as the case where this version
     * knows it and as the raw word where it does not, same reasoning as
     * `BookingEventRepository::forBooking()`.
     *
     * @return list<array{
     *     id: int, booking_passenger_id: int, passenger: string, passenger_type: string,
     *     document_type: ?DocumentType, raw_document_type: string, document_number: string,
     *     status: ?TicketStatus, raw_status: string, issue_date: string,
     * }>
     */
    public function forBooking(int $bookingId): array
    {
        /** @var list<TicketRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT t.id, t.booking_passenger_id, p.first_name, p.last_name, p.type AS passenger_type,'
            . ' t.document_type, t.document_number, t.status, t.issue_date'
            . ' FROM ' . Table::BookingTickets->value . ' t'
            . ' JOIN ' . Table::BookingPassengers->value . ' p ON p.id = t.booking_passenger_id'
            . ' WHERE p.booking_id = ?'
            . ' ORDER BY p.position ASC, t.issue_date ASC, t.id ASC',
            [$bookingId],
        );

        return array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'booking_passenger_id' => $row['booking_passenger_id'],
            'passenger' => trim($row['first_name'] . ' ' . $row['last_name']),
            'passenger_type' => self::PASSENGER_TYPES[$row['passenger_type']] ?? $row['passenger_type'],
            'document_type' => DocumentType::tryFrom($row['document_type']),
            'raw_document_type' => $row['document_type'],
            'document_number' => $row['document_number'],
            'status' => TicketStatus::tryFrom($row['status']),
            'raw_status' => $row['status'],
            'issue_date' => $row['issue_date'],
        ], $rows);
    }

    public function setStatus(int $id, int $bookingId, TicketStatus $status): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::BookingTickets->value . ' t'
            . ' JOIN ' . Table::BookingPassengers->value . ' p ON p.id = t.booking_passenger_id'
            . ' SET t.status = ? WHERE t.id = ? AND p.booking_id = ?',
            [$status->value, $id, $bookingId],
        );
    }

    public function remove(int $id, int $bookingId): int
    {
        return $this->connection->execute(
            'DELETE t FROM ' . Table::BookingTickets->value . ' t'
            . ' JOIN ' . Table::BookingPassengers->value . ' p ON p.id = t.booking_passenger_id'
            . ' WHERE t.id = ? AND p.booking_id = ?',
            [$id, $bookingId],
        );
    }
}
