<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\SearchUrl;
use TripBuilder\View\TwigRenderer;

/**
 * One route, city to city.
 *
 * The fifth page on the `place` shell and the first that is a pair rather than
 * a record. That changes two things the other four could take for granted.
 *
 * There is no directory. 42,578 ordered city pairs in this data have a direct
 * flight, so there is no page that could list them and nothing to group by a
 * first letter -- which is also why the trail below goes through the origin
 * city rather than through an index that cannot exist.
 *
 * And the page has to decide whether it exists. A city we sell has a page
 * because the city is in the table; a route has one only if you can fly it
 * nonstop, which is a question, and RouteRepository::summary() answering null
 * is how it comes back as a 404 rather than as a page reading "no flights".
 *
 * Nonstop is the scope of the page and not a shortcut. Every figure it prints
 * belongs to a single flight -- the time in the air, the distance, the airlines
 * flying it, the cheapest date -- and none of them survives a connection: a
 * London to Sydney page would have two distances and no flight time. So a pair
 * with only connecting itineraries has no page here even though the search
 * sells it. Measured against real demand, that costs five of the 163 city pairs
 * anybody has searched for, all of them the long way round the world.
 */
class RouteController extends AbstractController
{
    /** Dates in the cheapest-dates strip. */
    private const int CHEAPEST_DATES = 8;

    public function show(): void
    {
        [$fromSlug, $toSlug] = $this->slugs();
        $fromCode = Helper::placeCode($fromSlug, 3);
        $toCode = Helper::placeCode($toSlug, 3);

        // A route to where you already are is not a route. Nothing generates
        // one, but the address can be typed, and the queries would answer it
        // with whatever intra-city hop exists -- Heathrow to Gatwick as
        // "flights from London to London".
        if ($fromCode === null || $toCode === null || $fromCode === $toCode) {
            $this->notFound();

            return;
        }

        try {
            $cities = new CityRepository($this->connection());
            $from = $cities->byCode($fromCode);
            $to = $cities->byCode($toCode);

            if ($from === null || $to === null) {
                $this->notFound();

                return;
            }

            $canonical = Helper::routeUrl(
                (string) $from['name'],
                (string) $from['code'],
                (string) $to['name'],
                (string) $to['code'],
            );

            if ('/route/' . $fromSlug . '/' . $toSlug !== $canonical) {
                $this->bounce($canonical, 301);

                return;
            }

            $origins = array_column($cities->airports($fromCode), 'code');
            $destinations = array_column($cities->airports($toCode), 'code');

            $routes = new RouteRepository($this->connection());
            $summary = $routes->summary($origins, $destinations, CabinClass::Economy);

            if ($summary === null) {
                $this->notFound();

                return;
            }

            echo new TwigRenderer()->renderPage('route/view.html.twig', [
                'breadcrumbs' => self::trailFor($from, $to),
                'route' => $summary + ['from' => $from, 'to' => $to],
                'dates' => self::datesFor(
                    $routes->cheapestDates($origins, $destinations, CabinClass::Economy, self::CHEAPEST_DATES),
                    (string) $from['code'],
                    (string) $to['code'],
                ),
                'carriers' => self::addressableCarriers(
                    $routes->carriers($origins, $destinations, CabinClass::Economy),
                ),
                'ends' => self::endsFor($routes, $origins, $destinations),
            ]);
        } catch (Throwable $e) {
            error_log('Route page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this route. Please try again later.';
        }
    }

    /**
     * Each cheap date, with the search that would sell it.
     *
     * The search is by city and not by the airport the fare happens to be on.
     * A card reading "JFK to Stansted" that opens a search for JFK to Stansted
     * hides the eight other pairs on the route, and the cheapest answer for the
     * day is what the visitor came for.
     *
     * @param list<array<string, mixed>> $dates
     * @return list<array<string, mixed>>
     */
    private static function datesFor(array $dates, string $fromCode, string $toCode): array
    {
        return array_map(
            static fn(array $date): array => $date + [
                'search' => new SearchUrl(
                    from: $fromCode,
                    to: $toCode,
                    depart: (string) $date['depart_date'],
                    return: null,
                )->path(),
            ],
            $dates,
        );
    }

    /**
     * Who flies it, each with its own page's address.
     *
     * @param list<array<string, mixed>> $carriers
     * @return list<array<string, mixed>>
     */
    private static function addressableCarriers(array $carriers): array
    {
        return array_map(
            static fn(array $carrier): array => $carrier + [
                'url' => Helper::airlineUrl((string) $carrier['name'], (string) $carrier['code']),
            ],
            $carriers,
        );
    }

    /**
     * Where you leave from and where you land, as two tabs.
     *
     * Tabs rather than two headings, because it is the shape the airport
     * board already uses for the same question asked in two directions -- and
     * because on most routes each side holds one airport, where two standing
     * headings over one chip each would be more furniture than content.
     *
     * @param list<string> $origins
     * @param list<string> $destinations
     * @return list<array<string, mixed>>
     */
    private static function endsFor(RouteRepository $routes, array $origins, array $destinations): array
    {
        $sides = [
            ['id' => 'out', 'label' => 'Leaves from', 'arriving' => false],
            ['id' => 'in', 'label' => 'Lands at', 'arriving' => true],
        ];

        foreach ($sides as $i => $side) {
            $sides[$i]['airports'] = array_map(
                static fn(array $airport): array => $airport + [
                    'url' => Helper::airportUrl((string) $airport['title'], (string) $airport['code']),
                ],
                $routes->ends($origins, $destinations, CabinClass::Economy, (bool) $side['arriving']),
            );
        }

        return array_values(array_filter(
            $sides,
            static fn(array $side): bool => $side['airports'] !== [],
        ));
    }

    /**
     * Home, the city you leave, then the route.
     *
     * The origin city and not a directory, because there is no directory of
     * routes and there could not be one. It is also the honest ancestor: the
     * question behind this page is "where can I go from here", and the city
     * page is where the rest of that answer is.
     *
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    private static function trailFor(array $from, array $to): array
    {
        return [
            ['label' => (string) Config::get('breadcrumbs.home', 'Home'), 'url' => '/', 'current' => false],
            [
                'label' => (string) $from['name'],
                'url' => '/city/' . Helper::placeSlug((string) $from['name'], (string) $from['code']),
                'current' => false,
            ],
            [
                'label' => $from['name'] . ' — ' . $to['name'],
                'url' => null,
                'current' => true,
            ],
        ];
    }

    /**
     * The two slugs out of /route/<from>/<to>.
     *
     * @return array{string, string}
     */
    private function slugs(): array
    {
        // As written, not folded -- the canonical check has to see the capitals
        // to be able to send them somewhere.
        return preg_match(
            '#^/route/([A-Za-z0-9-]+)/([A-Za-z0-9-]+)$#',
            $this->request->path(),
            $match,
        ) === 1 ? [$match[1], $match[2]] : ['', ''];
    }
}
