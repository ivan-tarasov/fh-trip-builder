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

    public function testAutofillReturnsMajorAirportsOnly(): void
    {
        $codes = array_column($this->repository()->autofill('mon'), 'code');

        // YUL (Montreal) is major and should appear...
        self::assertContains('YUL', $codes);
        // ...while MVD (Montevideo) is a minor airport also matching 'mon' and
        // must be excluded: flights are only generated between major airports,
        // so suggesting it would always yield an empty search.
        self::assertNotContains('MVD', $codes);

        // Belt and braces: every suggestion must be a major, enabled airport.
        $majorCodes = array_column(
            $this->connection()->fetchAll('SELECT code FROM airports WHERE enabled = 1 AND is_major = 1'),
            'code',
        );
        self::assertNotEmpty($codes);
        self::assertEmpty(array_diff($codes, $majorCodes));
    }

    private function repository(): AirportRepository
    {
        return new AirportRepository($this->connection());
    }
}
