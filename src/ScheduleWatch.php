<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\ScheduleRunRepository;

/**
 * Noticing, from the one part of this that is still running.
 *
 * E16.1 (#168) put every scheduled command behind a single cron line. If that
 * line stops firing then rates go stale and the retention policy stops
 * applying, together, and nothing says so -- which is a worse failure than the
 * two lines it replaced unless something is watching.
 *
 * Nothing inside a stopped system can report that it stopped. But this one is a
 * website: a broken crontab does not stop page requests, so every request is a
 * heartbeat that can carry the check. That is the whole idea here.
 *
 * It does not run the overdue task. `currency:rates` calls out over HTTP, and a
 * visitor's page load should not pay for a broken crontab. Detection here;
 * execution stays in cron.
 */
final readonly class ScheduleWatch
{
    /**
     * How often the question is actually asked.
     *
     * The answer changes daily, so asking on every page load would be a query
     * per request to learn something that cannot have changed. The marker's
     * mtime gates it: one `filemtime` on the hot path, a query at most hourly.
     */
    private const int EVERY_SECONDS = 3600;

    private const string MARKER = '/cache/schedule-checked';

    /**
     * Write one line if the schedule has stopped working.
     *
     * Swallows everything. A diagnostic that can take a page down with it is
     * worse than no diagnostic, and this runs on requests that have already
     * finished answering.
     */
    public static function warnIfStale(): void
    {
        try {
            if (!self::isTimeToAsk()) {
                return;
            }

            $now = new DateTimeImmutable();
            $schedule = Schedule::fromConfig(Helper::getRootDir() . '/config/noah/schedule.php');
            $records = new ScheduleRunRepository(Connection::fromEnv())->all();

            foreach ($schedule->health($now, $records) as $command => $task) {
                if ($task['stale']) {
                    Log::error(sprintf(
                        'Scheduled command `%s` last worked %s ago. Is cron still running `schedule:run`?',
                        $command,
                        $task['age'],
                    ));
                }
            }
        } catch (Throwable) {
            // Deliberately silent. Logging a failure to log would be the only
            // thing this could do, and it would do it on every request.
        }
    }

    /**
     * Whether an hour has passed since the last check, marking it if so.
     *
     * The mark is written before the query rather than after, so a check that
     * throws does not retry on the very next request and turn one broken thing
     * into a query per page view.
     */
    private static function isTimeToAsk(): bool
    {
        $marker = Helper::getRootDir() . self::MARKER;
        $last = is_file($marker) ? (int) filemtime($marker) : 0;

        if (time() - $last < self::EVERY_SECONDS) {
            return false;
        }

        return touch($marker);
    }
}
