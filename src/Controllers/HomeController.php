<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\RoutePriceRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\SearchUrl;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\SuggestedOrigin;
use TripBuilder\View\TwigRenderer;
use TripBuilder\VisitorLocation;

class HomeController extends AbstractController
{
    /**
     * "This month" for the Explore price on a POI card (C8, #157) -- the
     * same idea `AjaxController`'s calendar window scales up from, just
     * narrow enough that "you can afford this month" stays true rather than
     * quietly meaning "sometime in the next three".
     */
    private const int EXPLORE_WINDOW_DAYS = 30;

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
            'poi_cards' => self::withExplorePrices($poi, $origin, $this->connection()),
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
     * This month's cheapest fare and a link to it, on whichever POI cards
     * can show one -- C8 (#157).
     *
     * Silent rather than guessing when there is nothing to show: no
     * suggested origin yet, a POI that happens to be the origin itself, or
     * a route `flights:explore` (`Noah\Flights\Explore`) has not warmed --
     * this never builds one live, the same reason the command exists.
     *
     * `public` rather than `private`, matching the reason
     * `AdminController::scheduledCommandHelp()` is: so a test can call it
     * directly rather than parsing rendered HTML back out for what it says.
     *
     * @param list<array{country: string, city: string, code: string, title: string, image: string}> $poi
     * @return list<array{country: string, city: string, code: string, title: string, image: string, price: float|null, url: string|null}>
     */
    public static function withExplorePrices(array $poi, ?string $origin, Connection $connection): array
    {
        if ($origin === null) {
            return array_map(
                static fn(array $card): array => $card + ['price' => null, 'url' => null],
                $poi,
            );
        }

        $prices = new RoutePriceRepository($connection);
        $since = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+' . self::EXPLORE_WINDOW_DAYS . ' day'));

        return array_map(
            static function (array $card) use ($prices, $origin, $since, $until): array {
                $cheapest = $card['code'] === $origin
                    ? null
                    : $prices->cheapest($origin, $card['code'], CabinClass::Economy, $since, $until);

                if ($cheapest === null) {
                    return $card + ['price' => null, 'url' => null];
                }

                return $card + [
                    'price' => $cheapest['base'] + $cheapest['tax'],
                    'url' => new SearchUrl($origin, $card['code'], $cheapest['depart_date'], null)->path(),
                ];
            },
            $poi,
        );
    }

}
