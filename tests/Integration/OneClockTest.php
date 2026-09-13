<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use TripBuilder\Clock;

/**
 * The application and its database agree about the time.
 *
 * They did not. Measured 2026-09-12 on the laptop and again against
 * production: PHP on UTC, MySQL on `SYSTEM` and therefore on the server's
 * Eastern clock, four hours apart in both places (E17, #171).
 *
 * This is the assertion that says the fix in `Connection::fromEnv()` is doing
 * something. It would have failed on every machine this project has ever run
 * on, and the reason nobody noticed is that nothing else would: no error, no
 * empty result, no broken page -- every timestamp kept being written, four
 * hours from the one beside it.
 */
final class OneClockTest extends IntegrationTestCase
{
    public function testTheDatabaseKeepsTheSameTimeAsTheApplication(): void
    {
        $drift = Clock::drift($this->connection());

        self::assertNotNull($drift, 'the database could not be asked the time');
        self::assertLessThanOrEqual(
            Clock::TOLERANCE_SECONDS,
            abs($drift),
            sprintf(
                'The database is %s. The Connection constructor sets the session timezone from '
                . 'PHP, so this failing means that line is gone.',
                Clock::describe($drift),
            ),
        );
    }
}
