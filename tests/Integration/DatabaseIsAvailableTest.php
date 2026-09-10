<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

/**
 * The integration suite has a database to work with.
 *
 * A canary, and the only test here that fails rather than skips when there is
 * none. Every other test in this suite skips, which is the right answer for a
 * test that cannot do its job -- but a suite that answers "OK, 166 skipped" is
 * how a fifth of the coverage goes unrun for weeks, and that is what happened.
 *
 * Failing here rather than passing `--fail-on-skipped` to PHPUnit, which was
 * the first attempt: about a dozen tests in this suite skip themselves when
 * the generated network happens to have no flights or no connections on the
 * route they picked. Those skips are legitimate and depend on random data, so
 * a runner that refused every skip would fail for reasons that have nothing to
 * do with the code. This one refuses the single skip that means the suite is
 * not really running.
 */
final class DatabaseIsAvailableTest extends IntegrationTestCase
{
    public function testTheSuiteHasADatabase(): void
    {
        self::assertNotNull(
            $this->connectionOrNull(),
            'The integration suite needs a database and could not reach one. Its settings come from'
            . ' .env, the same ones `php noah install` uses; export DB_HOST, DB_PORT or DB_SOCKET to'
            . ' point somewhere else. See the Tests section of the README. To run only the tests that'
            . ' need no database: composer test:unit',
        );
    }
}
