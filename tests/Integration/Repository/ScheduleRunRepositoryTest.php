<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The two timestamps, and why there are two.
 *
 * `last_run_at` says a task was started and is what stops it being started
 * again. `last_success_at` says it worked. A command failing every night has a
 * fresh first and a rotting second, and only the second tells the truth -- one
 * column would report that as healthy, which is the failure E16.2 (#169)
 * exists to catch.
 */
final class ScheduleRunRepositoryTest extends IntegrationTestCase
{
    private const string COMMAND = 'zz:probe';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM schedule_runs WHERE command LIKE ?', ['zz:%']);
    }

    private function runs(): ScheduleRunRepository
    {
        return new ScheduleRunRepository($this->connection());
    }

    public function testAFirstRunIsRecorded(): void
    {
        $runs = $this->runs();
        $runs->started(self::COMMAND, '2026-09-12 03:00:00');
        $runs->finished(self::COMMAND, 0, '2026-09-12 03:00:09');

        $record = $runs->all()[self::COMMAND] ?? null;

        self::assertNotNull($record);
        self::assertSame('2026-09-12 03:00:00', $record['last_run_at']);
        self::assertSame('2026-09-12 03:00:09', $record['last_success_at']);
        self::assertSame(0, $record['last_exit']);
    }

    /**
     * A failure moves the attempt and leaves the success where it was.
     */
    public function testAFailedRunDoesNotMoveTheSuccessTimestamp(): void
    {
        $runs = $this->runs();
        $runs->started(self::COMMAND, '2026-09-12 03:00:00');
        $runs->finished(self::COMMAND, 0, '2026-09-12 03:00:09');

        $runs->started(self::COMMAND, '2026-09-13 03:00:00');
        $runs->finished(self::COMMAND, 1, '2026-09-13 03:00:02');

        $record = $runs->all()[self::COMMAND];

        self::assertSame('2026-09-13 03:00:00', $record['last_run_at'], 'the attempt moved');
        self::assertSame('2026-09-12 03:00:09', $record['last_success_at'], 'the success did not');
        self::assertSame(1, $record['last_exit']);
    }

    /**
     * Starting a command that has never worked leaves the success unset rather
     * than pretending to a time.
     */
    public function testACommandThatHasNeverSucceededHasNoSuccessTime(): void
    {
        $runs = $this->runs();
        $runs->started(self::COMMAND, '2026-09-12 03:00:00');
        $runs->finished(self::COMMAND, 2, '2026-09-12 03:00:01');

        self::assertNull($runs->all()[self::COMMAND]['last_success_at']);
    }

    /**
     * A second start does not create a second row.
     */
    public function testStartingIsAnUpsert(): void
    {
        $runs = $this->runs();
        $runs->started(self::COMMAND, '2026-09-12 03:00:00');
        $runs->started(self::COMMAND, '2026-09-13 03:00:00');

        self::assertSame(
            1,
            (int) $this->connection()->fetchValue(
                'SELECT COUNT(*) FROM schedule_runs WHERE command = ?',
                [self::COMMAND],
            ),
        );
        self::assertSame('2026-09-13 03:00:00', $runs->all()[self::COMMAND]['last_run_at']);
    }
}
