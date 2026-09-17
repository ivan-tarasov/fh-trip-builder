<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;
use RuntimeException;

/**
 * What runs without anybody asking, and whether it is due.
 *
 * The schedule is the `scheduled_jobs` table, edited from `/admin/schedule`
 * -- before G19 (#377) it was `config/noah/schedule.php`, a static file in
 * git, and changing it meant a pull request and a deploy. The server still
 * holds one cron line and no knowledge of what it is for (E16, #167); this
 * class still knows nothing about the database itself, built instead from
 * whatever rows `fromRows()` is handed, so it stays as easy to test as it
 * was when those rows came from a required file.
 *
 * Crontab and not a vocabulary of its own. It is the notation everybody
 * already reads, cPanel's editor is these five fields in this order, and a
 * second spelling for the same idea is a second thing to learn. It became
 * worth having when the outer cron moved to every minute: before that the tick
 * was a fifteen-minute floor, so a `*​/5` here could not have been true.
 */
final readonly class Schedule
{
    /**
     * What to run, as a key in the config.
     *
     * A constant like `Cron::MINUTE` and its siblings, and for the same reason:
     * a mistyped `Schedule::COMMNAD` is a fatal error on the line that wrote
     * it, while a mistyped `'commnad'` is a missing key reported from
     * somewhere else. Both are caught; only one names the line.
     *
     * On `Schedule` and not on `Cron`, because the other five describe *when*
     * and this one describes *what*.
     */
    public const string COMMAND = 'command';

    /**
     * How far back `due()` will look for a missed occurrence when a command has
     * never run.
     *
     * A day. Long enough that a task scheduled for 03:00 is picked up by a
     * server first started at noon, short enough that a monthly task is not
     * fired on sight the moment it is added -- which would be a surprise, and
     * the surprise would be a command running on a database nobody expected it
     * to touch yet.
     */
    private const int UNSEEN_LOOKBACK_MINUTES = 1440;

    /**
     * @param list<array{command: string, cron: Cron}> $tasks
     */
    public function __construct(private array $tasks) {}

    /**
     * Build from plain rows -- a required file's own array, or
     * `ScheduledJobRepository::allEnabled()`'s. Either way, each row needs
     * only the five `Cron::FIELDS` and a `command`.
     *
     * @param iterable<mixed> $rows
     */
    public static function fromRows(iterable $rows): self
    {
        $tasks = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[self::COMMAND])) {
                throw new RuntimeException('Every scheduled task needs a `' . self::COMMAND . '`.');
            }

            /** @var array<string, string|int> $row */
            $command = (string) $row[self::COMMAND];

            $tasks[] = [
                'command' => $command,
                // The five named fields, not a single string. cPanel's editor
                // is five labelled boxes in this order and so is this, which
                // means the two can be read against each other without anybody
                // counting positions.
                'cron' => Cron::fromFields($row, $command),
            ];
        }

        return new self($tasks);
    }

    /**
     * @return list<array{command: string, cron: Cron}>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * The tasks that should run now.
     *
     * Not "does this minute match", which is what a crontab line means and what
     * a missed tick would silently skip. This asks whether the expression named
     * any minute since the command last started -- so a tick lost to a deploy,
     * a reboot or a slow run is caught up on the next one rather than costing a
     * day.
     *
     * The search is bounded by that gap, which on an ordinary tick is one
     * minute. A command with no record at all gets a day, for the reason
     * `UNSEEN_LOOKBACK_MINUTES` gives.
     *
     * Measured against `last_run_at` and never `last_success_at`, because this
     * decides whether to *start* something: a command that failed at 03:00 must
     * not be started again at 03:01 and every minute after. It is due again at
     * its next occurrence, and in between its rotting `last_success_at` is what
     * says something is wrong (E16.2, #169).
     *
     * @param array<string, array{last_run_at: string, ...}> $records
     * @return list<array{command: string, cron: Cron}>
     */
    public function due(DateTimeImmutable $now, array $records): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            $last = $records[$task['command']]['last_run_at'] ?? null;

            $window = $last === null
                ? self::UNSEEN_LOOKBACK_MINUTES
                : self::minutesBetween(new DateTimeImmutable($last), $now);

            $occurrence = $task['cron']->previous($now, $window);

            if ($occurrence === null) {
                continue;
            }

            if ($last === null || new DateTimeImmutable($last) < $occurrence) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * How each command is doing, for the health endpoint and the warning.
     *
     * Reads `last_success_at` and never `last_run_at`. A command failing every
     * night has a fresh attempt and a rotting success, and the attempt is the
     * one that looks healthy -- which is the whole reason there are two columns
     * (E16.2, #169).
     *
     * One whole gap of grace before "stale": the interval between this
     * expression's last two occurrences. A daily task that missed last night is
     * a bad night; one that has missed two is something nobody is watching. The
     * gap is measured rather than declared, so it is right for `0 3 * * *` and
     * for `*​/15 * * * *` without either being told.
     *
     * @param array<string, array{last_success_at?: ?string, ...}> $records
     * @return array<string, array{age: string, stale: bool}>
     */
    public function health(DateTimeImmutable $now, array $records): array
    {
        $health = [];

        foreach ($this->tasks as $task) {
            $success = $records[$task['command']]['last_success_at'] ?? null;

            if ($success === null) {
                $health[$task['command']] = ['age' => 'never', 'stale' => true];

                continue;
            }

            $at = new DateTimeImmutable($success);

            $health[$task['command']] = [
                'age' => self::age($at, $now),
                'stale' => $at < self::graceBoundary($task['cron'], $now),
            ];
        }

        return $health;
    }

    /**
     * Whether anything on the schedule has stopped working.
     *
     * @param array<string, array{last_success_at?: ?string, ...}> $records
     */
    public function isStale(DateTimeImmutable $now, array $records): bool
    {
        foreach ($this->health($now, $records) as $task) {
            if ($task['stale']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The moment before which a success counts as too long ago.
     *
     * Two occurrences back, so one whole gap is forgiven. Falls back to the
     * first occurrence when a second cannot be found inside the search window,
     * which is what happens for an expression that fires less often than the
     * window is long.
     */
    private static function graceBoundary(Cron $cron, DateTimeImmutable $now): DateTimeImmutable
    {
        $last = $cron->previous($now, self::UNSEEN_LOOKBACK_MINUTES);

        if ($last === null) {
            return $now->modify('-' . self::UNSEEN_LOOKBACK_MINUTES . ' minutes');
        }

        return $cron->previous($last->modify('-1 minute'), self::UNSEEN_LOOKBACK_MINUTES) ?? $last;
    }

    private static function minutesBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
    }

    /**
     * How long ago, in the coarsest unit that still says something.
     *
     * Minutes below an hour, hours below a day, then days. A monitor comparing
     * `4h` against `2d` does not need the seconds, and a person reading the log
     * at three in the morning does not want them.
     */
    private static function age(DateTimeImmutable $at, DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < 3600 => intdiv($seconds, 60) . 'm',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h',
            default => intdiv($seconds, 86400) . 'd',
        };
    }
}
