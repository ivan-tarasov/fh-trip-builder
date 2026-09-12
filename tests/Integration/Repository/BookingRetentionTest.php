<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\BookingPassengerRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The retention policy, applied to real rows.
 *
 * `bookings` and `booking_passengers` hold an email, a phone number, names and
 * dates of birth, and until E9 (#146) nothing ever deleted one. There is no
 * foreign key between the two tables, so the order of the two deletes is the
 * thing most likely to be wrong here -- and the way it goes wrong is that the
 * passengers survive their booking, which keeps the personal information and
 * loses the row that explained why it was there.
 */
final class BookingRetentionTest extends IntegrationTestCase
{
    private const string SESSION = 'zz-retention-test';

    /**
     * Absurd dates, on purpose.
     *
     * `forgetDepartedBefore()` is global by design -- it deletes every booking
     * that departed before the cutoff, which is the whole job. So a test that
     * picks a plausible cutoff deletes the developer's real rows along with its
     * own, and the first version of this file did exactly that: a cutoff of
     * 2026-09-12 took eight bookings nobody could get back, because nothing in
     * this application creates one except a person going through checkout.
     *
     * A 1991 cutoff cannot reach anything a human made. Keep it that way.
     */
    private const string LONG_GONE = '1990-03-01 06:00:00';
    private const string CUTOFF = '1991-01-01 00:00:00';
    private const string STILL_TO_COME = '2027-01-01 06:00:00';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM booking_passengers WHERE booking_id IN'
            . ' (SELECT id FROM bookings WHERE session_id LIKE ?)',
            [self::SESSION . '%'],
        );
        $this->connection()->execute('DELETE FROM bookings WHERE session_id LIKE ?', [self::SESSION . '%']);
    }

    /**
     * @return array{0: BookingRepository, 1: int}
     */
    private function bookingDeparting(string $departure): array
    {
        $bookings = new BookingRepository($this->connection());

        $id = $bookings->create([
            'session_id' => self::SESSION . '-' . uniqid(),
            'reference' => strtoupper(substr(str_replace('.', '', uniqid('', true)), -6)),
            'status' => 'confirmed',
            'departure_time' => $departure,
            'flight_outbound' => '{"x":1}',
            'flight_return' => null,
            'contact_email' => 'traveller@example.com',
            'contact_phone' => '+1 514 555 0142',
            'passenger_first' => 'Imogene',
            'passenger_last' => 'Mertz',
            'passenger_dob' => '1988-04-17',
            'passenger_gender' => 'F',
            'fare_brand' => 'Basic',
            'price_base' => 900.00,
            'price_tax' => 100.00,
            'card_brand' => 'Visa',
            'card_last4' => '4567',
        ]);

        new BookingPassengerRepository($this->connection())->createFor($id, [[
            'type' => 'A',
            'first_name' => 'Imogene',
            'last_name' => 'Mertz',
            'dob' => '1988-04-17',
            'gender' => 'F',
        ]]);

        return [$bookings, $id];
    }

    private function passengerCount(int $bookingId): int
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM booking_passengers WHERE booking_id = ?',
            [$bookingId],
        );
    }

    public function testTheWindowIsNinetyDays(): void
    {
        self::assertSame(
            90,
            BookingRepository::KEEP_DAYS_AFTER_DEPARTURE,
            'The retention window is a decision and the README states it. Change both together.',
        );
    }

    public function testAFlightThatHasNotLeftYetIsNotListed(): void
    {
        [$bookings, $id] = $this->bookingDeparting(self::STILL_TO_COME);

        $listed = array_column($bookings->departedBefore(self::CUTOFF), 'id');

        self::assertNotContains($id, $listed);
    }

    public function testABookingPastTheWindowIsListed(): void
    {
        [$bookings, $id] = $this->bookingDeparting(self::LONG_GONE);

        $listed = array_column($bookings->departedBefore(self::CUTOFF), 'id');

        self::assertContains($id, $listed);
    }

    /**
     * The passengers go with the booking, and in that order.
     */
    public function testForgettingRemovesTheTravellersToo(): void
    {
        [$bookings, $id] = $this->bookingDeparting(self::LONG_GONE);

        self::assertSame(1, $this->passengerCount($id));

        $removed = $bookings->forgetDepartedBefore(self::CUTOFF);

        self::assertGreaterThanOrEqual(1, $removed['bookings']);
        self::assertGreaterThanOrEqual(1, $removed['passengers']);
        self::assertSame(0, $this->passengerCount($id));
        self::assertSame([], $bookings->forSession(self::SESSION));
    }

    /**
     * A trip still inside the window keeps everything, including its people.
     */
    public function testABookingInsideTheWindowSurvivesIntact(): void
    {
        [$bookings, $kept] = $this->bookingDeparting(self::STILL_TO_COME);
        [, $gone] = $this->bookingDeparting(self::LONG_GONE);

        $bookings->forgetDepartedBefore(self::CUTOFF);

        self::assertSame(1, $this->passengerCount($kept));
        self::assertSame(0, $this->passengerCount($gone));
    }
}
