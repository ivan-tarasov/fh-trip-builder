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

    /**
     * Open a new row in the real, per-run history -- unlike `started()`
     * above, never an upsert: every call is a separate attempt (G19, #377).
     *
     * Returns the row's id so `historyFinished()` can be told which one to
     * close, since nothing else here ties a start to its own finish.
     */
    public function historyStarted(string $command, string $at): int
    {
        return $this->connection->insert(
            'INSERT INTO ' . Table::ScheduleRunHistory->value . ' (command, started_at) VALUES (?, ?)',
            [$command, $at],
        );
    }

    /**
     * Close the row `historyStarted()` opened.
     *
     * A row that never reaches this -- `finished_at` and `exit_code` both
     * still null -- is a run that was killed or crashed, visible in the
     * history rather than silently missing from it.
     */
    public function historyFinished(int $id, int $exit, string $at): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::ScheduleRunHistory->value . ' SET exit_code = ?, finished_at = ? WHERE id = ?',
            [$exit, $at, $id],
        );
    }

    /**
     * One command's most recent attempts, newest first.
     *
     * @return list<array{started_at: string, finished_at: ?string, exit_code: ?int}>
     */
    public function historyFor(string $command, int $limit = 50): array
    {
        /** @var list<array{started_at: string, finished_at: ?string, exit_code: ?int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT started_at, finished_at, exit_code FROM ' . Table::ScheduleRunHistory->value
            . ' WHERE command = ? ORDER BY started_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$command],
        );

        return $rows;
    }
}
