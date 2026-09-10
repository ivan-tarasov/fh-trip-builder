<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\SuggestedOrigin;
use TripBuilder\View\TwigRenderer;
use TripBuilder\VisitorLocation;

class HomeController extends AbstractController
{
    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        // Three random points of interest for the promo cards.
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
            'poi_cards' => $poi,
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

}
