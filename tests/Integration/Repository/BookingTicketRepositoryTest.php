<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\DocumentType;
use TripBuilder\Repository\BookingPassengerRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\BookingTicketRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\TicketStatus;

/**
 * Tickets, against a real table.
 *
 * The rows here carry a made-up session and references no allocator will
 * issue, and they are removed afterwards: this table holds documents
 * against real people on any install that has taken a booking (G8.3, #338).
 */
final class BookingTicketRepositoryTest extends IntegrationTestCase
{
    private const string SESSION = 'zzt-not-a-real-session';

    /** @var list<int> */
    private array $bookings = [];

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->bookings as $id) {
            $this->connection()->execute(
                'DELETE FROM booking_tickets WHERE booking_passenger_id IN'
                . ' (SELECT id FROM booking_passengers WHERE booking_id = ?)',
                [$id],
            );
            $this->connection()->execute('DELETE FROM booking_passengers WHERE booking_id = ?', [$id]);
            $this->connection()->execute('DELETE FROM bookings WHERE id = ?', [$id]);
        }
    }

    public function testATicketReadsBackWithThePassengerItBelongsTo(): void
    {
        $id = $this->insert('ZZT001');
        $passengerId = $this->firstPassengerId($id);

        $this->tickets()->create($passengerId, DocumentType::Ticket, '1234567890123', TicketStatus::Issued, '2026-01-15');

        $lines = $this->tickets()->forBooking($id);

        self::assertCount(1, $lines);
        self::assertSame('Zzlead Zzsurname', $lines[0]['passenger']);
        self::assertSame('Adult', $lines[0]['passenger_type']);
        self::assertSame(DocumentType::Ticket, $lines[0]['document_type']);
        self::assertSame('1234567890123', $lines[0]['document_number']);
        self::assertSame(TicketStatus::Issued, $lines[0]['status']);
    }

    public function testSetStatusChangesIt(): void
    {
        $id = $this->insert('ZZT002');
        $passengerId = $this->firstPassengerId($id);

        $ticketId = $this->tickets()->create($passengerId, DocumentType::Ticket, '1111111111111', TicketStatus::Issued, '2026-01-15');

        self::assertSame(1, $this->tickets()->setStatus($ticketId, $id, TicketStatus::Voided));
        self::assertSame(TicketStatus::Voided, $this->tickets()->forBooking($id)[0]['status']);
    }

    /**
     * A ticket id from another booking cannot be touched through this one --
     * the join scopes both the write and the read to the booking it
     * actually belongs to (G8.3, #338).
     */
    public function testSetStatusIsScopedToTheBookingItBelongsTo(): void
    {
        $ownId = $this->insert('ZZT003');
        $otherId = $this->insert('ZZT004');
        $otherPassengerId = $this->firstPassengerId($otherId);

        $otherTicketId = $this->tickets()->create($otherPassengerId, DocumentType::Ticket, '2222222222222', TicketStatus::Issued, '2026-01-15');

        self::assertSame(
            0,
            $this->tickets()->setStatus($otherTicketId, $ownId, TicketStatus::Voided),
            'a ticket from a different booking should not have moved',
        );
        self::assertSame(TicketStatus::Issued, $this->tickets()->forBooking($otherId)[0]['status']);
    }

    public function testRemoveDropsTheRowScopedToItsOwnBooking(): void
    {
        $id = $this->insert('ZZT005');
        $passengerId = $this->firstPassengerId($id);

        $ticketId = $this->tickets()->create($passengerId, DocumentType::Ticket, '3333333333333', TicketStatus::Issued, '2026-01-15');

        self::assertSame(0, $this->tickets()->remove($ticketId, $this->insert('ZZT006')), 'removed through the wrong booking');
        self::assertCount(1, $this->tickets()->forBooking($id));

        self::assertSame(1, $this->tickets()->remove($ticketId, $id));
        self::assertSame([], $this->tickets()->forBooking($id));
    }

    public function testFindReadsBackOneTicketScopedToItsBooking(): void
    {
        $id = $this->insert('ZZT010');
        $otherId = $this->insert('ZZT011');
        $passengerId = $this->firstPassengerId($id);

        $ticketId = $this->tickets()->create($passengerId, DocumentType::Emd, '6666666666666', TicketStatus::Issued, '2026-01-15');

        $found = $this->tickets()->find($ticketId, $id);

        self::assertNotNull($found);
        self::assertSame('6666666666666', $found['document_number']);
        self::assertSame(DocumentType::Emd, $found['document_type']);

        self::assertNull($this->tickets()->find($ticketId, $otherId), 'a ticket from a different booking should not be found');
        self::assertNull($this->tickets()->find(0, $id), 'a ticket that does not exist should not be found');
    }

    public function testUpdateNumberChangesItScopedToItsOwnBooking(): void
    {
        $id = $this->insert('ZZT012');
        $passengerId = $this->firstPassengerId($id);

        $ticketId = $this->tickets()->create($passengerId, DocumentType::Ticket, '7777777777777', TicketStatus::Issued, '2026-01-15');

        self::assertSame(
            0,
            $this->tickets()->updateNumber($ticketId, $this->insert('ZZT013'), '8888888888888'),
            'updated through the wrong booking',
        );
        self::assertSame('7777777777777', $this->tickets()->find($ticketId, $id)['document_number'] ?? null);

        self::assertSame(1, $this->tickets()->updateNumber($ticketId, $id, '9999999999999'));
        self::assertSame('9999999999999', $this->tickets()->find($ticketId, $id)['document_number'] ?? null);
    }

    public function testPassengersWithoutTicketsFindsOnlyThoseWithNone(): void
    {
        $withTicket = $this->insert('ZZT008');
        $withoutTicket = $this->insert('ZZT009');

        $withTicketPassenger = $this->firstPassengerId($withTicket);
        $withoutTicketPassenger = $this->firstPassengerId($withoutTicket);

        $this->tickets()->create($withTicketPassenger, DocumentType::Ticket, '5555555555555', TicketStatus::Issued, '2026-01-15');

        self::assertSame([], $this->tickets()->passengersWithoutTickets($withTicket));
        self::assertSame([$withoutTicketPassenger], $this->tickets()->passengersWithoutTickets($withoutTicket));
    }

    /**
     * A document type this version does not know is printed, not hidden --
     * same reasoning as an unrecognised `BookingEvent` in the log.
     */
    public function testADocumentTypeThisVersionDoesNotKnowStillReads(): void
    {
        $id = $this->insert('ZZT007');
        $passengerId = $this->firstPassengerId($id);

        $this->connection()->execute(
            'INSERT INTO booking_tickets (booking_passenger_id, document_type, document_number, status, issue_date, created)'
            . ' VALUES (?, ?, ?, ?, ?, NOW())',
            [$passengerId, 'ancillary', '4444444444444', 'issued', '2026-01-15'],
        );

        $lines = $this->tickets()->forBooking($id);

        self::assertCount(1, $lines);
        self::assertNull($lines[0]['document_type'], 'an unknown word should not resolve to a case');
        self::assertSame('ancillary', $lines[0]['raw_document_type']);
    }

    private function tickets(): BookingTicketRepository
    {
        return new BookingTicketRepository($this->connection());
    }

    private function firstPassengerId(int $bookingId): int
    {
        return new BookingPassengerRepository($this->connection())->forBooking($bookingId)[0]['id'];
    }

    private function insert(string $reference): int
    {
        $bookings = new BookingRepository($this->connection());

        $id = $bookings->create([
            'session_id' => self::SESSION,
            'departure_time' => '2030-01-01 08:00:00',
            'flight_outbound' => '[]',
            'flight_return' => null,
            'created' => '2020-06-01 12:00:00',
            'reference' => $reference,
            'status' => 'confirmed',
            'contact_email' => 'nobody@example.test',
            'contact_phone' => '+10000000000',
            'passenger_first' => 'Zzlead',
            'passenger_last' => 'Zzsurname',
            'passenger_dob' => '1990-01-01',
            'passenger_gender' => 'F',
            'price_base' => 100.00,
            'price_tax' => 20.00,
            'currency' => 'CAD',
            'currency_rate' => 1.0,
            'fare_brand' => 'basic',
            'fare_rules' => '[]',
            'card_brand' => 'Visa',
            'card_last4' => '0000',
        ]);

        new BookingPassengerRepository($this->connection())->createFor($id, [[
            'type' => 'A',
            'first_name' => 'Zzlead',
            'last_name' => 'Zzsurname',
            'dob' => '1990-01-01',
            'gender' => 'F',
        ]]);

        $this->bookings[] = $id;

        return $id;
    }
}
