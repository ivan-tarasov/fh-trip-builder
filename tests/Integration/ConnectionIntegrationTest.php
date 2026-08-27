<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

final class ConnectionIntegrationTest extends IntegrationTestCase
{
    public function testFetchValueRunsAScalarQuery(): void
    {
        self::assertSame(2, (int) $this->connection()->fetchValue('SELECT 1 + 1'));
    }

    public function testFetchOneReturnsAnAssociativeRow(): void
    {
        $row = $this->connection()->fetchOne('SELECT ? AS code, ? AS name', ['YUL', 'Montreal']);

        self::assertSame(['code' => 'YUL', 'name' => 'Montreal'], $row);
    }

    public function testFetchAllReturnsRows(): void
    {
        $rows = $this->connection()->fetchAll(
            'SELECT 1 AS n UNION ALL SELECT 2 ORDER BY n',
        );

        self::assertSame([['n' => 1], ['n' => 2]], $rows);
    }

    public function testSeededReferenceDataIsPresent(): void
    {
        $countries = (int) $this->connection()->fetchValue('SELECT COUNT(*) FROM countries');

        self::assertGreaterThan(0, $countries, 'app:install should have seeded countries');
    }
}
