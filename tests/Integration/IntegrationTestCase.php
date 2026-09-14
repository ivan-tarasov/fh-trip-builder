<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TripBuilder\Database\Connection;
use TripBuilder\Horizon;

/**
 * Base for tests that need a live MySQL database.
 *
 * Connects from environment: DB_SOCKET (local fixture) or DB_HOST/DB_PORT (CI),
 * plus DB_DATABASE/DB_USERNAME/DB_PASSWORD. When no database is reachable the
 * tests skip, so the suite stays green on machines without one.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Connection $connection = null;
    private static bool $attempted = false;

    protected function connection(): Connection
    {
        $connection = $this->connectionOrNull();

        if ($connection === null) {
            self::markTestSkipped('No test database available (set DB_DATABASE and DB_SOCKET or DB_HOST).');
        }

        return $connection;
    }

    /**
     * A departure date no generated flight can ever occupy.
     *
     * `flights:add` draws its days from tomorrow to `Horizon::DAYS`, so a test
     * that fixes a literal date inside that window is asserting about its own
     * rows *and* about whatever the generator happened to put on the same route
     * that day. The generator is random and CI rebuilds the corpus on every
     * run, so such a test passes or fails by luck.
     *
     * Measured on one generated corpus: `YUL -> LHR` appeared on 18 of the 365
     * days, so a fixed date inside the window collided about one run in twenty.
     * That is exactly how `SearchEmissionsTest` failed on a pull request that
     * touched neither flights nor emissions.
     *
     * A literal cannot be right for long either way, because the window rolls:
     * a date beyond it today is inside it a year from now. So this is derived
     * from the same constant the generator fills up to, and moves with it.
     *
     * **Only for a test that calls `FlightRepository` directly.** A test that
     * goes through a real page -- `SearchController`, `SearchUrl` -- cannot use
     * this: `SearchUrl::withinHorizon()` refuses to build a search past
     * `Horizon::last()` at all, so a date from here makes that kind of test
     * search nothing rather than search safely. `SavedFlightCabinTest` found
     * this the hard way. A page-level test has no way to be both searchable and
     * guaranteed empty, and has to live with the shared corpus instead --
     * which is fine as long as its assertions check presence or a relation
     * rather than an exact count.
     *
     * @param int $plusDays days past the horizon, to give tests dates of their own
     */
    protected static function dateBeyondGeneratedFlights(int $plusDays = 1): string
    {
        return date(
            'Y-m-d',
            (int) strtotime(sprintf('%s +%d days', Horizon::last(), max(1, $plusDays))),
        );
    }

    /**
     * The connection, or null where there is nothing to connect to.
     *
     * For setUp and tearDown, and the reason they need their own accessor:
     * `markTestSkipped` raised from a tearDown is a *failure* in PHPUnit and
     * not a skip. So a class that tidied up after itself failed where an
     * otherwise identical class that did not tidy up, skipped -- and which of
     * the two a class happened to be decided whether a run with no database
     * reported sixty-four failures or none of them.
     *
     * Skipping is the right answer for a test body, which cannot do its job
     * without rows. It is the wrong answer for a teardown, which has nothing
     * to do when nothing was written.
     */
    protected function connectionOrNull(): ?Connection
    {
        if (!self::$attempted) {
            self::$attempted = true;

            try {
                self::$connection = new Connection(self::makePdo());
            } catch (Throwable) {
                self::$connection = null;
            }
        }

        return self::$connection;
    }

    private static function makePdo(): PDO
    {
        $database = (string) (getenv('DB_DATABASE') ?: '');

        if ($database === '') {
            throw new RuntimeException('DB_DATABASE not set');
        }

        $socket = (string) (getenv('DB_SOCKET') ?: '');

        if ($socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $database);
        } else {
            $dsn = Connection::dsn([
                'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
                'DB_DATABASE' => $database,
                'DB_PORT' => (string) (getenv('DB_PORT') ?: ''),
            ]);
        }

        return new PDO(
            $dsn,
            (string) (getenv('DB_USERNAME') ?: ''),
            (string) (getenv('DB_PASSWORD') ?: ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
