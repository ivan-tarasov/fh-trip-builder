<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What the health endpoint says, decided away from the request.
 *
 * A pure function of what was measured, so both answers can be tested -- the
 * failing one especially, which is the answer that matters and the one a live
 * check cannot produce on demand: `Connection::fromEnv()` shares one connection
 * per process, so there is no way to ask a working application for a broken
 * database.
 */
final readonly class Health
{
    /**
     * `status` follows the database and not the schedule, deliberately.
     *
     * Rates being a day old is not a reason to tell a load balancer the site is
     * down, and a check that cannot tell those apart gets muted. So a stopped
     * scheduler shows in its own field, where a monitor can assert on it
     * without confusing it with the site being unreachable (E16.2, #169).
     *
     * @param array<string, array{age: string, stale: bool}> $schedule
     * @return array{status: string, db: string, schedule: string, tasks: array<string, string>, version: string}
     */
    public static function report(bool $database, string $version, array $schedule = []): array
    {
        $stale = array_filter($schedule, static fn(array $task): bool => $task['stale']);

        return [
            'status' => $database ? 'ok' : 'error',
            'db' => $database ? 'ok' : 'down',
            'schedule' => $schedule === [] ? 'unknown' : ($stale === [] ? 'ok' : 'stale'),
            'tasks' => array_map(static fn(array $task): string => $task['age'], $schedule),
            'version' => $version,
        ];
    }

    /**
     * `503` and not `500`: the application is answering, the thing behind it is
     * not, and that is a difference a load balancer acts on.
     */
    public static function statusCode(bool $database): int
    {
        return $database ? 200 : 503;
    }
}
