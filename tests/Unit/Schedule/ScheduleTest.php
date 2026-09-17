<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Cron;
use TripBuilder\Schedule;

/**
 * When a scheduled command is due.
 *
 * Everything here is pure: a clock passed in, a set of records passed in. The
 * thing being protected is a decision made at 03:00 by a process nobody
 * watches, so it has to be checkable at any hour by a test that does not wait.
 *
 * `Schedule::fromRows()`'s own refusals are tested here, against a plain
 * array -- reading the real, DB-backed schedule and checking every scheduled
 * command actually exists is `ScheduledJobRepositoryTest`'s job, an
 * integration test, since both need a real `scheduled_jobs` table.
 */
final class ScheduleTest extends TestCase
{
    /**
     * @param list<array{command: string, at: string}> $tasks
     */
    private static function schedule(array $tasks): Schedule
    {
        return new Schedule(array_map(
            static fn(array $t): array => ['command' => $t['command'], 'cron' => Cron::parse($t['at'])],
            $tasks,
        ));
    }

    /**
     * @return array{command: string, at: string}
     */
    private static function daily(string $at = '0 3 * * *'): array
    {
        return ['command' => 'currency:rates', 'at' => $at];
    }

    public function testACommandThatHasNeverRunIsDue(): void
    {
        $due = self::schedule([self::daily()])->due(new DateTimeImmutable('2026-09-12 04:00:00'), []);

        self::assertCount(1, $due);
    }

    public function testACommandThatAlreadyRanTodayIsNotDue(): void
    {
        $due = self::schedule([self::daily()])->due(
            new DateTimeImmutable('2026-09-12 14:00:00'),
            ['currency:rates' => ['last_run_at' => '2026-09-12 03:00:04']],
        );

        self::assertSame([], $due);
    }

    /**
     * Before its time, yesterday's run still counts.
     */
    public function testACommandIsNotDueAgainBeforeItsTimeComesRoundAgain(): void
    {
        $due = self::schedule([self::daily()])->due(
            new DateTimeImmutable('2026-09-13 02:00:00'),
            ['currency:rates' => ['last_run_at' => '2026-09-12 03:00:04']],
        );

        self::assertSame([], $due);
    }

    /**
     * A tick missed catches up rather than skipping the day.
     *
     * Fifteen minutes is a window a deploy, a reboot or a slow task can land
     * on. Skipping would mean rates a day stale and a retention policy that
     * quietly did not apply, neither of which announces itself.
     */
    public function testATaskMissedForTwoDaysIsStillDue(): void
    {
        $due = self::schedule([self::daily()])->due(
            new DateTimeImmutable('2026-09-14 03:15:00'),
            ['currency:rates' => ['last_run_at' => '2026-09-12 03:00:04']],
        );

        self::assertCount(1, $due);
    }

    /**
     * A failed run still counts as a run.
     *
     * `due()` reads `last_run_at`, which is stamped whatever the exit code was,
     * so a command that fails at 03:00 is not retried at 03:15 and every
     * quarter hour after it. What says something is wrong is the untouched
     * `last_success_at`, which E16.2 (#169) reads.
     */
    public function testAFailedRunIsNotRetriedOnTheNextTick(): void
    {
        $due = self::schedule([self::daily()])->due(
            new DateTimeImmutable('2026-09-12 03:15:00'),
            ['currency:rates' => [
                'last_run_at' => '2026-09-12 03:00:04',
                'last_success_at' => '2026-09-01 03:00:02',
                'last_exit' => 1,
            ]],
        );

        self::assertSame([], $due);
    }

    public function testAnHourlyTaskIsDueOncePerHour(): void
    {
        $task = ['command' => 'alerts:check', 'at' => '20 * * * *'];

        self::assertCount(1, self::schedule([$task])->due(
            new DateTimeImmutable('2026-09-12 14:25:00'),
            ['alerts:check' => ['last_run_at' => '2026-09-12 13:20:01']],
        ));

        self::assertSame([], self::schedule([$task])->due(
            new DateTimeImmutable('2026-09-12 14:25:00'),
            ['alerts:check' => ['last_run_at' => '2026-09-12 14:20:01']],
        ));
    }

    public function testAnHourlyTaskBeforeItsMinuteMeasuresAgainstThePreviousHour(): void
    {
        $task = ['command' => 'alerts:check', 'at' => '20 * * * *'];

        self::assertSame([], self::schedule([$task])->due(
            new DateTimeImmutable('2026-09-12 14:05:00'),
            ['alerts:check' => ['last_run_at' => '2026-09-12 13:20:01']],
        ));
    }

    public function testAnExpressionThisDoesNotUnderstandIsRefusedOnLoad(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not something this understands/');

        Schedule::fromRows([self::fields('currency:rates', weekday: 'MON')]);
    }

    /**
     * A field left out is refused rather than assumed to be `*`.
     *
     * The assumption is the dangerous one: forgetting the day field would turn
     * a monthly task into a daily one, and nothing about the line would look
     * wrong.
     */
    public function testAMissingFieldIsRefusedRatherThanDefaulted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is missing day/');

        $fields = self::fields('currency:rates');
        unset($fields[Cron::DAY]);

        Schedule::fromRows([$fields]);
    }

    /**
     * @return array<string, string|int>
     */
    private static function fields(
        string $command,
        string|int $minute = 0,
        string|int $hour = 3,
        string|int $day = Cron::EVERY,
        string|int $month = Cron::EVERY,
        string|int $weekday = Cron::EVERY,
    ): array {
        return [
            Cron::MINUTE => $minute,
            Cron::HOUR => $hour,
            Cron::DAY => $day,
            Cron::MONTH => $month,
            Cron::WEEKDAY => $weekday,
            Schedule::COMMAND => $command,
        ];
    }

    /**
     * A command that has never succeeded is stale, whatever its attempts say.
     */
    public function testACommandThatHasNeverWorkedIsStale(): void
    {
        $health = self::schedule([self::daily()])->health(
            new DateTimeImmutable('2026-09-12 14:00:00'),
            ['currency:rates' => ['last_run_at' => '2026-09-12 03:00:00', 'last_success_at' => null]],
        );

        self::assertSame('never', $health['currency:rates']['age']);
        self::assertTrue($health['currency:rates']['stale']);
    }

    /**
     * One missed night is a bad night. Two is something nobody is watching.
     */
    public function testOneMissedRunIsNotYetStale(): void
    {
        $schedule = self::schedule([self::daily()]);
        $now = new DateTimeImmutable('2026-09-12 14:00:00');

        self::assertFalse($schedule->health($now, [
            'currency:rates' => ['last_success_at' => '2026-09-11 03:00:02'],
        ])['currency:rates']['stale'], 'one missed night was called stale');

        self::assertTrue($schedule->health($now, [
            'currency:rates' => ['last_success_at' => '2026-09-10 03:00:02'],
        ])['currency:rates']['stale'], 'two missed nights were not called stale');
    }

    /**
     * Staleness reads the success and never the attempt.
     *
     * A command failing every night at 03:00 has a fresh `last_run_at` and a
     * rotting `last_success_at`. Reading the first would call that healthy,
     * which is the failure the two columns exist to separate.
     */
    public function testABusyFailingCommandIsStillStale(): void
    {
        $health = self::schedule([self::daily()])->health(
            new DateTimeImmutable('2026-09-12 14:00:00'),
            ['currency:rates' => [
                'last_run_at' => '2026-09-12 03:00:00',
                'last_success_at' => '2026-09-01 03:00:02',
                'last_exit' => 1,
            ]],
        );

        self::assertTrue($health['currency:rates']['stale']);
        self::assertSame('11d', $health['currency:rates']['age']);
    }

    public function testAgeIsReportedInTheCoarsestUsefulUnit(): void
    {
        $schedule = self::schedule([self::daily()]);
        $now = new DateTimeImmutable('2026-09-12 14:00:00');

        $age = static fn(string $at): string => $schedule->health(
            $now,
            ['currency:rates' => ['last_success_at' => $at]],
        )['currency:rates']['age'];

        self::assertSame('30m', $age('2026-09-12 13:30:00'));
        self::assertSame('4h', $age('2026-09-12 10:00:00'));
        self::assertSame('2d', $age('2026-09-10 14:00:00'));
    }

    public function testIsStaleAnswersForTheWholeSchedule(): void
    {
        $schedule = self::schedule([self::daily()]);
        $now = new DateTimeImmutable('2026-09-12 14:00:00');

        self::assertFalse($schedule->isStale($now, [
            'currency:rates' => ['last_success_at' => '2026-09-12 03:00:02'],
        ]));
        self::assertTrue($schedule->isStale($now, []));
    }

    /**
     * A stepped expression fires on the boundary, and only once per boundary.
     */
    public function testAStepIsDueOnceEachBoundary(): void
    {
        $schedule = self::schedule([['command' => 'alerts:check', 'at' => '*/15 * * * *']]);
        $now = new DateTimeImmutable('2026-09-12 14:31:00');

        self::assertCount(1, $schedule->due($now, [
            'alerts:check' => ['last_run_at' => '2026-09-12 14:15:03'],
        ]), 'the 14:30 boundary was missed');

        self::assertSame([], $schedule->due($now, [
            'alerts:check' => ['last_run_at' => '2026-09-12 14:30:02'],
        ]), 'it ran twice inside one boundary');
    }

    /**
     * A slow run does not push the next one later.
     *
     * The occurrence is a property of the expression, not of when the last run
     * happened to finish, so a task that overruns does not walk away from the
     * clock it is written against.
     */
    public function testASlowRunDoesNotDriftTheSchedule(): void
    {
        self::assertCount(1, self::schedule([['command' => 'alerts:check', 'at' => '*/15 * * * *']])->due(
            new DateTimeImmutable('2026-09-12 14:30:05'),
            ['alerts:check' => ['last_run_at' => '2026-09-12 14:15:59']],
        ));
    }
}
