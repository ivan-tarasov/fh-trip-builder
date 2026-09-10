<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TripBuilder\Database\Connection;

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
