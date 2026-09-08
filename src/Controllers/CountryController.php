<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\SearchUrl;
use TripBuilder\View\Directory;
use TripBuilder\View\TwigRenderer;

/**
 * A country, and the list of them.
 *
 * Deliberately the same shape as CityController, down to the canonical
 * redirect and the two fare tabs, because the pages are read the same way. What
 * a country does not get is an airlines block: 90% of the 105 airlines in this
 * database serve any given country we sell to, so "airlines flying to Canada"
 * is a list of nearly every airline and tells a reader nothing.
 */
class CountryController extends AbstractController
{
    /** Origin cities per tab. Each is one row in the fares strip. */
    private const int FARE_ORIGINS = 8;

    /**
     * Every country, so every country page has a way in.
     *
     * The same directory the cities use. 93 rows rather than 231, and the same
     * problem underneath: before /cities existed sixty city pages had no
     * inbound link at all, and a country page nothing links to is a page
     * nothing finds.
     */
    public function index(): void
    {
        try {
            echo new TwigRenderer()->renderPage('country/index.html.twig', [
                'groups' => Directory::byLetter(
                    self::addressable(new CountryRepository($this->connection())->sellable()),
                ),
            ]);
        } catch (Throwable $e) {
            error_log('Countries page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading countries. Please try again later.';
        }
    }

    public function show(): void
    {
        $slug = $this->slug();
        $code = self::codeFrom($slug);

        if ($code === null) {
            $this->notFound();

            return;
        }

        try {
            $countries = new CountryRepository($this->connection());
            $country = $countries->byCode($code);

            if ($country === null) {
                $this->notFound();

                return;
            }

            // One country, one address -- the same rule the city page follows
            // and for the same reason. 301, because this is how the page is
            // spelled and not where it happens to be today.
            $canonical = Helper::placeSlug((string) $country['name'], (string) $country['code']);

            if ($slug !== $canonical) {
                $this->bounce('/country/' . $canonical, 301);

                return;
            }

            $cities = new CityRepository($this->connection());
            $airports = $countries->airports($code);

            echo new TwigRenderer()->renderPage('country/view.html.twig', [
                'breadcrumbs' => self::trailFor($country),
                'country' => $country,
                'country_cities' => self::cityAddresses($cities->inCountry($code)),
                'country_airports' => $airports,
                'fares' => $this->fares($cities, $country, array_column($airports, 'code')),
            ]);
        } catch (Throwable $e) {
            error_log('Country page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this country. Please try again later.';
        }
    }

    /**
     * The cheapest way in, from inside the country and from outside it.
     *
     * The city page splits its tabs the same way and the split does more work
     * here: a Toronto-Montreal hop at $42 and a Chicago-Vancouver at $253 are
     * both "cheap flights to Canada", and sorted together the domestic ones
     * take every row.
     *
     * One row per arrival city rather than per origin, which is the one real
     * difference from the city page: what varies down a country's strip is
     * where in the country you land.
     *
     * @param array<string, mixed> $country
     * @param list<string> $airports
     * @return list<array<string, mixed>>
     */
    private function fares(CityRepository $cities, array $country, array $airports): array
    {
        $code = (string) $country['code'];
        $flights = new FlightRepository($this->connection());

        $tabs = [];

        foreach ([false, true] as $domestic) {
            $origins = $cities->busiestOriginAirportsByCountry($code, $domestic, self::FARE_ORIGINS);
            $found = $flights->cheapestPerDestinationCity($origins, $airports, CabinClass::Economy);

            if ($found === []) {
                continue;
            }

            $tabs[] = [
                'id' => $domestic ? 'home' : 'away',
                'label' => $domestic ? 'Within ' . $country['name'] : 'From abroad',
                'fares' => array_map(
                    static fn(array $fare): array => $fare + [
                        'search' => new SearchUrl(
                            from: (string) $fare['from_city_code'],
                            to: (string) $fare['to_city_code'],
                            depart: substr((string) $fare['departure_time'], 0, 10),
                            return: null,
                        )->path(),
                    ],
                    $found,
                ),
            ];
        }

        return $tabs;
    }

    /**
     * Home, then the list, then the country.
     *
     * Not the same shape as the city trail above it, on purpose. A city has a
     * real parent -- the country it is in -- and its trail says so. A country
     * has no geographic parent, so the honest one is the directory that lists
     * every country, which is also the only page that links to all of them.
     *
     * @param array<string, mixed> $country
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    private static function trailFor(array $country): array
    {
        /** @var array<string, string> $pages */
        $pages = Config::get('breadcrumbs.pages', []);

        return [
            ['label' => (string) Config::get('breadcrumbs.home', 'Home'), 'url' => '/', 'current' => false],
            ['label' => $pages['/countries'] ?? 'Countries', 'url' => '/countries', 'current' => false],
            ['label' => (string) $country['name'], 'url' => null, 'current' => true],
        ];
    }

    /**
     * Give each country the address it is reached at.
     *
     * @param list<array<string, mixed>> $countries
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $countries): array
    {
        return array_map(
            static fn(array $country): array => $country + [
                'url' => '/country/' . Helper::placeSlug((string) $country['name'], (string) $country['code']),
            ],
            $countries,
        );
    }

    /**
     * The cities of this country, each with its own page's address.
     *
     * A country page's job is to hand a reader on to one of its cities, so the
     * chips are links and not labels.
     *
     * @param list<array<string, mixed>> $cities
     * @return list<array<string, mixed>>
     */
    private static function cityAddresses(array $cities): array
    {
        return array_map(
            static fn(array $city): array => $city + [
                'url' => '/city/' . Helper::placeSlug((string) $city['name'], (string) $city['code']),
            ],
            $cities,
        );
    }

    /**
     * The slug out of /country/<slug>.
     */
    private function slug(): string
    {
        // As written, not folded -- the canonical check has to see the capitals
        // to be able to send them somewhere.
        return preg_match('#^/country/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }

    /** Two characters, which is what an ISO country code is. */
    private static function codeFrom(string $slug): ?string
    {
        return Helper::placeCode($slug, 2);
    }
}
