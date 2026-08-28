<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\View\TwigRenderer;

class AirportsController extends AbstractController
{
    public function index(): void
    {
        try {
            $airports = new AirportRepository($this->connection())->enabled(true);

            echo new TwigRenderer()->renderPage('airports/view.html.twig', [
                'airports' => $airports,
            ]);
        } catch (Exception $e) {
            error_log('Airports page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airports. Please try again later.';
        }
    }

}
