<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The live schedule -- what `Schedule::fromRows()` builds from, and what
 * `/admin/schedule` edits (G19, #377).
 *
 * @phpstan-type ScheduledJobRow array{
 *     id: int, command: string, minute: string, hour: string, day: string,
 *     month: string, weekday: string, enabled: bool,
 * }
 */
final readonly class ScheduledJobRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Every job, enabled and disabled -- what the list page shows.
     *
     * @return list<ScheduledJobRow>
     */
    public function all(): array
    {
        return array_map(
            self::shaped(...),
            $this->connection->fetchAll('SELECT * FROM ' . Table::ScheduledJobs->value . ' ORDER BY command'),
        );
    }

    /**
     * Only what should actually run -- what `Schedule::fromRows()` is fed.
     *
     * @return list<ScheduledJobRow>
     */
    public function allEnabled(): array
    {
        return array_map(
            self::shaped(...),
            $this->connection->fetchAll(
                'SELECT * FROM ' . Table::ScheduledJobs->value . ' WHERE enabled = 1 ORDER BY command',
            ),
        );
    }

    /** @return ?ScheduledJobRow */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM ' . Table::ScheduledJobs->value . ' WHERE id = ?',
            [$id],
        );

        return $row === null ? null : self::shaped($row);
    }

    public function commandExists(string $command, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . Table::ScheduledJobs->value . ' WHERE command = ?';
        $params = [$command];

        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        /** @var int $count */
        $count = $this->connection->fetchValue($sql, $params);

        return $count > 0;
    }

    public function create(
        string $command,
        string $minute,
        string $hour,
        string $day,
        string $month,
        string $weekday,
        bool $enabled,
    ): int {
        return $this->connection->insert(
            'INSERT INTO ' . Table::ScheduledJobs->value
            . ' (command, minute, hour, day, month, weekday, enabled) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$command, $minute, $hour, $day, $month, $weekday, $enabled ? 1 : 0],
        );
    }

    public function update(
        int $id,
        string $command,
        string $minute,
        string $hour,
        string $day,
        string $month,
        string $weekday,
        bool $enabled,
    ): void {
        $this->connection->execute(
            'UPDATE ' . Table::ScheduledJobs->value
            . ' SET command = ?, minute = ?, hour = ?, day = ?, month = ?, weekday = ?, enabled = ?, updated = NOW()'
            . ' WHERE id = ?',
            [$command, $minute, $hour, $day, $month, $weekday, $enabled ? 1 : 0, $id],
        );
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::ScheduledJobs->value . ' SET enabled = ?, updated = NOW() WHERE id = ?',
            [$enabled ? 1 : 0, $id],
        );
    }

    public function remove(int $id): void
    {
        $this->connection->execute('DELETE FROM ' . Table::ScheduledJobs->value . ' WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return ScheduledJobRow
     */
    private static function shaped(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'command' => (string) $row['command'],
            'minute' => (string) $row['minute'],
            'hour' => (string) $row['hour'],
            'day' => (string) $row['day'],
            'month' => (string) $row['month'],
            'weekday' => (string) $row['weekday'],
            'enabled' => (bool) $row['enabled'],
        ];
    }
}
