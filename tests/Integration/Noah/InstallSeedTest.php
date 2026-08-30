<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Noah;

use PDO;
use ReflectionClass;
use ReflectionProperty;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Noah\Db\Install;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The seeder's upsert has to run on whatever MySQL or MariaDB the app is
 * deployed to. The `VALUES (...) AS alias` form only exists in MySQL 8.0.19+
 * and not in MariaDB at all, so seeding silently failed on shared hosting while
 * passing locally. These tests run the generated statement against the server
 * the suite is connected to, whichever that is.
 */
final class InstallSeedTest extends IntegrationTestCase
{
    private const COLUMNS = ['code', 'title', 'url', 'phone', 'country', 'hubs', 'traffic', 'is_major'];
    private const PROBE = 'ZZ';

    protected function tearDown(): void
    {
        $this->connection()->execute('DELETE FROM airlines WHERE code = ?', [self::PROBE]);
    }

    public function testSeedStatementIsAcceptedByThisServer(): void
    {
        $sql = $this->seedStatement('airlines', self::COLUMNS);

        // Prepared without emulation, so the server itself parses the syntax.
        $this->connection()->pdo()->prepare($sql);

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    public function testSeedStatementInsertsThenUpdatesInsteadOfDuplicating(): void
    {
        $sql = $this->seedStatement('airlines', self::COLUMNS);

        $this->connection()->execute($sql, [self::PROBE, 'Probe', '', '', 'XX', 'XXX', 0, 0]);
        $this->connection()->execute($sql, [self::PROBE, 'Probe Renamed', '', '', 'XX', 'XXX', 0, 0]);

        self::assertSame(
            1,
            (int) $this->connection()->fetchValue('SELECT COUNT(*) FROM airlines WHERE code = ?', [self::PROBE]),
        );
        self::assertSame(
            'Probe Renamed',
            $this->connection()->fetchValue('SELECT title FROM airlines WHERE code = ?', [self::PROBE]),
        );
    }

    public function testRowAliasIsOnlyUsedWhereItExists(): void
    {
        $version = (string) $this->connection()->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $sql = $this->seedStatement('airlines', self::COLUMNS);

        $expected = !str_contains(strtolower($version), 'mariadb')
            && version_compare($version, '8.0.19', '>=');

        self::assertSame($expected, str_contains($sql, ' AS new'), "server reported: {$version}");
    }

    /**
     * @param list<string> $columns
     */
    private function seedStatement(string $table, array $columns): string
    {
        $class = new ReflectionClass(Install::class);
        $command = $class->newInstanceWithoutConstructor();

        $connection = new ReflectionProperty(AbstractCommand::class, 'connection');
        $connection->setValue($command, $this->connection());

        $method = $class->getMethod('seedStatement');

        return $method->invoke($command, $table, $columns);
    }
}
