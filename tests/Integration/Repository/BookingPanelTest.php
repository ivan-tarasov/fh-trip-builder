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
 * The first reads of this table that are not scoped to one session.
 *
 * Every other read answers "what has this browser bought", because that is all
 * the public site ever needs to know. An operator looking a booking up is a
 * different question and a different responsibility, and the two are kept as
 * separate methods rather than one with an optional session -- an optional
 * session is one forgotten argument away from serving somebody else's booking
 * to a stranger (A3.8, #233).
 *
 * The rows here carry a made-up session and a reference no allocator will
 * issue, and they are removed afterwards: this table holds real personal data
 * on any install that has taken a booking.
 */
final class BookingPanelTest extends IntegrationTestCase
{
    private const string SESSION = 'zzb-not-a-real-session';

    /** @var list<int> */
    private array $bookings = [];

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->bookings as $id) {
            $this->connection()->execute('DELETE FROM bookings WHERE id = ?', [$id]);
        }
    }

    /**
     * The panel sees a booking the browser that made it cannot claim.
     *
     * The whole point of the unscoped read, stated: `findForSession()` refuses
     * it and `find()` returns it.
     */
    public function testTheOperatorSeesWhatTheSessionCannot(): void
    {
        $id = $this->insert('ZZB001');

        self::assertNull(
            $this->bookings()->findForSession($id, 'somebody-else'),
            'another session should never see this',
        );

        $found = $this->bookings()->find($id);

        self::assertNotNull($found, 'the panel could not open a booking');
        self::assertSame('ZZB001', trim((string) $found['reference']));
    }

    public function testABookingThatIsNotThereReadsAsNothing(): void
    {
        self::assertNull($this->bookings()->find(0));
    }

    /**
     * `missing_ticket` matches a booking with at least one passenger and no
     * ticket for them -- the same "needs a ticket" reading
     * `BookingTicketRepository::bookingsNeedingTickets()` already gives,
     * restated as a filter on the list itself (G18, #376).
     */
    public function testMissingTicketFindsOnlyTheBookingWithoutOne(): void
    {
        $connection = $this->connection();
        $passengers = new BookingPassengerRepository($connection);
        $tickets = new BookingTicketRepository($connection);

        $noTicket = $this->insert('ZZB007');
        $hasTicket = $this->insert('ZZB008');

        $passengers->createFor($noTicket, [
            ['type' => 'A', 'first_name' => 'Zz', 'last_name' => 'Notickets', 'dob' => '1990-01-01', 'gender' => 'M'],
        ]);
        $passengers->createFor($hasTicket, [
            ['type' => 'A', 'first_name' => 'Zz', 'last_name' => 'Hastickets', 'dob' => '1990-01-01', 'gender' => 'M'],
        ]);

        $ticketedPassengerId = (int) $passengers->forBooking($hasTicket)[0]['id'];
        $tickets->create($ticketedPassengerId, DocumentType::Ticket, 'ZZ-TICKET-1', TicketStatus::Issued, '2020-06-01');

        try {
            $ids = array_map(
                static fn(array $row): int => (int) $row['id'],
                $this->bookings()->filtered('', null, null, null, true, 50),
            );

            self::assertContains($noTicket, $ids, 'the ticketless booking should have matched');
            self::assertNotContains($hasTicket, $ids, 'the ticketed booking should not have matched');
        } finally {
            $connection->execute('DELETE FROM booking_tickets WHERE booking_passenger_id = ?', [$ticketedPassengerId]);
            $connection->execute('DELETE FROM booking_passengers WHERE booking_id IN (?, ?)', [$noTicket, $hasTicket]);
        }
    }

    /**
     * Newest first, by when it was made.
     *
     * The traveller's own list is ordered by departure, because that is the
     * order they will fly them. The panel is asked about them in the order they
     * arrived.
     */
    public function testTheListIsNewestFirst(): void
    {
        $older = $this->insert('ZZB002', '2020-01-01 09:00:00');
        $newer = $this->insert('ZZB003', '2020-01-02 09:00:00');

        $ids = array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->bookings()->filtered('', null, null, null, false, 200),
        );

        $at = static fn(int $id): int|false => array_search($id, $ids, true);

        self::assertNotFalse($at($older));
        self::assertNotFalse($at($newer));
        self::assertLessThan($at($older), $at($newer), 'the older booking came first');
    }

    /**
     * The window is honoured, which is what lets the panel page.
     */
    public function testTheListIsWindowed(): void
    {
        $this->insert('ZZB004', '2020-01-03 09:00:00');
        $this->insert('ZZB005', '2020-01-04 09:00:00');

        $first = $this->bookings()->filtered('', null, null, null, false, 1);
        $second = $this->bookings()->filtered('', null, null, null, false, 1, 1);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]['id'], $second[0]['id'], 'the second page repeated the first');
    }

    public function testTheCountMatchesTheTable(): void
    {
        $before = $this->bookings()->countFiltered('', null, null, null, false);
        $this->insert('ZZB006');

        self::assertSame($before + 1, $this->bookings()->countFiltered('', null, null, null, false));

        /** @var int $count */
        $count = $this->connection()->fetchValue('SELECT COUNT(*) FROM bookings');

        self::assertSame($count, $this->bookings()->countFiltered('', null, null, null, false));
    }

    private function insert(string $reference, string $created = '2020-06-01 12:00:00'): int
    {
        $id = $this->bookings()->create([
            'session_id' => self::SESSION,
            'departure_time' => '2030-01-01 08:00:00',
            'flight_outbound' => '[]',
            'flight_return' => null,
            'created' => $created,
            'reference' => $reference,
            'status' => 'confirmed',
            'contact_email' => 'nobody@example.test',
            'contact_phone' => '+10000000000',
            'passenger_first' => 'Test',
            'passenger_last' => 'Traveller',
            'passenger_dob' => '1990-01-01',
            'passenger_gender' => 'M',
            'price_base' => 100.00,
            'price_tax' => 20.00,
            'currency' => 'CAD',
            'currency_rate' => 1.0,
            // No defaults on these, and a booking without them is not a row
            // the table will take. Four digits and a brand is every digit this
            // site has ever held.
            'fare_brand' => 'basic',
            'fare_rules' => '[]',
            'card_brand' => 'Visa',
            'card_last4' => '0000',
        ]);

        $this->bookings[] = $id;

        return $id;
    }

    private function bookings(): BookingRepository
    {
        return new BookingRepository($this->connection());
    }
}
