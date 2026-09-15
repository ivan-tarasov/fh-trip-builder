<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * What the sweep removes, and what it must not.
 *
 * **Every flight here departs in 1990, and the cutoff is 1991.** The method
 * deletes everything before the moment it is given, so a plausible cutoff would
 * take the whole table with it -- which is how a retention test once deleted
 * eight real bookings out of a development database (E18, #176). A year nothing
 * real is in is the only safe way to ask this question.
 *
 * The case that matters is the second one. `departure_time` is a wall-clock
 * reading at the departure airport and the cutoff is an instant, so a flight
 * leaving Honolulu at -10.00 late on the 12th has a local time before a UTC
 * cutoff of the 13th and has not left yet. The old sweep deleted it, up to nine
 * hours and twenty-three minutes early (E24.2, #192).
 */
final class FlightSweepTest extends IntegrationTestCase
{
    /** Nothing real is in 1990, and nothing real is compared against 1991. */
    private const string CUTOFF = '1991-01-01 00:00:00';

    private const string AIRLINE = 'ZZ';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM flights WHERE airline = ?', [self::AIRLINE]);
    }

    public function testAFlightThatHasDepartedIsRemoved(): void
    {
        $id = $this->flight('1990-06-01 08:00:00', '1990-06-01 08:00:00');

        self::assertSame(1, $this->sweep(), 'the flight left in 1990 and should be gone');
        self::assertFalse($this->exists($id));
    }

    /**
     * The bug, as a test.
     *
     * Local time before the cutoff, UTC instant after it: this flight has not
     * departed, and reading the wall clock says it has.
     */
    public function testAFlightWhoseLocalTimeLooksPastIsKept(): void
    {
        $id = $this->flight('1990-12-31 22:00:00', '1991-01-01 08:00:00');

        self::assertSame(0, $this->sweep(), 'nothing here has departed yet');
        self::assertTrue($this->exists($id), 'a flight ten hours from departure was deleted');
    }

    /** And the reverse: gone by the clock that counts, still to come by the other. */
    public function testAFlightWhoseLocalTimeLooksFutureIsRemoved(): void
    {
        $id = $this->flight('1991-01-01 09:00:00', '1990-12-31 22:00:00');

        self::assertSame(1, $this->sweep(), 'it left before the cutoff, whatever the wall clock says');
        self::assertFalse($this->exists($id));
    }

    /**
     * Batching is an implementation detail of how the locks are held, not of
     * what ends up deleted.
     */
    public function testEveryDepartedFlightGoesHoweverSmallTheBatches(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->flight('1990-06-01 08:00:00', sprintf('1990-06-0%d 08:00:00', $i + 1));
        }

        self::assertSame(5, $this->sweep(batchSize: 2));
    }

    private function sweep(int $batchSize = 5000): int
    {
        return new FlightRepository($this->connection())
            ->forgetDepartedBefore(self::CUTOFF, $batchSize);
    }

    private function exists(int $id): bool
    {
        return $this->connection()->fetchOne('SELECT id FROM flights WHERE id = ?', [$id]) !== null;
    }

    /** One flight, with its two clocks set independently so they can disagree. */
    private function flight(string $local, string $utc): int
    {
        $this->connection()->execute(
            'INSERT INTO flights (airline, number, departure_airport, arrival_airport,'
            . ' departure_time, arrival_time, departure_utc, aircraft, distance,'
            . ' duration, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::AIRLINE, '9001', 'HNL', 'YUL', $local, $local, $utc, '320', 100, 60, 100.0, 10.0, 1],
        );

        /** @var int $id */
        $id = $this->connection()->fetchValue('SELECT LAST_INSERT_ID()');

        return $id;
    }
}
