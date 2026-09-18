<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Helper;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\SuggestedOrigin;
use TripBuilder\View\TwigRenderer;
use TripBuilder\VisitorLocation;

class HomeController extends AbstractController
{
    /**
     * Origin cities per side, domestic and international combined -- the
     * same shortlist size `CityController::FARE_ORIGINS` uses for its own
     * "cheap tickets" strip, since a POI card is asking the same question
     * for one fewer reason to answer it twice.
     */
    private const int ORIGIN_LIMIT = 8;

    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        // Three random points of interest for the promo cards.
        /** @var list<array{country: string, city: string, code: string, title: string, image: string}> $poi */
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
        // Null everywhere the edge is not -- local development included -- and
        // null again where the nearest place we sell from is further than a
        // drive, which leaves the field alone rather than guessing wide.
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

        echo new TwigRenderer()->renderPage('index/view.html.twig', [
            'today_date' => date('Y-m-d'),
            'poi_cards' => self::withCheapestFares($poi, $this->connection()),
            'top_searches' => $topSearches,
            // Everywhere a search can start or end. Small enough to ship whole,
            // which is what lets the form filter in the browser.
            'places' => $places,
            'depart_code' => $origin ?? '',
            // The nearby block, for the place the field opens on. Keyed by that
            // code because the template looks it up by the field's own value --
            // the same shape the results page passes.
            'nearby' => $origin === null ? [] : [
                $origin => $airports->nearbyPlaces(
                    $origin,
                    AirportRepository::NEARBY_CITIES,
                    AirportRepository::NEARBY_KM,
                    $places,
                ),
            ],
            'recent' => RecentSearches::rows($this->request->cookies, $places),
        ]);
    }

    /**
     * The cheapest direct fare into each POI card, from wherever the market
     * actually is -- C8 (#157), redesigned away from a personal origin
     * after two problems with that version: it went stale the moment
     * somebody typed a different "From" without submitting, and reaching a
     * live price for it at all meant a scheduled job proactively warming
     * `route_day_price` for routes nobody had asked about yet.
     *
     * Not personalised at all now, on purpose: the same "cheapest way in,
     * from home and abroad" question `CityController::fares()` already
     * answers on the destination's own page, reusing its two lower-level
     * calls (`CityRepository::busiestOriginAirports()`,
     * `FlightRepository::cheapestDirectPerOrigin()`) rather than its
     * composed, two-tab shape -- a card needs one number, not a UI. The
     * card links to that same page (`/city/<slug>`) rather than a
     * pre-filled search, so a visitor lands on real fares from real
     * origins instead of one this controller guessed at.
     *
     * `public` rather than `private`, matching the reason
     * `AdminController::scheduledCommandHelp()` is: so a test can call it
     * directly rather than parsing rendered HTML back out for what it says.
     *
     * @param list<array{country: string, city: string, code: string, title: string, image: string}> $poi
     * @return list<array{country: string, city: string, code: string, title: string, image: string, price: float|null, url: string|null}>
     */
    public static function withCheapestFares(array $poi, Connection $connection): array
    {
        $cities = new CityRepository($connection);
        $flights = new FlightRepository($connection);

        return array_map(
            static function (array $card) use ($cities, $flights): array {
                $city = $cities->byCode($card['code']);

                if ($city === null) {
                    return $card + ['price' => null, 'url' => null];
                }

                $url = '/city/' . Helper::placeSlug((string) $city['name'], (string) $city['code']);

                $origins = [
                    ...$cities->busiestOriginAirports($card['code'], (string) $city['country_code'], true, self::ORIGIN_LIMIT),
                    ...$cities->busiestOriginAirports($card['code'], (string) $city['country_code'], false, self::ORIGIN_LIMIT),
                ];
                $destinations = array_column($cities->airports($card['code']), 'code');

                $cheapest = $flights->cheapestDirectPerOrigin($origins, $destinations, CabinClass::Economy)[0] ?? null;

                return $card + [
                    'price' => $cheapest === null ? null : (float) $cheapest['total'],
                    // A page to send them to either way -- the fact of the
                    // city is not conditional on a fare existing this minute.
                    'url' => $url,
                ];
            },
            $poi,
        );
    }

}
