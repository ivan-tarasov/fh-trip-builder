<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\RouteRepository;
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
 */
final class RouteRepositoryTest extends IntegrationTestCase
{
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
