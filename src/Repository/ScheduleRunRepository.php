<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * @phpstan-type ScheduleRunRow array{
 *     command: string, last_run_at: string, last_success_at: ?string, last_exit: int,
 * }
 */
final readonly class ScheduleRunRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Every command's record, keyed by command.
     *
     * @return array<string, array{last_run_at: string, last_success_at: ?string, last_exit: int}>
     */
    public function all(): array
    {
        $records = [];

        /** @var list<ScheduleRunRow> $rows */
        $rows = $this->connection->fetchAll('SELECT * FROM ' . Table::ScheduleRuns->value);

        foreach ($rows as $row) {
            $records[(string) $row['command']] = [
                'last_run_at' => (string) $row['last_run_at'],
                'last_success_at' => $row['last_success_at'] === null ? null : (string) $row['last_success_at'],
                'last_exit' => (int) $row['last_exit'],
            ];
        }

        return $records;
    }

    /**
     * Claim a command, before it runs.
     *
     * Stamped at the start and not at the end, which is what stops the tick
     * fifteen minutes from now picking up a task that is still going -- and
     * stops one whose process was killed from retrying in a loop. The exit
     * code is left at whatever it was until `finished()` corrects it.
     */
    public function started(string $command, string $at): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::ScheduleRuns->value
            . ' (command, last_run_at, last_success_at, last_exit) VALUES (?, ?, NULL, 0)'
            . ' ON DUPLICATE KEY UPDATE last_run_at = ?',
            [$command, $at, $at],
        );
    }

    /**
     * Record how it went.
     *
     * `last_success_at` moves only on a zero exit. A command failing every
     * night has a fresh `last_run_at` and a rotting `last_success_at`, and the
     * second is the one E16.2 (#169) reads -- a single timestamp would call
     * that healthy.
     */
    public function finished(string $command, int $exit, string $at): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::ScheduleRuns->value
            . ' SET last_exit = ?, last_success_at = IF(? = 0, ?, last_success_at)'
            . ' WHERE command = ?',
            [$exit, $exit, $at, $command],
        );
    }
}
