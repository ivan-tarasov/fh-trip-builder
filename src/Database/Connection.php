<?php

declare(strict_types=1);

namespace TripBuilder\Database;

use PDO;
use PDOStatement;

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

    public function __construct(private readonly PDO $pdo) {}

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
     * Reads real environment variables first (getenv), falling back to $_ENV.
     * This matters in CI, where the credentials are process env vars and
     * phpdotenv's immutable loader won't copy them into $_ENV.
     */
    public static function fromEnv(): self
    {
        if (self::$shared instanceof self) {
            return self::$shared;
        }

        $pdo = new PDO(
            self::dsn([
                'DB_HOST' => self::env('DB_HOST'),
                'DB_DATABASE' => self::env('DB_DATABASE'),
                'DB_PORT' => self::env('DB_PORT'),
            ]),
            self::env('DB_USERNAME'),
            self::env('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        return self::$shared = new self($pdo);
    }

    /**
     * Read a config value: real environment variable first, then $_ENV.
     * getenv() distinguishes "unset" (false) from a legitimately empty value.
     */
    private static function env(string $key): string
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        return isset($_ENV[$key]) ? (string) $_ENV[$key] : '';
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
