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
        if (!self::$attempted) {
            self::$attempted = true;

            try {
                self::$connection = new Connection(self::makePdo());
            } catch (Throwable) {
                self::$connection = null;
            }
        }

        if (self::$connection === null) {
            self::markTestSkipped('No test database available (set DB_DATABASE and DB_SOCKET or DB_HOST).');
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
