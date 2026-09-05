<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;

class NotFoundController extends AbstractController
{
    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        echo new TwigRenderer()->renderPage('error/404-not-found.html.twig', [
            // Derived from the path, the last crumb would be the segment that
            // did not resolve -- `/my/bookings/fdfsdf` read as "Home > My
            // bookings > fdfsdf", naming a page that does not exist after the
            // URL that failed. The ancestors are real and worth keeping, so
            // only the page itself is renamed, to what it actually is.
            'breadcrumbs' => Breadcrumbs::trail($this->request->path(), 'Page not found'),
        ]);
    }
}
