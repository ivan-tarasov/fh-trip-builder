<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\TwigRenderer;

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

        $places = new AirportRepository($this->connection())->pickable();

        echo new TwigRenderer()->renderPage('index/view.html.twig', [
            'today_date' => date('Y-m-d'),
            'poi_cards' => $poi,
            'top_searches' => $topSearches,
            // Everywhere a search can start or end. Small enough to ship whole,
            // which is what lets the form filter in the browser.
            'places' => $places,
            'recent' => RecentSearches::rows($this->request->cookies, $places),
        ]);
    }

}
