<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\GreatCircle;
use TripBuilder\Helper;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\RouteAddress;
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
        $slug = $this->slug();

        if ($slug === '') {
            $this->notFound();

            return;
        }

        try {
            $cities = new CityRepository($this->connection());

            // The names are what the address is spelled with, so resolving one
            // takes the whole set. It is the same map the airport board asks
            // for, and the same canonical names: MIN(city) over a city's
            // airports -- see CityRepository::names().
            $pair = RouteAddress::read($slug, RouteAddress::index($cities->names()));

            if ($pair === null) {
                $this->notFound();

                return;
            }

            [$fromCode, $toCode] = $pair;

            // A route to where you already are is not a route. Nothing
            // generates one, but the address can be typed, and the queries
            // would answer it with whatever intra-city hop exists -- Heathrow
            // to Gatwick as "flights from London to London".
            if ($fromCode === $toCode) {
                $this->notFound();

                return;
            }

            $from = $cities->byCode($fromCode);
            $to = $cities->byCode($toCode);

            if ($from === null || $to === null) {
                $this->notFound();

                return;
            }

            // One route, one address. read() is deliberately case-insensitive
            // and so finds this route under any capitalisation, which is two
            // spellings of one page unless one is sent to the other.
            $canonical = RouteAddress::path((string) $from['name'], (string) $to['name']);

            if ($this->request->path() !== $canonical) {
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
                'route' => $summary + [
                    'from' => $from,
                    'to' => $to,
                    // The line the map draws between them. Computed here
                    // because it is spherical trigonometry and a template is
                    // no place for it; see GreatCircle.
                    'path' => GreatCircle::segments(
                        (float) $from['latitude'],
                        (float) $from['longitude'],
                        (float) $to['latitude'],
                        (float) $to['longitude'],
                    ),
                ],
                'dates' => self::datesFor(
                    $routes->cheapestDates($origins, $destinations, CabinClass::Economy, self::CHEAPEST_DATES),
                    (string) $from['code'],
                    (string) $to['code'],
                ),
                'carriers' => self::addressableCarriers(
                    $routes->carriers($origins, $destinations, CabinClass::Economy),
                ),
                'ends' => self::endsFor($routes, $origins, $destinations),
                'reverse' => self::backAgain($routes, $from, $to, $destinations, $origins),
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
     * The same trip the other way, when there is one.
     *
     * The one link a route page was missing, and the most likely next thing a
     * reader wants: somebody reading about New York to London is usually
     * coming back. Nothing pointed there -- the footer shows one direction per
     * pair on purpose, and only 30 of the 163 sitemapped routes have their
     * reverse sitemapped too.
     *
     * It is a real check and not an assumption. The page exists only where the
     * pair can be flown nonstop, and there is no rule in this data that says a
     * pair flown one way is flown the other -- so this asks, and the link is
     * absent when the answer is no. Sampled at 40 of 40 before it was written,
     * which is why it is worth asking; measured at 0.8 to 3.3ms, which is why
     * asking is affordable.
     *
     * Linked whether or not the reverse is in the sitemap. The sitemap is
     * demand-driven because 42,578 route pages cannot all be listed; a link is
     * for the person reading, and withholding one to a page that works would
     * be tidiness at their expense.
     *
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @param list<string> $origins airports at the far end -- this route's destinations
     * @param list<string> $destinations airports at this end
     * @return array<string, mixed>|null
     */
    private static function backAgain(
        RouteRepository $routes,
        array $from,
        array $to,
        array $origins,
        array $destinations,
    ): ?array {
        $summary = $routes->summary($origins, $destinations, CabinClass::Economy);

        if ($summary === null) {
            return null;
        }

        return [
            'from' => (string) $to['name'],
            'to' => (string) $from['name'],
            'url' => RouteAddress::path((string) $to['name'], (string) $from['name']),
            'cheapest' => $summary['cheapest'],
        ];
    }

    /**
     * The slug out of /route/<slug>.
     */
    private function slug(): string
    {
        // As written, not folded -- the canonical check has to see the capitals
        // to be able to send them somewhere.
        return preg_match('#^/route/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }
}
