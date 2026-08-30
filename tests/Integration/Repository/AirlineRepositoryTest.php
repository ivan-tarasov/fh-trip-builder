<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class AirlineRepositoryTest extends IntegrationTestCase
{
    public function testSearchReturnsAllAirlinesOrderedByTitle(): void
    {
        $airlines = $this->repository()->search(null, false);

        self::assertNotEmpty($airlines);

        // Compare against the canonical DB ordering rather than re-sorting in PHP
        // (MySQL's collation differs from PHP's byte-wise sort).
        $canonical = $this->connection()->fetchAll('SELECT title FROM airlines ORDER BY title ASC');

        self::assertSame(
            array_column($canonical, 'title'),
            array_column($airlines, 'title'),
        );
    }

    public function testMajorOnlyFiltersToMajorCarriers(): void
    {
        foreach ($this->repository()->search(null, true) as $airline) {
            self::assertSame(1, (int) $airline['is_major']);
        }
    }

    public function testSearchByCodesReturnsOnlyThoseCodes(): void
    {
        $airlines = $this->repository()->search(['AC', 'WS'], false);

        $codes = array_column($airlines, 'code');
        sort($codes);
        self::assertSame(['AC', 'WS'], $codes);
    }

    public function testRowShapeMatchesTableColumns(): void
    {
        $airlines = $this->repository()->search(['AC'], false);

        self::assertCount(1, $airlines);

        // Compared against the table itself rather than a copied-out list, so
        // adding a column does not fail a test that is about row shape.
        $columns = array_column(
            $this->connection()->fetchAll('SHOW COLUMNS FROM airlines'),
            'Field',
        );

        self::assertSame($columns, array_keys($airlines[0]));
    }

    private function repository(): AirlineRepository
    {
        return new AirlineRepository($this->connection());
    }
}
