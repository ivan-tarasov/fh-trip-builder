<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\SearchUrl;
use TripBuilder\View\Directory;
use TripBuilder\View\TwigRenderer;

class CityController extends AbstractController
{
    /**
     * How many neighbours to offer, and how far away one may be.
     *
     * Both are needed. A count alone calls a city 3,855km away a neighbour --
     * that is the real distance to the nearest city from the loneliest place in
     * this database. A radius alone leaves the block empty: 231 major cities
     * for the whole world is a thin map, its median gap between neighbours is
     * 296km, and at the 500km a denser dataset can afford, 85% of cities would
     * have fewer than five to show.
     */
    private const int NEARBY_LIMIT = 8;
    private const int NEARBY_MAX_KM = 1000;

    /** Origin cities per tab. Each is one row in the fares strip. */
    private const int FARE_ORIGINS = 8;

    /**
     * Every city, so every city page has a way in.
     *
     * Crawling from the homepage before this existed reached 171 of the 231 city
     * pages and took six hops to do it; sixty had no inbound link at all. This
     * is one page, one hop from the footer, that names all of them.
     */
    public function index(): void
    {
        try {
            echo new TwigRenderer()->renderPage('city/index.html.twig', [
                'groups' => Directory::byLetter(
                    self::addressable(new CityRepository($this->connection())->all()),
                ),
            ]);
        } catch (Throwable $e) {
            error_log('Cities page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading cities. Please try again later.';
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
            $cities = new CityRepository($this->connection());
            $city = $cities->byCode($code);

            if ($city === null) {
                $this->notFound();

                return;
            }

            // One city, one address. The code is what found the page, so any
            // name in front of it would serve the same content -- which is two
            // URLs for one thing unless one of them is made to point at the
            // other. 301 rather than 302: this is how the page is spelled, not
            // where it happens to be today.
            $canonical = Helper::placeSlug((string) $city['name'], (string) $city['code']);

            if ($slug !== $canonical) {
                $this->bounce('/city/' . $canonical, 301);

                return;
            }

            echo new TwigRenderer()->renderPage('city/view.html.twig', [
                'breadcrumbs' => self::trailFor($city),
                'city' => $city,
                'city_airports' => $cities->airports($code),
                'nearby' => self::addressable($cities->nearby($code, self::NEARBY_LIMIT, self::NEARBY_MAX_KM)),
                'fares' => $this->fares($cities, $city),
            ]);
        } catch (Throwable $e) {
            error_log('City page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this city. Please try again later.';
        }
    }

    /**
     * The cheapest way in, from home and from abroad.
     *
     * Two tabs because they answer different questions: a domestic hop and an
     * intercontinental fare are not comparable, and sorted together the cheap
     * short ones bury everything else. Either can come back empty -- a city
     * whose country has no other airport we sell, most obviously -- and the
     * block drops a tab that has nothing rather than showing an empty strip.
     *
     * @param array<string, mixed> $city
     * @return list<array<string, mixed>>
     */
    private function fares(CityRepository $cities, array $city): array
    {
        $code = (string) $city['code'];
        $country = (string) $city['country_code'];
        $destinations = array_column($cities->airports($code), 'code');
        $flights = new FlightRepository($this->connection());

        $tabs = [];

        foreach ([true, false] as $domestic) {
            $origins = $cities->busiestOriginAirports($code, $country, $domestic, self::FARE_ORIGINS);
            $found = $flights->cheapestDirectPerOrigin($origins, $destinations, CabinClass::Economy);

            if ($found === []) {
                continue;
            }

            $tabs[] = [
                'id' => $domestic ? 'home' : 'away',
                'label' => $domestic ? 'From ' . $city['country'] : 'Other countries',
                'fares' => array_map(
                    fn(array $fare): array => $fare + [
                        // The strip is shared with the country page, where the
                        // arrival city differs per row. Here it is the same one
                        // every time, and saying so is cheaper than selecting
                        // it back out of the query.
                        'to_city' => $city['name'],
                        // Where the card goes: the same search anybody would
                        // have run to find this fare, already filled in.
                        'search' => new SearchUrl(
                            from: (string) $fare['from_city_code'],
                            to: $code,
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
     * Home, then the country, then the city.
     *
     * The URL is not the trail here and does not need to be. /city/montreal-ymq
     * has no ancestor in its own path -- there is no /city page and there
     * should not be one, because the parent of a city is not a list of every
     * city we sell, it is the country it is in. Breadcrumbs::trail() reads
     * ancestors off the path and so finds nothing to say; renderPage() lets a
     * controller hand over a trail instead, which is what the booking page and
     * the 404 already do.
     *
     * The same shape the reference uses: its Moscow page reads Home / Russia /
     * Moscow, not Home / Cities / Moscow.
     *
     * The country link answers 404 for now, like the other pages still to be
     * built. It is spelled the way the city above it is -- name then code -- so
     * it will work the day that page exists.
     *
     * @param array<string, mixed> $city
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    private static function trailFor(array $city): array
    {
        $country = (string) $city['country'];

        return [
            ['label' => (string) Config::get('breadcrumbs.home', 'Home'), 'url' => '/', 'current' => false],
            [
                'label' => $country,
                'url' => '/country/' . Helper::placeSlug($country, (string) $city['country_code']),
                'current' => false,
            ],
            ['label' => (string) $city['name'], 'url' => null, 'current' => true],
        ];
    }

    /**
     * Give each city the address it is reached at.
     *
     * Built here rather than selected: a slug is how this app spells a name,
     * which is a view concern and not something the database should be asked
     * to store a second copy of. The whole path and not just the slug, so the
     * one place that knows where a city lives is this method -- a template that
     * writes "/city/" in front of a slug is a second place to change.
     *
     * @param list<array<string, mixed>> $cities
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $cities): array
    {
        return array_map(
            static fn(array $city): array => $city + [
                'url' => '/city/' . Helper::placeSlug((string) $city['name'], (string) $city['code']),
            ],
            $cities,
        );
    }

    /**
     * The slug out of /city/<slug>.
     */
    private function slug(): string
    {
        // Returned as written, not folded. A URL that has been through a mail
        // client, a spreadsheet or somebody's capitals still names the city --
        // the lookup lower-cases it -- but the canonical check has to see the
        // capitals to send them somewhere, or /city/LONDON-LON quietly serves
        // the same page at a second address.
        return preg_match('#^/city/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }

    /** Three characters, which is what an IATA code is. */
    private static function codeFrom(string $slug): ?string
    {
        return Helper::placeCode($slug, 3);
    }
}
