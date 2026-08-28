<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\View\TwigRenderer;

class AirlinesController extends AbstractController
{
    public function index(): void
    {
        try {
            $airlines = new AirlineRepository($this->connection())->search(null, true);

            echo new TwigRenderer()->renderPage('airlines/view.html.twig', [
                'airlines' => $airlines,
            ]);
        } catch (Exception $e) {
            error_log('Airlines page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airlines. Please try again later.';
        }
    }

}
