<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Clock;
use TripBuilder\Health;
use TripBuilder\Helper;
use TripBuilder\Log;
use TripBuilder\Repository\ScheduledJobRepository;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Schedule;

/**
 * One URL that says whether this is working.
 *
 * There was no such URL: a monitor could fetch the homepage and get a 200 from
 * a page that had swallowed a database error into an empty block, because most
 * of this application is written to degrade rather than fail. That is right for
 * a visitor and useless for a check.
 *
 * So this asks the database a question it cannot answer from a cache, and says
 * plainly what came back.
 *
 * No authentication and nothing sensitive. The version it reports is already in
 * the footer of every page, and a check that needs a credential is a check that
 * stops working the day the credential rotates.
 */
final class HealthController extends AbstractController
{
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // A monitor polling a cached answer is monitoring the cache.
        header('Cache-Control: no-store');

        $database = $this->databaseAnswers();

        http_response_code(Health::statusCode($database)->value);

        echo json_encode(
            Health::report(
                $database,
                $this->version(),
                $database ? $this->schedule() : [],
                $database ? Clock::drift($this->connection()) : null,
            ),
            JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * How the scheduled commands are doing.
     *
     * Only asked when the database answered, because these records come from
     * it -- reporting "never" for everything because the connection is down
     * would be saying the same thing twice and one of them wrongly.
     *
     * @return array<string, array{age: string, stale: bool}>
     */
    private function schedule(): array
    {
        try {
            return Schedule::fromRows(new ScheduledJobRepository($this->connection())->allEnabled())
                ->health(new DateTimeImmutable(), new ScheduleRunRepository($this->connection())->all());
        } catch (Throwable $e) {
            Log::error('Health check could not read the schedule: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Ask the database a question it cannot answer from anything this process
     * already holds. The point is that it answered, now.
     */
    private function databaseAnswers(): bool
    {
        try {
            $this->connection()->fetchValue('SELECT 1');

            return true;
        } catch (Throwable $e) {
            Log::error('Health check failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * What is deployed, spelled the way the footer spells it.
     *
     * Best effort. `Helper::getGitInfo()` shells out to git, so a deployment
     * that is an export rather than a checkout has no answer -- and a health
     * endpoint that 500s because it could not name a version would be
     * reporting on itself rather than on the application.
     */
    private function version(): string
    {
        try {
            $git = Helper::getGitInfo();

            return sprintf('%s-%s-%s', $git['tag'], $git['branch'], $git['commit_hash']);
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
