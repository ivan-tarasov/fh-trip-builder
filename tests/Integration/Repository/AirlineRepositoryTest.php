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

    /**
     * The airline page's own lookup, which is narrower than search(): it
     * answers only for airlines we sell, so a carrier in the table with no
     * flights has no page rather than an empty one.
     */
    public function testByCodeAnswersForAnAirlineWeSell(): void
    {
        $airline = $this->repository()->byCode('AC');

        self::assertNotNull($airline);
        self::assertSame('AC', $airline['code']);
        self::assertSame('Canada', $airline['country'], 'the country name should be joined, not its code');
        self::assertSame('CA', $airline['country_code']);
        self::assertNotEmpty($airline['hubs']);
    }

    public function testByCodeIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->repository()->byCode('AC'),
            $this->repository()->byCode('ac'),
            'a lower-case code in a URL should find the same airline',
        );
    }

    /**
     * The two ways there is no page: a code that is nobody, and a code that is
     * an airline we do not sell. Both have to answer null, because the
     * controller turns null into a 404 and anything else into a page.
     */
    public function testByCodeAnswersForNothingElse(): void
    {
        self::assertNull($this->repository()->byCode('ZZ'), 'a code that is no airline');

        $unsold = $this->connection()->fetchOne(
            'SELECT code FROM airlines WHERE is_major = 0 LIMIT 1',
        );

        self::assertNotNull($unsold, 'the seed should hold an airline we do not sell');
        self::assertNull(
            $this->repository()->byCode((string) $unsold['code']),
            'an airline we do not sell should have no page',
        );
    }

    /**
     * sellable() and search(null, true) have to agree on who we sell.
     *
     * Two methods, two WHERE clauses, one fact. The directory is built from the
     * first and the search filters from the second, so a drift between them
     * would list an airline nobody can pick or hide one anybody can.
     */
    public function testSellableAgreesWithTheMajorOnlySearch(): void
    {
        $sellable = array_column($this->repository()->sellable(), 'code');
        $major = array_column($this->repository()->search(null, true), 'code');

        sort($sellable);
        sort($major);

        self::assertSame($major, $sellable);
        self::assertNotEmpty($sellable);
    }

    /**
     * Name-ordered, because the directory groups by first letter and trusts the
     * query to have sorted inside each group -- see View\Directory.
     */
    public function testSellableIsOrderedByName(): void
    {
        $names = array_column($this->repository()->sellable(), 'name');

        // The canonical ordering, not PHP's: MySQL's collation is not a
        // byte-wise sort, which is what the search test above already relies
        // on.
        $canonical = $this->connection()->fetchAll(
            'SELECT title FROM airlines WHERE is_major = 1 ORDER BY title ASC',
        );

        self::assertSame(array_column($canonical, 'title'), $names);
    }

    private function repository(): AirlineRepository
    {
        return new AirlineRepository($this->connection());
    }
}
