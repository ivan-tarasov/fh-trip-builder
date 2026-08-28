<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

class NotFoundController
{
    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        echo new TwigRenderer()->renderPage('error/404-not-found.html.twig', [
            'app_css_folder' => sprintf('%s/%s', Cdn::getUrl(), Config::get('site.static.endpoint.css')),
        ]);
    }
}
