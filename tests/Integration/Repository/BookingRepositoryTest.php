<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use DateTimeImmutable;
use TripBuilder\BookingStatus;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class BookingRepositoryTest extends IntegrationTestCase
{
    /**
     * A complete booking.
     *
     * Checkout made most of these columns NOT NULL with no default, which is
     * right — a row with no reference, no contact and no passenger is not a
     * booking anyone could travel on. That means a partial insert is now a
     * database error rather than a half-filled row, so the fixture states the
     * whole thing and one place has to change when a column is added.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function booking(string $session, array $overrides = []): array
    {
        return $overrides + [
            'session_id' => $session,
            'reference' => strtoupper(substr(str_replace('.', '', uniqid('', true)), -6)),
            'status' => 'confirmed',
            'departure_time' => '2026-09-15 06:00:00',
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
        ];
    }

    public function testForSessionReturnsEmptyForUnknownSession(): void
    {
        $bookings = (new BookingRepository($this->connection()))
            ->forSession('no-such-session-' . uniqid());

        self::assertSame([], $bookings);
    }

    public function testCreateReturnsIdAndCancelForSessionIsScoped(): void
    {
        $repo = new BookingRepository($this->connection());
        $session = 'test-' . uniqid();

        $id = $repo->create(self::booking($session));

        try {
            self::assertGreaterThan(0, $id);
            self::assertCount(1, $repo->forSession($session));

            // Wrong session must not cancel.
            self::assertSame(0, $repo->cancelForSession($id, 'someone-else'));
            $found = $repo->findForSession($id, $session);

            self::assertNotNull($found, 'the booking this session just made should be findable');
            self::assertSame(
                BookingStatus::Confirmed->value,
                $found['status'],
            );

            self::assertSame(1, $repo->cancelForSession($id, $session));

            // The row survives -- that is the whole point of cancelling rather
            // than deleting -- and a second cancel changes nothing.
            $cancelled = $repo->findForSession($id, $session);
            self::assertNotNull($cancelled);
            self::assertSame(BookingStatus::Cancelled->value, $cancelled['status']);
            self::assertCount(1, $repo->forSession($session));
            self::assertSame(0, $repo->cancelForSession($id, $session));
        } finally {
            $this->connection()->execute('DELETE FROM bookings WHERE session_id = ?', [$session]);
        }
    }

    public function testFindForSessionIsScopedToTheSessionThatBooked(): void
    {
        $repo = new BookingRepository($this->connection());
        $session = 'test-' . uniqid();

        $id = $repo->create(self::booking($session));

        try {
            self::assertNotNull($repo->findForSession($id, $session));
            // An id guessed from another browser resolves to nothing.
            self::assertNull($repo->findForSession($id, 'someone-else'));
        } finally {
            $this->connection()->execute('DELETE FROM bookings WHERE session_id = ?', [$session]);
        }
    }

    public function testForSessionRoundTripsAnInsertedBooking(): void
    {
        $connection = $this->connection();
        $session = 'test-' . uniqid();

        $booking = self::booking($session);
        $columns = array_keys($booking);

        $connection->execute(
            'INSERT INTO bookings (' . implode(', ', array_map(static fn(string $c): string => "`$c`", $columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($booking),
        );

        try {
            $bookings = (new BookingRepository($connection))->forSession($session);

            self::assertCount(1, $bookings);
            self::assertSame($session, $bookings[0]['session_id']);
            self::assertArrayHasKey('flight_outbound', $bookings[0]);
        } finally {
            $connection->execute('DELETE FROM bookings WHERE session_id = ?', [$session]);
        }
    }

    /**
     * A year with nothing else in it, so the window's own count is the
     * whole answer rather than one this session's fixture shares with
     * whatever real data the dev database happens to hold around
     * `created`'s actual default of "now" (G7.1, #332).
     */
    public function testMadeCancelledAndCostAllReadTheSameWindow(): void
    {
        $repo = new BookingRepository($this->connection());
        $session = 'test-' . uniqid();

        $repo->create(self::booking($session, [
            'created' => '2020-03-10 10:00:00', 'price_base' => 100.0, 'price_tax' => 20.0,
        ]));
        $repo->create(self::booking($session, [
            'created' => '2020-03-15 10:00:00', 'status' => 'cancelled',
            'price_base' => 50.0, 'price_tax' => 5.0,
        ]));
        // Outside the window on purpose -- proves the bound is exclusive at
        // `$to` and not just "everything before some date".
        $repo->create(self::booking($session, [
            'created' => '2020-04-01 00:00:00', 'price_base' => 999.0, 'price_tax' => 999.0,
        ]));

        try {
            $from = new DateTimeImmutable('2020-03-01');
            $to = new DateTimeImmutable('2020-04-01');

            self::assertSame(2, $repo->madeCount($from, $to));
            self::assertSame(1, $repo->cancelledCount($from, $to));
            self::assertSame(175.0, $repo->totalCost($from, $to), 'gross of both -- cancelled still cost something');
        } finally {
            $this->connection()->execute('DELETE FROM bookings WHERE session_id = ?', [$session]);
        }
    }

    public function testDailyMadeCountsFillsAContinuousRunOfDays(): void
    {
        $repo = new BookingRepository($this->connection());
        $session = 'test-' . uniqid();

        $repo->create(self::booking($session, ['created' => '2020-06-15 09:00:00']));

        try {
            $series = $repo->dailyMadeCounts(new DateTimeImmutable('2020-06-14'), new DateTimeImmutable('2020-06-17'));

            self::assertSame(['2020-06-14' => 0, '2020-06-15' => 1, '2020-06-16' => 0], $series);
        } finally {
            $this->connection()->execute('DELETE FROM bookings WHERE session_id = ?', [$session]);
        }
    }
}
