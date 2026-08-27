<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\AirportRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class AirportRepositoryTest extends IntegrationTestCase
{
    private const EXPECTED_COLUMNS = [
        'code', 'title', 'country', 'city_code', 'city',
        'timezone', 'timezone_name', 'latitude', 'longitude', 'altitude',
    ];

    public function testEnabledReturnsRowsWithTheCountryJoinColumns(): void
    {
        $airports = $this->repository()->enabled(false);

        self::assertNotEmpty($airports);
        self::assertSame(self::EXPECTED_COLUMNS, array_keys($airports[0]));
    }

    public function testEnabledMajorOnlyIsASubsetOfAllEnabled(): void
    {
        $all = $this->repository()->enabled(false);
        $major = $this->repository()->enabled(true);

        self::assertLessThanOrEqual(count($all), count($major));
        self::assertNotEmpty($major);
    }

    public function testEnabledIsOrderedByTitle(): void
    {
        $titles = array_column($this->repository()->enabled(true), 'title');
        $canonical = array_column(
            $this->connection()->fetchAll(
                'SELECT a.title FROM airports a WHERE a.enabled = 1 AND is_major = 1 ORDER BY a.title ASC',
            ),
            'title',
        );

        self::assertSame($canonical, $titles);
    }

    public function testAutofillMatchesCityAndReturnsJoinColumns(): void
    {
        $airports = $this->repository()->autofill('mon');

        self::assertNotEmpty($airports);
        self::assertSame(self::EXPECTED_COLUMNS, array_keys($airports[0]));
        // 'mon' should surface Montreal.
        self::assertContains('YUL', array_column($airports, 'code'));
    }

    private function repository(): AirportRepository
    {
        return new AirportRepository($this->connection());
    }
}
