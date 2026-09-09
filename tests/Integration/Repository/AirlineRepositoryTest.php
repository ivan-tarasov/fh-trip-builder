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

    /**
     * The two figures the page's tiles are built from.
     *
     * Both are shares or rates rather than totals, so both have to be inside
     * their own bounds whatever the data does.
     */
    public function testNetworkReportsARateAndAShare(): void
    {
        $network = $this->repository()->network(...$this->anAirlineWithHubs());

        self::assertNotNull($network);
        self::assertGreaterThanOrEqual(1, $network['per_day']);
        self::assertGreaterThanOrEqual(0, $network['widebody_share']);
        self::assertLessThanOrEqual(100, $network['widebody_share']);
    }

    /**
     * An airline with nowhere to fly from has no network to describe.
     */
    public function testNetworkIsNullWithoutHubs(): void
    {
        self::assertNull($this->repository()->network('AC', []));
        self::assertSame([], $this->repository()->peers('AC', [], 6));
    }

    public function testAircraftIsCappedAndOrderedByFlights(): void
    {
        $types = $this->repository()->aircraft($this->anAirlineWithHubs()[0], 5);

        self::assertNotEmpty($types);
        self::assertLessThanOrEqual(5, count($types));

        $flights = array_map(static fn(array $t): int => (int) $t['flights'], $types);
        $sorted = $flights;
        rsort($sorted);

        self::assertSame($sorted, $flights);
    }

    /**
     * The claim the block rests on: the order is not the same list every time.
     *
     * Every airline in this data flies all 28 types, so a fleet as a *set*
     * would be one sentence repeated 105 times. What makes it worth drawing is
     * that the seeder picks an aircraft by whether its range covers the leg, so
     * the order follows an airline's route lengths -- turboprops at the top for
     * a short-haul carrier, widebodies for a long-haul one. Flatten that and
     * this fails.
     */
    public function testTheAircraftOrderDiffersBetweenAirlines(): void
    {
        $seen = [];

        foreach (array_slice($this->repository()->sellable(), 0, 12) as $airline) {
            $top = $this->repository()->aircraft((string) $airline['code'], 3);

            if ($top !== []) {
                $seen[] = implode('|', array_column($top, 'title'));
            }
        }

        self::assertNotEmpty($seen);
        self::assertGreaterThan(1, count(array_unique($seen)), 'every airline led with the same aircraft');
    }

    /**
     * Never the airline whose page it is, and never one we do not sell.
     */
    public function testPeersExcludeTheAirlineItselfAndAnythingUnsold(): void
    {
        [$code, $hubs] = $this->anAirlineWithHubs();

        $peers = $this->repository()->peers($code, $hubs, 6);

        self::assertNotEmpty($peers);
        self::assertLessThanOrEqual(6, count($peers));

        $sellable = array_column($this->repository()->sellable(), 'code');

        foreach ($peers as $peer) {
            self::assertNotSame($code, $peer['code']);
            self::assertContains($peer['code'], $sellable);
        }
    }

    /**
     * An airline this suite can actually assert about: hubs, and flights out of
     * them inside the window the counted methods look at.
     *
     * Both halves are checked rather than assumed. byCode(), not sellable(),
     * because the directory list carries no hubs. And network() rather than
     * stopping at the first airline that has any, because how much flying
     * there is in a fortnight depends on how the database was filled -- CI
     * generates 200,000 flights where this developer's holds 683,760, so the
     * quietest carriers thin out to nothing there and the first name in the
     * alphabet is no guarantee of anything.
     *
     * @return array{string, list<string>}
     */
    private function anAirlineWithHubs(): array
    {
        foreach ($this->repository()->sellable() as $airline) {
            $code = (string) $airline['code'];
            $row = $this->repository()->byCode($code);
            $hubs = AirlineRepository::hubCodes((string) ($row['hubs'] ?? ''));

            if ($hubs !== [] && $this->repository()->network($code, $hubs) !== null) {
                return [$code, $hubs];
            }
        }

        self::fail('No sellable airline has a hub it flies out of in the next '
            . AirlineRepository::WINDOW_DAYS . ' days');
    }

    private function repository(): AirlineRepository
    {
        return new AirlineRepository($this->connection());
    }
}
