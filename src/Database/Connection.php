<?php

declare(strict_types=1);

namespace TripBuilder\Database;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use TripBuilder\Env;

/**
 * Thin typed wrapper around a single PDO connection.
 *
 * Replaces the untyped thingengineer/mysqli-database-class query builder:
 * callers pass explicit parameterised SQL and get back typed results.
 */
final class Connection
{
    private int $queryCount = 0;

    /**
     * One connection per process. A request is a process here, so this is
     * request scope; a Noah command holds it for the length of the command.
     */
    private static ?self $shared = null;

    public function __construct(private readonly PDO $pdo)
    {
        // One clock for both halves of the application (E17, #171).
        //
        // Measured 2026-09-12, on the laptop and again in production: PHP on
        // UTC, MySQL on `SYSTEM` and therefore on the server's Eastern clock,
        // four hours apart. This codebase writes timestamps with both -- 37
        // `NOW()` sites and `date()` everywhere else -- so two clocks were
        // writing into the same tables, four hours apart, and nothing errored.
        //
        // The flight comparisons that made this urgent are fixed separately and
        // differently: `departure_time` is local at the airport, so no server
        // clock could have made `>= NOW()` right. See E20 (#180).
        //
        // Here and not in `fromEnv()`, which is where it was first written.
        // The integration harness builds its own PDO and wraps it directly, so
        // a fix in the factory left every test running against a connection
        // configured differently from the one the application uses -- which is
        // the shape of a test suite that agrees with itself and not with
        // production.
        //
        // An offset and not a named zone. `SET time_zone = 'UTC'` needs MySQL's
        // timezone tables loaded, which shared hosting frequently does not have
        // and which fails a long way from this line. An offset is always
        // understood.
        //
        // Read once, which is safe only because both entry points pin PHP to
        // UTC and UTC has no transitions. On a zone that observed DST this
        // offset would go stale an hour after one, inside any command running
        // long enough to span it. ClockTest asserts both halves of that.
        $pdo->exec(sprintf("SET time_zone = '%s'", new DateTimeImmutable()->format('P')));
    }

    /**
     * Number of statements run on this connection (diagnostic).
     */
    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * The connection for this process, built from the DB_* environment
     * variables on first use.
     *
     * Shared rather than built per caller. A page used to open two -- one for
     * the controller and one for LayoutData -- which cost a second MySQL
     * connection per request and, because queryCount() is per instance, made
     * the footer's "DB requests" read 1 on every page in the app including a
     * search that runs dozens. The number was measuring the connection nobody
     * was using.
     *
     * Credentials come from Env::get(), which is where every other setting
     * comes from -- see the note there about which source wins.
     */
    public static function fromEnv(): self
    {
        if (self::$shared instanceof self) {
            return self::$shared;
        }

        $pdo = new PDO(
            self::dsn([
                'DB_HOST' => Env::get('DB_HOST'),
                'DB_DATABASE' => Env::get('DB_DATABASE'),
                'DB_PORT' => Env::get('DB_PORT'),
            ]),
            Env::get('DB_USERNAME'),
            Env::get('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        return self::$shared = new self($pdo);
    }

    /**
     * Build a `mysql:` DSN from a DB_* environment map. Honors DB_PORT
     * (the legacy mysqli web path ignored it) and pins utf8mb4.
     *
     * @param array<string, string> $env
     */
    public static function dsn(array $env): string
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_DATABASE'] ?? '',
        );

        if (!empty($env['DB_PORT'])) {
            $dsn .= ';port=' . (int) $env['DB_PORT'];
        }

        return $dsn;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function fetchValue(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    /**
     * Run an INSERT and return the last insert id.
     *
     * @param array<string|int, mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Run a write statement (UPDATE/DELETE/DDL) and return affected rows.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $this->queryCount++;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params === [] ? null : $params);

        return $statement;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
