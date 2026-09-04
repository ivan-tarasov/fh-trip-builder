<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

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
            self::assertSame(
                BookingStatus::Confirmed->value,
                $repo->findForSession($id, $session)['status'],
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
}
