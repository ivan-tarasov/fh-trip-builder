<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\BookingActor;
use TripBuilder\BookingEvent;
use TripBuilder\Repository\BookingEventRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * What happened to a booking, in the order it happened.
 *
 * The rows here carry a made-up session and references no allocator will issue,
 * and they are removed afterwards: this table holds real personal data on any
 * install that has taken a booking (A3.8, #233).
 */
final class BookingLogTest extends IntegrationTestCase
{
    private const string SESSION = 'zzl-not-a-real-session';

    /** @var list<int> */
    private array $bookings = [];

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->bookings as $id) {
            $this->connection()->execute('DELETE FROM booking_events WHERE booking_id = ?', [$id]);
            $this->connection()->execute('DELETE FROM booking_passengers WHERE booking_id = ?', [$id]);
            $this->connection()->execute('DELETE FROM bookings WHERE id = ?', [$id]);
        }
    }

    /**
     * What happened, in the order it happened.
     *
     * Two events in the same second are ordered by the order they were written,
     * which is why the query sorts on the id as well as the clock.
     */
    public function testTheLogReadsInTheOrderThingsHappened(): void
    {
        $id = $this->insert('ZZL001');
        $log = $this->log();

        $log->record($id, BookingEvent::Booked, BookingActor::Visitor, '2 traveller(s)');
        $log->record($id, BookingEvent::Cancelled, BookingActor::Visitor);
        $log->record($id, BookingEvent::Reinstated, BookingActor::Operator, 'from the panel');

        $lines = $log->forBooking($id);

        self::assertCount(3, $lines);
        self::assertSame(
            [BookingEvent::Booked, BookingEvent::Cancelled, BookingEvent::Reinstated],
            array_column($lines, 'event'),
        );
        self::assertSame('2 traveller(s)', $lines[0]['note']);
        self::assertSame(BookingActor::Operator, $lines[2]['actor']);
    }

    /**
     * A word this version does not know is printed, not hidden.
     *
     * `tryFrom()` gives null and `raw` carries what was stored, so a row written
     * by a later version shows up in the log rather than disappearing from it.
     */
    public function testAnEventThisVersionDoesNotKnowStillReads(): void
    {
        $id = $this->insert('ZZL002');

        $this->connection()->execute(
            'INSERT INTO booking_events (booking_id, event, actor, note, at) VALUES (?, ?, ?, ?, NOW())',
            [$id, 'refunded', 'robot', ''],
        );

        $lines = $this->log()->forBooking($id);

        self::assertCount(1, $lines);
        self::assertNull($lines[0]['event'], 'an unknown word should not resolve to a case');
        self::assertNull($lines[0]['actor']);
        self::assertSame('refunded', $lines[0]['raw']);
    }

    /** The log begins the day it is installed, and says when that was. */
    public function testTheLogKnowsWhenItStarted(): void
    {
        $id = $this->insert('ZZL004');
        $this->log()->record($id, BookingEvent::Booked, BookingActor::Visitor);

        self::assertNotNull($this->log()->startedAt());
    }

    private function log(): BookingEventRepository
    {
        return new BookingEventRepository($this->connection());
    }

    private function bookings(): BookingRepository
    {
        return new BookingRepository($this->connection());
    }

    private function insert(string $reference): int
    {
        $id = $this->bookings()->create([
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
            // No defaults on these, and a booking without them is not a row the
            // table will take.
            'fare_brand' => 'basic',
            'fare_rules' => '[]',
            'card_brand' => 'Visa',
            'card_last4' => '0000',
        ]);

        $this->bookings[] = $id;

        return $id;
    }
}
