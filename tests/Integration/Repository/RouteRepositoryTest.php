<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The two lists that give the route family a way in.
 *
 * A route page is not a row anywhere, so nothing lists routes except these
 * queries. Before departing() and arriving() existed, only the footer's five
 * were linked and the rest were pages the sitemap named and nothing pointed
 * at.
 *
 * The claim worth testing is the coverage one, and it is a claim about the data
 * and not about the code: twelve links per direction is enough that no route
 * page is left unlinked. That is true of this table and could stop being true
 * of another, which is why it is asserted here rather than written down in a
 * comment and hoped for.
 *
 * Which route pages exist comes from the `search` table, and that is the trap
 * these tests fell into first. A developer's database has been used, so it has
 * hundreds of searched pairs; CI's has been installed, and `app:install` seeds
 * six CSVs of which none is searches. Written against the first, every
 * assertion here passed locally and every one failed the first time CI ran
 * them -- on nothing more interesting than an empty table.
 *
 * So the demand is the test's own now. setUp() records a handful of searches
 * for pairs it has checked can be flown, tearDown() removes them, and the
 * assertions hold whether the database underneath has been used or only
 * installed. Same write-then-clean-up shape SearchRepositoryTest uses.
 */
final class RouteRepositoryTest extends IntegrationTestCase
{
    /**
     * Enough searched pairs for one origin to have a list worth cutting.
     *
     * Four, because testTheLimitIsHonoured asks for three of them and a limit
     * that cannot bite proves nothing.
     */
    private const int SEEDED_ROUTES = 4;

    /** @var list<string> hashes written by setUp, for tearDown to take back */
    private array $seeded = [];

    protected function setUp(): void
    {
        $searches = new SearchRepository($this->connection());
        $pairs = $this->flyablePairs();

        // Loud, and early. The failure this replaces was six assertions all
        // saying "array is not empty", which told nobody that the table they
        // depend on had never been written to.
        self::assertNotEmpty(
            $pairs,
            'No city flies to ' . self::SEEDED_ROUTES . ' others in the sampled flights, so this'
            . ' suite has no route pages to assert about. Check the flights table is populated.',
        );

        foreach ($pairs as [$fromCode, $fromName, $toCode, $toName]) {
            // char(32), and the column is the primary key. Prefixed so a run
            // killed between setUp and tearDown leaves something recognisable
            // behind; tearDown deletes by the exact hash either way.
            $hash = 'rr' . substr(md5(uniqid('', true)), 0, 30);

            $searches->record(
                $hash,
                $fromCode,
                $fromName,
                $toCode,
                $toName,
                date('Y-m-d', strtotime('+7 days')),
                null,
                'oneway',
                CabinClass::Economy,
            );

            $this->seeded[] = $hash;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->seeded as $hash) {
            $this->connection()->execute('DELETE FROM search WHERE hash = ?', [$hash]);
        }

        $this->seeded = [];
    }

    /**
     * City pairs one city flies to nonstop, taken from the flights themselves.
     *
     * Off a bounded slice of the table rather than the whole of it: what is
     * wanted is any origin with a few destinations, not the busiest one, and
     * grouping 683,760 rows to find it would cost more than every assertion
     * here put together.
     *
     * @return list<array{string, string, string, string}>
     */
    private function flyablePairs(): array
    {
        // Widening rather than one big sample: 4,000 departures is enough on
        // any database anybody runs this against, and the second pass is there
        // so a thin one fails slowly rather than wrongly.
        foreach ([4000, 40000] as $sample) {
            $found = $this->originWithSeveralDestinations($sample);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @return list<array{string, string, string, string}>
     */
    private function originWithSeveralDestinations(int $sample): array
    {
        $rows = $this->connection()->fetchAll(
            'SELECT o.city_code AS from_code, MIN(o.city) AS from_name,'
            . ' d.city_code AS to_code, MIN(d.city) AS to_name'
            . ' FROM ('
            . '  SELECT departure_airport, arrival_airport FROM flights'
            . '  WHERE departure_time >= NOW() LIMIT ' . $sample
            . ' ) f'
            . ' JOIN airports o ON o.code = f.departure_airport AND o.enabled = 1 AND o.is_major = 1'
            . ' JOIN airports d ON d.code = f.arrival_airport AND d.enabled = 1 AND d.is_major = 1'
            . ' WHERE o.city_code <> d.city_code'
            . ' GROUP BY o.city_code, d.city_code',
        );

        $byOrigin = [];

        foreach ($rows as $row) {
            $byOrigin[(string) $row['from_code']][] = [
                (string) $row['from_code'],
                (string) $row['from_name'],
                (string) $row['to_code'],
                (string) $row['to_name'],
            ];
        }

        foreach ($byOrigin as $pairs) {
            if (count($pairs) >= self::SEEDED_ROUTES) {
                return array_slice($pairs, 0, self::SEEDED_ROUTES);
            }
        }

        return [];
    }

    private function repository(): RouteRepository
    {
        return new RouteRepository($this->connection());
    }

    public function testDepartingNamesTheGivenCityAtTheOriginEnd(): void
    {
        $city = $this->busiest('from_code');

        $routes = $this->repository()->departing($city, RouteRepository::PLACE_LINKS);

        self::assertNotEmpty($routes);

        foreach ($routes as $route) {
            self::assertSame($city, $route['from_code']);
            self::assertNotSame($city, $route['to_code']);
        }
    }

    public function testArrivingNamesTheGivenCityAtTheDestinationEnd(): void
    {
        $city = $this->busiest('to_code');

        $routes = $this->repository()->arriving($city, RouteRepository::PLACE_LINKS);

        self::assertNotEmpty($routes);

        foreach ($routes as $route) {
            self::assertSame($city, $route['to_code']);
            self::assertNotSame($city, $route['from_code']);
        }
    }

    public function testTheLimitIsHonoured(): void
    {
        $routes = $this->repository()->departing($this->busiest('from_code'), 3);

        self::assertCount(3, $routes);
    }

    public function testAnUnknownCityHasNoRoutes(): void
    {
        self::assertSame([], $this->repository()->departing('ZZZ', RouteRepository::PLACE_LINKS));
        self::assertSame([], $this->repository()->arriving('ZZZ', RouteRepository::PLACE_LINKS));
    }

    /**
     * Every route offered is a route with a page.
     *
     * The same filter searched() applies, because a search is recorded for any
     * pair anybody asked about and only the ones that can be flown nonstop
     * have a page. A chip here for a pair that has none would be a link to a
     * 404 on every city and airport page that carries the block.
     */
    public function testEveryRouteOfferedHasAPage(): void
    {
        $pages = $this->keys($this->repository()->searched());
        $city = $this->busiest('from_code');

        $offered = [
            ...$this->repository()->departing($city, RouteRepository::PLACE_LINKS),
            ...$this->repository()->arriving($city, RouteRepository::PLACE_LINKS),
        ];

        self::assertNotEmpty($offered);

        foreach ($this->keys($offered) as $key) {
            self::assertContains($key, $pages);
        }
    }

    /**
     * The same question twice gets the same answer.
     *
     * Most of these pairs have been searched once, so a list cut at twelve is
     * cutting a run of ties -- and without a tiebreak it would hold whichever
     * twelve MySQL happened to return, so the page's links would drift between
     * requests for no reason a reader could see.
     */
    public function testTheOrderIsStable(): void
    {
        $city = $this->busiest('to_code');

        self::assertSame(
            $this->keys($this->repository()->arriving($city, RouteRepository::PLACE_LINKS)),
            $this->keys($this->repository()->arriving($city, RouteRepository::PLACE_LINKS)),
        );
    }

    /**
     * PLACE_LINKS is enough to leave no route page unlinked.
     *
     * Walks every city that is at either end of a route and collects what its
     * pages would offer, which is what a crawler coming through the city and
     * airport pages would find. Ten leaves one route unlinked in this data and
     * eight leaves four, so this is the assertion that says why the number is
     * twelve -- and the one that will say so again when demand has moved the
     * family.
     */
    public function testTwelveLinksPerDirectionCoverEveryRoutePage(): void
    {
        $routes = $this->repository()->searched();

        self::assertNotEmpty($routes);

        $cities = [];

        foreach ($routes as $route) {
            $cities[(string) $route['from_code']] = true;
            $cities[(string) $route['to_code']] = true;
        }

        $linked = [];

        foreach (array_keys($cities) as $city) {
            $linked = [
                ...$linked,
                ...$this->keys($this->repository()->departing($city, RouteRepository::PLACE_LINKS)),
                ...$this->keys($this->repository()->arriving($city, RouteRepository::PLACE_LINKS)),
            ];
        }

        self::assertSame([], array_diff($this->keys($routes), $linked));
    }

    /**
     * The city at one end of the most-searched pair, which is the busiest in
     * that direction and so the one long enough to be cut by a limit.
     */
    private function busiest(string $end): string
    {
        $routes = $this->repository()->searched();

        self::assertNotEmpty($routes);

        return (string) $routes[0][$end];
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return list<string>
     */
    private function keys(array $routes): array
    {
        return array_map(
            static fn(array $route): string => $route['from_code'] . '-' . $route['to_code'],
            $routes,
        );
    }
}
