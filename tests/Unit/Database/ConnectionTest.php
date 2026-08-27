<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Database\Connection;

final class ConnectionTest extends TestCase
{
    /**
     * @param array<string, string> $env
     */
    #[DataProvider('dsnProvider')]
    public function testDsn(string $expected, array $env): void
    {
        self::assertSame($expected, Connection::dsn($env));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function dsnProvider(): array
    {
        return [
            'host + db + port' => [
                'mysql:host=db.example.com;dbname=trip;charset=utf8mb4;port=3307',
                ['DB_HOST' => 'db.example.com', 'DB_DATABASE' => 'trip', 'DB_PORT' => '3307'],
            ],
            'port honored as int' => [
                'mysql:host=localhost;dbname=trip;charset=utf8mb4;port=3306',
                ['DB_HOST' => 'localhost', 'DB_DATABASE' => 'trip', 'DB_PORT' => '3306'],
            ],
            'no port omits the segment' => [
                'mysql:host=localhost;dbname=trip;charset=utf8mb4',
                ['DB_HOST' => 'localhost', 'DB_DATABASE' => 'trip'],
            ],
            'empty port omits the segment' => [
                'mysql:host=localhost;dbname=trip;charset=utf8mb4',
                ['DB_HOST' => 'localhost', 'DB_DATABASE' => 'trip', 'DB_PORT' => ''],
            ],
            'defaults when unset' => [
                'mysql:host=127.0.0.1;dbname=;charset=utf8mb4',
                [],
            ],
        ];
    }

    public function testDsnAlwaysPinsUtf8mb4(): void
    {
        self::assertStringContainsString('charset=utf8mb4', Connection::dsn(['DB_HOST' => 'x', 'DB_DATABASE' => 'y']));
    }
}
