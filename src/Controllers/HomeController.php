<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Config;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\View\TwigRenderer;

class HomeController extends AbstractController
{
    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        $bgImageUrl = sprintf(
            '%s/%s/background/%s.jpg',
            Config::get('site.static.url'),
            Config::get('site.static.endpoint.images'),
            rand(1, 10),
        );

        // Three random points of interest for the promo cards.
        $poi = Config::get('site.poi');
        shuffle($poi);
        $poi = array_slice($poi, 0, 3);

        $topSearches = new SearchRepository($this->connection())->topSearches(5);

        echo new TwigRenderer()->renderPage('index/view.html.twig', [
            'bg_image_url' => $bgImageUrl,
            'today_date' => date('Y-m-d'),
            'poi_cards' => $poi,
            'top_searches' => $topSearches,
        ]);
    }

}
