<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityImageRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\SearchUrl;
use TripBuilder\Settings;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\SuggestedOrigin;
use TripBuilder\View\TwigRenderer;
use TripBuilder\VisitorLocation;

class HomeController extends AbstractController
{
    /**
     * Montreal's own airport, when nothing resolves an origin at all -- no
     * recent-search cookie, no Cloudflare geo header. In production a
     * real visitor almost always carries one or the other; this exists
     * for local development, where neither ever arrives and both the
     * "From" field and the deals carousel would otherwise sit empty on
     * every visit.
     *
     * `YUL`, not Montreal's city code `YMQ` -- the "From" field is a
     * `<select>` whose options are exactly `AirportRepository::pickable()`'s
     * own list, and a city only appears there in its own right when it
     * has more than one major airport. Montreal has one, so `YMQ` is
     * never an option at all: the field rendered empty despite
     * `depart_code` holding a real value, because nothing in the list
     * matched it. `YUL` is what a real resolved Montreal origin would
     * actually be, since that is the only code `pickable()` ever offers
     * for it.
     *
     * Applied here and in the "From" field/its nearby block below, on
     * request -- `SuggestedOrigin` itself is left alone, since its own
     * three-way precedence (searched before, geo guess, nothing) is a
     * real rule about *whose* answer wins and this is a fourth rung
     * bolted on after it, not a change to that rule.
     */
    private const string FALLBACK_ORIGIN = 'YUL';

    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        // Three random points of interest for the promo cards.
        /** @var list<array{country: string, city: string, title: string, image: string}> $poi */
        $poi = Config::get('site.poi');
        shuffle($poi);
        $poi = array_slice($poi, 0, 3);

        // Each row carries the cabin it was searched in; the list shows it
        // when it is not economy, so two rows differing only by cabin read
        // differently. Resolved here rather than in Twig so the template does
        // not have to know the slugs.
        $topSearches = array_map(
            static function (array $search): array {
                $cabin = CabinClass::fromRequest(
                    is_string($search['class'] ?? null) ? $search['class'] : null,
                );

                return $search + [
                    'class_label' => $cabin === CabinClass::Economy ? null : $cabin->label(),
                ];
            },
            new SearchRepository($this->connection())->topSearches(5),
        );

        $airports = new AirportRepository($this->connection());
        $places = $airports->pickable();

        // The field arrives on a place rather than empty. Only "From": where
        // somebody is going is the question they came to answer, and guessing
        // at it would be answering it for them.
        // Where they appear to be, for a first visit with no search behind it.
        // Null everywhere the edge is not -- local development included --
        // and null again where the nearest place we sell from is further
        // than a drive, which leaves $origin itself null rather than
        // guessing wide. $displayOrigin below is where that null is finally
        // resolved to something, not here.
        $here = VisitorLocation::coordinates($this->request);

        $origin = SuggestedOrigin::choose(
            RecentSearches::latestOrigin($this->request->cookies),
            $here === null ? null : $airports->nearestPlaceTo(
                $here['latitude'],
                $here['longitude'],
                AirportRepository::HERE_KM,
                $places,
            ),
            array_column($places, 'code'),
        );

        // Montreal rather than nothing when neither signal arrived -- see
        // FALLBACK_ORIGIN's own docblock for why this sits here rather
        // than inside SuggestedOrigin.
        $displayOrigin = $origin ?? self::FALLBACK_ORIGIN;

        echo new TwigRenderer()->renderPage('index/view.html.twig', [
            'today_date' => date('Y-m-d'),
            'poi_cards' => $poi,
            'top_searches' => $topSearches,
            'deals' => self::travelDeals($origin, $airports, $this->connection()),
            'popular' => self::popularFlights($origin, $airports, $this->connection()),
            // Everywhere a search can start or end. Small enough to ship whole,
            // which is what lets the form filter in the browser.
            'places' => $places,
            'depart_code' => $displayOrigin,
            // The nearby block, for the place the field opens on. Keyed by that
            // code because the template looks it up by the field's own value --
            // the same shape the results page passes.
            'nearby' => [
                $displayOrigin => $airports->nearbyPlaces(
                    $displayOrigin,
                    AirportRepository::NEARBY_CITIES,
                    AirportRepository::NEARBY_KM,
                    $places,
                ),
            ],
            'recent' => RecentSearches::rows($this->request->cookies, $places),
        ]);
    }

    /**
     * "Travel deals under $X" -- the cheapest direct fare into each
     * destination city reachable from the visitor's own suggested origin,
     * cheapest first. C10 (#395).
     *
     * Reuses `FlightRepository::cheapestPerDestinationCity()` as-is --
     * built for a country page ("which of my cities is cheap from here"),
     * and structurally the same question this asks, just against every
     * major airport rather than one country's. The origin side is what
     * keeps the query cheap (its own docblock: naming the airports lets
     * the index seek on `departure_airport`); the destination side is
     * deliberately broad, not filtered down, since "anywhere" is the
     * point.
     *
     * `$X` is not a fixed number: it is the priciest of whichever cards
     * are actually shown, rounded up to the nearest $10, so the heading
     * never promises a ceiling the cards do not back up. No flights found
     * under the resolved origin reads the same way as no origin at all --
     * an empty carousel, not a guess -- but a missing origin itself falls
     * back to `FALLBACK_ORIGIN` first; see that constant for why this is
     * the one place on the homepage that guesses.
     *
     * `public` rather than `private`, matching the reason
     * `AdminController::scheduledCommandHelp()` is: so a test can call it
     * directly rather than parsing rendered HTML back out for what it says.
     *
     * How many cards show is `PanelSetting::HomeDealsLimit` (G22, #426),
     * not a constant -- Trip.com's own reference shows four; ten is the
     * config default, enough to make a strip worth scrolling without
     * asking `cheapestPerDestinationCity()` to rank the entire network for
     * a row nobody will reach.
     *
     * `image` is our own S3 key -- whatever `cities:content`
     * (`Noah\Cities\Content`) already downloaded from Wikipedia and
     * re-hosted -- read here, never fetched here, and never a Wikipedia
     * URL. The template renders it through `cdn()`, the same way
     * `poi.image` already is. `null` when a city has none (17 of 231 real
     * cities, live-measured) or has not been looked up yet; the template
     * falls back to the plain card for either case rather than an empty
     * image box.
     *
     * @return array{ceiling: int|null, cards: list<array{city: string, price: float, url: string, airline: string, departure_time: string, duration: int, image: string|null}>}
     */
    public static function travelDeals(?string $origin, AirportRepository $airports, Connection $connection): array
    {
        $fromAirports = $airports->codesFor($origin ?? self::FALLBACK_ORIGIN);
        $toAirports = array_column($airports->enabled(true), 'code');

        if ($fromAirports === [] || $toAirports === []) {
            return ['ceiling' => null, 'cards' => []];
        }

        $rows = array_slice(
            new FlightRepository($connection)->cheapestPerDestinationCity($fromAirports, $toAirports, CabinClass::Economy),
            0,
            (int) Settings::get('site.home.deals_limit', 10),
        );

        if ($rows === []) {
            return ['ceiling' => null, 'cards' => []];
        }

        $totals = array_map(static fn(array $row): float => (float) $row['total'], $rows);
        $images = new CityImageRepository($connection);

        return [
            'ceiling' => (int) (ceil(max($totals) / 10) * 10),
            'cards' => array_map(
                static fn(array $row): array => [
                    'city' => $row['to_city'],
                    'price' => (float) $row['total'],
                    'url' => new SearchUrl(
                        from: $row['from_city_code'],
                        to: $row['to_city_code'],
                        depart: substr($row['departure_time'], 0, 10),
                        return: null,
                    )->path(),
                    'airline' => $row['airline'],
                    'departure_time' => $row['departure_time'],
                    'duration' => $row['duration'],
                    'image' => $images->imageKeyFor($row['to_city_code']),
                ],
                $rows,
            ),
        ];
    }

    /**
     * "Popular flights near you" -- domestic and international tabs of the
     * cheapest direct fare into each destination city reachable from the
     * visitor's own suggested origin. C12 (#401).
     *
     * The reverse of what a city page's own fares block asks
     * ({@see CityController::fares()}): that one
     * holds the destination fixed and splits *origins* by country; here
     * the origin is fixed (the same one travelDeals() resolves) and the
     * split is on the *destination* side instead, via
     * {@see AirportRepository::enabledByCountry()}. A tab that comes back
     * empty -- most likely international, from an origin whose country
     * sells nowhere else -- is dropped rather than shown scrolling nothing.
     *
     * Reuses `place/fares.html.twig`, the same tabset partial the city and
     * country pages already render their own fare tabs through: each
     * `cheapestPerDestinationCity()` row already carries `from_city` /
     * `to_city`, so only `search` needs adding.
     *
     * Cards per tab is `PanelSetting::HomePopularLimit` (G22, #426), not a
     * constant -- same reasoning `travelDeals()`'s own limit has: enough to
     * fill a strip worth scrolling without asking `cheapestPerDestinationCity()`
     * to rank every city in the split.
     *
     * @return list<array{id: string, label: string, fares: list<array<string, mixed>>}>
     */
    public static function popularFlights(?string $origin, AirportRepository $airports, Connection $connection): array
    {
        $fromAirports = $airports->codesFor($origin ?? self::FALLBACK_ORIGIN);

        if ($fromAirports === []) {
            return [];
        }

        $originCountry = $airports->byCode($fromAirports[0])['country_code'] ?? null;

        if ($originCountry === null) {
            return [];
        }

        $flights = new FlightRepository($connection);
        $popularLimit = (int) Settings::get('site.home.popular_limit', 8);
        $tabs = [];

        foreach ([true, false] as $domestic) {
            $toAirports = $airports->enabledByCountry($originCountry, $domestic, true);
            $rows = array_slice(
                $flights->cheapestPerDestinationCity($fromAirports, $toAirports, CabinClass::Economy),
                0,
                $popularLimit,
            );

            if ($rows === []) {
                continue;
            }

            $tabs[] = [
                'id' => $domestic ? 'home' : 'away',
                'label' => $domestic ? 'Domestic' : 'International',
                'fares' => array_map(
                    static fn(array $row): array => $row + [
                        'search' => new SearchUrl(
                            from: $row['from_city_code'],
                            to: $row['to_city_code'],
                            depart: substr($row['departure_time'], 0, 10),
                            return: null,
                        )->path(),
                    ],
                    $rows,
                ),
            ];
        }

        return $tabs;
    }

}
