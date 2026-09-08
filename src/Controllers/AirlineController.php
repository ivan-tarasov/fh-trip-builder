<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\SearchUrl;
use TripBuilder\View\TwigRenderer;

/**
 * One airline.
 *
 * The fourth page on the `place` shell, and the one whose facts are mostly not
 * counted. A city, a country and an airport are each described by the flights
 * around them; an airline is described by things this database was seeded with
 * -- where it is based, what it flies out of, how to telephone it -- and the
 * flights table has less to add than it looks like it should.
 *
 * Fare brands are still left out: all five, on all 105, so a table of them
 * would be the same table on every page.
 *
 * A fleet was left out for the same reason and has since been put back, on a
 * second look that changed the answer. The *set* of aircraft says nothing --
 * 104 of the 105 airlines fly all 28 types in this data -- but the seeder
 * picks an aircraft by whether its range covers the leg, so the types an
 * airline flies *most* restate how long its legs are, and that differs
 * sharply: easyJet is 44% widebody with turboprops at the top, Qantas 86% with
 * A350s and A380s. See AirlineRepository::aircraft(), which also says why the
 * block counts flights and never airframes.
 */
class AirlineController extends AbstractController
{
    /** Destinations in the fares strip. */
    private const int FARE_DESTINATIONS = 8;

    /**
     * Aircraft types listed, of the 28 in this data.
     *
     * Ten, because the tail is noise: every airline flies all 28, and past the
     * tenth the counts sit close enough together that the order between them
     * says nothing. The ten that lead are the ones its route lengths chose.
     */
    private const int AIRCRAFT_TYPES = 10;

    /** Other airlines offered at the foot of the page. */
    private const int PEER_AIRLINES = 6;

    public function show(): void
    {
        $slug = $this->slug();
        $code = Helper::placeCode($slug, 2);

        if ($code === null) {
            $this->notFound();

            return;
        }

        try {
            $airlines = new AirlineRepository($this->connection());
            $airline = $airlines->byCode($code);

            if ($airline === null) {
                $this->notFound();

                return;
            }

            // One airline, one address -- the same rule the other three place
            // pages follow. 301, because this is how the page is spelled.
            $canonical = Helper::placeSlug((string) $airline['name'], (string) $airline['code']);

            if ($slug !== $canonical) {
                $this->bounce(Helper::airlineUrl((string) $airline['name'], (string) $airline['code']), 301);

                return;
            }

            $hubs = AirlineRepository::hubCodes((string) $airline['hubs']);
            $reached = $this->reached($code, $hubs);

            echo new TwigRenderer()->renderPage('airline/view.html.twig', [
                'breadcrumbs' => self::trailFor($airline),
                'airline' => $airline
                    + [
                        'hub_count' => count($hubs),
                        'destinations' => count($reached),
                        // The one fact in the masthead that sends a reader
                        // somewhere else, so it is built where the other
                        // addresses on this page are.
                        'country_url' => $airline['country'] === null ? null : '/country/' . Helper::placeSlug(
                            (string) $airline['country'],
                            (string) $airline['country_code'],
                        ),
                    ]
                    + ($airlines->network($code, $hubs) ?? []),
                'hubs' => self::addressable(new AirportRepository($this->connection())->byCodes($hubs)),
                'fares' => self::strip($reached),
                'aircraft' => $airlines->aircraft($code, self::AIRCRAFT_TYPES),
                'peers' => self::addressableAirlines($airlines->peers($code, $hubs, self::PEER_AIRLINES)),
                // Both counted blocks look this far ahead, and both say so --
                // a count with no period is not a count.
                'window_days' => AirlineRepository::WINDOW_DAYS,
            ]);
        } catch (Throwable $e) {
            error_log('Airline page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this airline. Please try again later.';
        }
    }

    /**
     * The cheapest seat this airline sells out of its own hubs, per place it
     * reaches.
     *
     * Filtered to the carrier, which is the whole point: an unfiltered query
     * from Heathrow and Gatwick would fill British Airways' page with Ryanair.
     *
     * From its hubs and not from everywhere it flies, because the origins have
     * to be named for the query to seek rather than scan -- see
     * FlightRepository::cheapestPerDestinationCity(). A hub is also the honest
     * origin for a page about the airline: it is where its network starts.
     *
     * @param list<string> $hubs
     * @return list<array<string, mixed>>
     */
    private function reached(string $code, array $hubs): array
    {
        $airports = new AirportRepository($this->connection());

        return new FlightRepository($this->connection())->cheapestPerDestinationCity(
            $hubs,
            array_column($airports->enabled(true), 'code'),
            CabinClass::Economy,
            $code,
        );
    }

    /**
     * The front of that list, as the fares block wants it.
     *
     * One tab. A city page splits home from abroad because it is asking where
     * somebody is flying in from; an airline's hubs are where its flights
     * start, and the only question left is where it is cheap to go.
     *
     * The strip is sliced where the airport page's is and for the same reason:
     * the query answers for every city the hubs reach -- 231 of them for Delta
     * -- and the rest of that list is a search, not a row of cards.
     *
     * @param list<array<string, mixed>> $reached
     * @return list<array<string, mixed>>
     */
    private static function strip(array $reached): array
    {
        if ($reached === []) {
            return [];
        }

        return [[
            'id' => 'out',
            'label' => 'Cheapest destinations',
            'fares' => array_map(
                static fn(array $fare): array => $fare + [
                    'search' => new SearchUrl(
                        from: (string) $fare['from_city_code'],
                        to: (string) $fare['to_city_code'],
                        depart: substr((string) $fare['departure_time'], 0, 10),
                        return: null,
                    )->path(),
                ],
                array_slice($reached, 0, self::FARE_DESTINATIONS),
            ),
        ]];
    }

    /**
     * Home, then the list, then the airline.
     *
     * The country it is based in is a fact about it and not its parent -- Air
     * Canada is not inside Canada the way Montreal is -- so the trail goes
     * through the directory that lists every airline, which is the shape the
     * country page uses for the same reason.
     *
     * @param array<string, mixed> $airline
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    private static function trailFor(array $airline): array
    {
        /** @var array<string, string> $pages */
        $pages = Config::get('breadcrumbs.pages', []);

        return [
            ['label' => (string) Config::get('breadcrumbs.home', 'Home'), 'url' => '/', 'current' => false],
            ['label' => $pages['/airlines'] ?? 'Airlines', 'url' => '/airlines', 'current' => false],
            ['label' => (string) $airline['name'], 'url' => null, 'current' => true],
        ];
    }

    /**
     * Give each of the other airlines the address of its own page.
     *
     * @param list<array<string, mixed>> $airlines
     * @return list<array<string, mixed>>
     */
    private static function addressableAirlines(array $airlines): array
    {
        return array_map(
            static fn(array $airline): array => $airline + [
                'url' => Helper::airlineUrl((string) $airline['name'], (string) $airline['code']),
            ],
            $airlines,
        );
    }

    /**
     * Give each hub the address of its own page.
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
     * The slug out of /airline/<slug>.
     */
    private function slug(): string
    {
        // As written, not folded -- the canonical check has to see the capitals
        // to be able to send them somewhere.
        return preg_match('#^/airline/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }
}
