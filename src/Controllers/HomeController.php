<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\SearchUrl;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\SuggestedOrigin;
use TripBuilder\View\TwigRenderer;
use TripBuilder\VisitorLocation;

class HomeController extends AbstractController
{
    /**
     * Cards in the "Travel deals" carousel -- C10 (#395). Trip.com's own
     * reference shows four; eight is enough to make a strip worth
     * scrolling without asking `cheapestPerDestinationCity()` to rank the
     * entire network for a row nobody will reach.
     */
    private const int DEALS_LIMIT = 8;

    /**
     * Montreal, when nothing resolves an origin at all -- no recent-search
     * cookie, no Cloudflare geo header. In production a real visitor
     * almost always carries one or the other; this exists for local
     * development, where neither ever arrives and both the "From" field
     * and the deals carousel would otherwise sit empty on every visit.
     *
     * Applied here and in the "From" field/its nearby block below, on
     * request -- `SuggestedOrigin` itself is left alone, since its own
     * three-way precedence (searched before, geo guess, nothing) is a
     * real rule about *whose* answer wins and this is a fourth rung
     * bolted on after it, not a change to that rule.
     */
    private const string FALLBACK_ORIGIN = 'YMQ';

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
     * @return array{ceiling: int|null, cards: list<array{city: string, price: float, url: string, airline: string, departure_time: string, duration: int}>}
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
            self::DEALS_LIMIT,
        );

        if ($rows === []) {
            return ['ceiling' => null, 'cards' => []];
        }

        $totals = array_map(static fn(array $row): float => (float) $row['total'], $rows);

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
                ],
                $rows,
            ),
        ];
    }

}
