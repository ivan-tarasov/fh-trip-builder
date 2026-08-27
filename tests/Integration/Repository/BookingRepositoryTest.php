<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\BookingRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class BookingRepositoryTest extends IntegrationTestCase
{
    public function testForSessionReturnsEmptyForUnknownSession(): void
    {
        $bookings = (new BookingRepository($this->connection()))
            ->forSession('no-such-session-' . uniqid());

        self::assertSame([], $bookings);
    }

    public function testCreateReturnsIdAndDeleteForSessionRemovesScoped(): void
    {
        $repo = new BookingRepository($this->connection());
        $session = 'test-' . uniqid();

        $id = $repo->create([
            'session_id' => $session,
            'departure_time' => '2026-09-15 06:00:00',
            'flight_outbound' => '{"x":1}',
            'flight_return' => null,
        ]);

        self::assertGreaterThan(0, $id);
        self::assertCount(1, $repo->forSession($session));

        // Wrong session must not delete.
        self::assertSame(0, $repo->deleteForSession($id, 'someone-else'));
        // Correct session deletes exactly one.
        self::assertSame(1, $repo->deleteForSession($id, $session));
        self::assertSame([], $repo->forSession($session));
    }

    public function testForSessionRoundTripsAnInsertedBooking(): void
    {
        $connection = $this->connection();
        $session = 'test-' . uniqid();

        $connection->execute(
            'INSERT INTO bookings (session_id, flight_outbound, flight_return, departure_time)'
            . ' VALUES (?, ?, ?, ?)',
            [$session, '{"x":1}', null, '2026-09-15 06:00:00'],
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
