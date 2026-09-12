<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;
use RuntimeException;

/**
 * What runs without anybody asking, and whether it is due.
 *
 * The schedule is `config/noah/schedule.php`, in git. The server holds one
 * cron line and no knowledge of what it is for (E16, #167).
 *
 * Deliberately not a cron-expression parser, and the reason is the outer cron
 * rather than the effort. That line runs every fifteen minutes, so fifteen
 * minutes is the finest thing this can express no matter how it is spelled --
 * a `*​/5` here would be a lie. Full expressions become worth having the day a
 * task needs "every Monday" or "weekdays only", and that is the day to reach
 * for `dragonmantank/cron-expression` rather than hand-roll one: day-of-week
 * against day-of-month is an OR, not an AND, and that is the kind of detail
 * that is wrong for a year in something nobody watches run.
 */
final readonly class Schedule
{
    /**
     * @param list<array{command: string, every: Frequency, at: string}> $tasks
     */
    public function __construct(private array $tasks) {}

    public static function fromConfig(string $file): self
    {
        /** @var mixed $tasks */
        $tasks = require $file;

        if (!is_array($tasks)) {
            throw new RuntimeException($file . ' must return an array of tasks.');
        }

        foreach ($tasks as $task) {
            if (!is_array($task) || !isset($task['command'], $task['every'], $task['at'])) {
                throw new RuntimeException('Every scheduled task needs a `command`, an `every` and an `at`.');
            }

            if (!$task['every'] instanceof Frequency) {
                throw new RuntimeException(sprintf('`%s` has no Frequency.', $task['command']));
            }

            if (preg_match($task['every']->pattern(), (string) $task['at']) !== 1) {
                throw new RuntimeException(sprintf(
                    '`%s` is %s and its `at` is `%s`, which is not the shape that takes.',
                    $task['command'],
                    $task['every']->value,
                    $task['at'],
                ));
            }
        }

        /** @var list<array{command: string, every: Frequency, at: string}> $tasks */
        return new self(array_values($tasks));
    }

    /**
     * @return list<array{command: string, every: Frequency, at: string}>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * The tasks that should run now.
     *
     * Due when nothing has run since the moment it was last supposed to. A
     * tick missed because the server was down or mid-deploy therefore catches
     * up on the next one instead of skipping the day, and a command with no
     * record at all is due at once.
     *
     * Measured against `last_run_at` and not `last_success_at`, because this
     * decides whether to *start* something. A command that failed at 03:00
     * must not be started again at 03:15 and every quarter hour after that; it
     * is due again tomorrow, and in between its rotting `last_success_at` is
     * what says something is wrong (E16.2, #169).
     *
     * @param array<string, array{last_run_at: string, ...}> $records
     * @return list<array{command: string, every: Frequency, at: string}>
     */
    public function due(DateTimeImmutable $now, array $records): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            $last = $records[$task['command']]['last_run_at'] ?? null;

            if ($last === null || new DateTimeImmutable($last) < $task['every']->lastOccurrence($now, $task['at'])) {
                $due[] = $task;
            }
        }

        return $due;
    }
}
