<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\RouteAddress;
use TripBuilder\SearchUrl;
use TripBuilder\View\TwigRenderer;

/**
 * One airport.
 *
 * The third page built on the `place` shell and the first with something the
 * other two have no version of: a schedule. A city or a country is a set of
 * places and reads the same whenever you arrive; an airport is a set of
 * departures, and what it is doing today is the thing somebody standing in it
 * wants to know.
 */
class AirportController extends AbstractController
{
    /** Neighbours to offer, and how far one may be. */
    private const int NEARBY_LIMIT = 6;

    /**
     * 300km, which is about a three-hour drive.
     *
     * The city page reaches a thousand, and it should: a nearby city is
     * somewhere else to fly from. A nearby airport is somewhere else to leave
     * from, and past this nobody drives it. See AirportRepository::nearby().
     */
    private const int NEARBY_MAX_KM = 300;

    /** Destinations in the fares strip. */
    private const int FARE_DESTINATIONS = 8;

    public function show(): void
    {
        $slug = $this->slug();
        $code = Helper::placeCode($slug, 3);

        if ($code === null) {
            $this->notFound();

            return;
        }

        try {
            $airports = new AirportRepository($this->connection());
            $airport = $airports->byCode($code);

            if ($airport === null) {
                $this->notFound();

                return;
            }

            $canonical = Helper::placeSlug((string) $airport['title'], (string) $airport['code']);

            if ($slug !== $canonical) {
                $this->bounce('/airport/' . $canonical, 301);

                return;
            }

            $now = self::localNow((float) $airport['timezone']);

            echo new TwigRenderer()->renderPage('airport/view.html.twig', [
                'breadcrumbs' => self::trailFor($airport),
                'airport' => $airport,
                'schedule' => $this->schedule($airports, $code, $now),
                'local_now' => $now->format('Y-m-d H:i:s'),
                'nearby' => self::addressable($airports->nearby($code, self::NEARBY_LIMIT, self::NEARBY_MAX_KM)),
                'fares' => $this->fares($airports, $code),
                'routes' => self::addressableRoutes(
                    new RouteRepository($this->connection())
                        ->departing((string) $airport['city_code'], RouteRepository::PLACE_LINKS),
                ),
            ]);
        } catch (Throwable $e) {
            error_log('Airport page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this airport. Please try again later.';
        }
    }

    /**
     * Today's board, out and in.
     *
     * "Today" is the airport's own date, which is the whole reason this is
     * computed rather than taken from date(). At 04:00 UTC the server has been
     * on the 8th for four hours and Honolulu is still on the 7th -- asked for
     * the server's date, Honolulu's board would show tomorrow.
     *
     * A direction with nothing in it is dropped rather than shown empty, the
     * same way the fares strip drops a tab, and the tabset stops drawing tabs
     * when only one is left.
     *
     * @return list<array<string, mixed>>
     */
    private function schedule(AirportRepository $airports, string $code, DateTimeImmutable $now): array
    {
        $date = $now->format('Y-m-d');

        $directions = [
            ['id' => 'out', 'label' => 'Departures', 'flights' => $airports->departures($code, $date)],
            ['id' => 'in', 'label' => 'Arrivals', 'flights' => $airports->arrivals($code, $date)],
        ];

        // The city each flight is to or from, by the name this app knows it by.
        // The query hands back the other airport's own value, which for Newark
        // Liberty is "Newark" where the city with the page is New York -- a
        // label no page of ours answers to, and a link to a redirect. Fetched
        // once and used for both directions.
        $names = new CityRepository($this->connection())->names();

        foreach ($directions as $i => $direction) {
            $directions[$i]['flights'] = array_map(
                static function (array $flight) use ($names): array {
                    $code = (string) $flight['other_city_code'];
                    $city = $names[$code] ?? (string) $flight['other_city'];

                    return [...$flight, 'other_city' => $city]
                        // Built here rather than in the template for the reason
                        // it is everywhere else: one place knows where a city
                        // lives.
                        + ['other_url' => '/city/' . Helper::placeSlug($city, $code)];
                },
                $direction['flights'],
            );
        }

        return array_values(array_filter(
            $directions,
            static fn(array $direction): bool => $direction['flights'] !== [],
        ));
    }

    /**
     * The cheapest direct fare out of here, per place it can reach.
     *
     * One tab, not two. A city page splits home from abroad because it is
     * asking where somebody is flying in from; an airport is where they are
     * standing, and the only question left is where it is cheap to go.
     *
     * @return list<array<string, mixed>>
     */
    private function fares(AirportRepository $airports, string $code): array
    {
        // Every sellable airport, which for this page is every airport there
        // is: measured, every one of the 686,394 flights lands at one of these
        // 254 and none lands anywhere else. So the filter excludes nothing and
        // costs about 15ms of the 48 -- kept anyway, because "anywhere" as a
        // special case of an empty list is a sentinel that turns a bug into
        // fares to everywhere instead of no fares at all.
        $destinations = array_column($airports->enabled(true), 'code');

        $found = new FlightRepository($this->connection())->cheapestPerDestinationCity(
            [$code],
            $destinations,
            CabinClass::Economy,
        );

        if ($found === []) {
            return [];
        }

        return [[
            'id' => 'out',
            'label' => 'Cheapest destinations',
            'fares' => array_map(
                static fn(array $fare): array => $fare + [
                    'search' => new SearchUrl(
                        from: $code,
                        to: (string) $fare['to_city_code'],
                        depart: substr((string) $fare['departure_time'], 0, 10),
                        return: null,
                    )->path(),
                ],
                // The query answers for every city this airport reaches -- 218
                // of them from Montreal -- and returns them cheapest first. The
                // strip shows the front of that list; the rest is a search.
                array_slice($found, 0, self::FARE_DESTINATIONS),
            ),
        ]];
    }

    /**
     * Home, the country, the city, then the airport.
     *
     * The first four-level trail on the site, and the first one that is a real
     * hierarchy the whole way down: Heathrow is in London, which is in the
     * United Kingdom. The city page's trail stops at three because a city's
     * parent is its country; this adds the level below it.
     *
     * @param array<string, mixed> $airport
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    private static function trailFor(array $airport): array
    {
        return [
            ['label' => (string) Config::get('breadcrumbs.home', 'Home'), 'url' => '/', 'current' => false],
            [
                'label' => (string) $airport['country'],
                'url' => '/country/' . Helper::placeSlug(
                    (string) $airport['country'],
                    (string) $airport['country_code'],
                ),
                'current' => false,
            ],
            [
                'label' => (string) $airport['city'],
                'url' => '/city/' . Helper::placeSlug((string) $airport['city'], (string) $airport['city_code']),
                'current' => false,
            ],
            ['label' => (string) $airport['title'], 'url' => null, 'current' => true],
        ];
    }

    /**
     * Give each airport the address it is reached at.
     *
     * @param list<array<string, mixed>> $airports
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $airports): array
    {
        return array_map(
            static fn(array $airport): array => $airport + [
                'url' => Helper::airportUrl((string) $airport['title'], (string) $airport['code']),
            ],
            $airports,
        );
    }

    /**
     * Give each route the address its page is at.
     *
     * The city's routes and not the airport's, because a route is a pair of
     * cities: London has six airports we sell and one set of routes, so all six
     * pages carry it -- the same way they all carry the fares strip, which is
     * also counted from the city.
     *
     * Leaving and not arriving, because this page is called "Flights from
     * Heathrow". The routes into a city are listed on the city's own page,
     * which is the one called "Flights to".
     *
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private static function addressableRoutes(array $routes): array
    {
        return array_map(
            static fn(array $route): array => $route + [
                'url' => RouteAddress::path((string) $route['from_name'], (string) $route['to_name']),
            ],
            $routes,
        );
    }

    /**
     * What time it is where the airport is.
     *
     * Built from UTC and the stored offset rather than from date(), because
     * date() answers in whatever zone the server is set to -- UTC on the CLI
     * here and Eastern in MySQL, neither of which is the airport's. The offset
     * is a decimal because eight of these airports are on a half hour.
     *
     * The offset is fixed, so this does not follow daylight saving. Neither
     * does anything else in this app: `flights.departure_time` is already
     * stored in the airport's local time, and this is the clock those times are
     * to be read against.
     */
    private static function localNow(float $offsetHours): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))
            ->modify(sprintf('%+d minutes', (int) round($offsetHours * 60)));
    }

    /**
     * The slug out of /airport/<slug>.
     */
    private function slug(): string
    {
        return preg_match('#^/airport/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }
}
