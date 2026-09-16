<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\BookingActor;
use TripBuilder\RemarkTone;
use TripBuilder\Repository\BookingRemarkRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Remarks, against a real table.
 *
 * The rows here carry a made-up session and references no allocator will
 * issue, and they are removed afterwards: this table holds real notes about
 * real people on any install that has taken a booking (G8.2, #337).
 */
final class BookingRemarkRepositoryTest extends IntegrationTestCase
{
    private const string SESSION = 'zzr-not-a-real-session';

    /** @var list<int> */
    private array $bookings = [];

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->bookings as $id) {
            $this->connection()->execute('DELETE FROM booking_remarks WHERE booking_id = ?', [$id]);
            $this->connection()->execute('DELETE FROM bookings WHERE id = ?', [$id]);
        }
    }

    /**
     * Newest first -- unlike the event log's timeline, this is a running note
     * an operator wants the latest of without scrolling past the rest.
     */
    public function testRemarksReadNewestFirst(): void
    {
        $id = $this->insert('ZZR001');
        $remarks = $this->remarks();

        $remarks->record($id, BookingActor::Operator, RemarkTone::Info, 'Called to confirm seats.');
        $remarks->record($id, BookingActor::Operator, RemarkTone::Important, 'Card on file is expiring soon.');

        $lines = $remarks->forBooking($id);

        self::assertCount(2, $lines);
        self::assertSame('Card on file is expiring soon.', $lines[0]['body']);
        self::assertSame(RemarkTone::Important, $lines[0]['tone']);
        self::assertSame('Called to confirm seats.', $lines[1]['body']);
        self::assertSame(BookingActor::Operator, $lines[1]['actor']);
    }

    /**
     * A tone this version does not know is printed, not hidden -- same
     * reasoning as an unrecognised `BookingEvent` in the log.
     */
    public function testAToneThisVersionDoesNotKnowStillReads(): void
    {
        $id = $this->insert('ZZR002');

        $this->connection()->execute(
            'INSERT INTO booking_remarks (booking_id, actor, tone, body, created) VALUES (?, ?, ?, ?, NOW())',
            [$id, 'operator', 'urgent', 'Escalated to a supervisor.'],
        );

        $lines = $this->remarks()->forBooking($id);

        self::assertCount(1, $lines);
        self::assertNull($lines[0]['tone'], 'an unknown word should not resolve to a case');
        self::assertSame('urgent', $lines[0]['raw_tone']);
        self::assertSame('Escalated to a supervisor.', $lines[0]['body']);
    }

    public function testABookingWithNoRemarksReadsEmpty(): void
    {
        $id = $this->insert('ZZR003');

        self::assertSame([], $this->remarks()->forBooking($id));
    }

    private function remarks(): BookingRemarkRepository
    {
        return new BookingRemarkRepository($this->connection());
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
