<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TripBuilder\Frequency;
use TripBuilder\Helper;
use TripBuilder\Schedule;

/**
 * When a scheduled command is due.
 *
 * Everything here is pure: a clock passed in, a set of records passed in. The
 * thing being protected is a decision made at 03:00 by a process nobody
 * watches, so it has to be checkable at any hour by a test that does not wait.
 */
final class ScheduleTest extends TestCase
{
    private const string CONFIG = '/config/noah/schedule.php';

    /**
     * @param list<array{command: string, every: Frequency, at: string}> $tasks
     */
    private static function schedule(array $tasks): Schedule
    {
        return new Schedule($tasks);
    }

    /**
     * @return array{command: string, every: Frequency, at: string}
     */
    private static function daily(string $at = '03:00'): array
    {
        return ['command' => 'currency:rates', 'every' => Frequency::Daily, 'at' => $at];
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
        $task = ['command' => 'alerts:check', 'every' => Frequency::Hourly, 'at' => ':20'];

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
        $task = ['command' => 'alerts:check', 'every' => Frequency::Hourly, 'at' => ':20'];

        self::assertSame([], self::schedule([$task])->due(
            new DateTimeImmutable('2026-09-12 14:05:00'),
            ['alerts:check' => ['last_run_at' => '2026-09-12 13:20:01']],
        ));
    }

    public function testATimeThatIsNotTheRightShapeIsRefusedOnLoad(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not the shape/');

        Schedule::fromConfig(self::fixture([
            ['command' => 'currency:rates', 'every' => Frequency::Hourly, 'at' => '03:00'],
        ]));
    }

    public function testATaskMissingItsFrequencyIsRefusedOnLoad(): void
    {
        $this->expectException(RuntimeException::class);

        Schedule::fromConfig(self::fixture([['command' => 'currency:rates', 'at' => '03:00']]));
    }

    /**
     * Every scheduled command is a command that exists.
     *
     * This is the failure this whole design invites: a typo in the config is
     * not a syntax error, not a test failure and not a deploy failure -- it is
     * a command that silently never runs, discovered weeks later by noticing
     * that rates are stale. Checked against the `AsCommand` names on disk.
     */
    public function testEveryScheduledCommandExists(): void
    {
        $registered = [];

        foreach (self::filesUnder(Helper::getRootDir() . '/src/Noah') as $file) {
            if (preg_match("/name: '([^']+)'/", (string) file_get_contents($file), $found) === 1) {
                $registered[] = $found[1];
            }
        }

        self::assertNotEmpty($registered, 'No commands were found to check against.');

        foreach (Schedule::fromConfig(Helper::getRootDir() . self::CONFIG)->tasks() as $task) {
            $name = explode(' ', $task['command'])[0];

            self::assertContains($name, $registered, sprintf(
                '`%s` is scheduled and does not exist. It would fail every night, quietly.',
                $name,
            ));
        }
    }

    public function testTheRealScheduleLoads(): void
    {
        $tasks = Schedule::fromConfig(Helper::getRootDir() . self::CONFIG)->tasks();

        self::assertNotEmpty($tasks);
    }

    /** @return list<string> */
    private static function filesUnder(string $directory): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
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
     * @param list<array<string, mixed>> $tasks
     */
    private static function fixture(array $tasks): string
    {
        $path = sys_get_temp_dir() . '/schedule-' . uniqid() . '.php';
        $body = "<?php\n\nuse TripBuilder\\Frequency;\n\nreturn [\n";

        foreach ($tasks as $task) {
            $body .= "    [";
            foreach ($task as $key => $value) {
                $body .= sprintf(
                    "'%s' => %s, ",
                    $key,
                    $value instanceof Frequency ? 'Frequency::' . $value->name : var_export($value, true),
                );
            }
            $body .= "],\n";
        }

        file_put_contents($path, $body . "];\n");

        return $path;
    }
}
