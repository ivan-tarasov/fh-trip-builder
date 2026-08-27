<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

class AirportsController
{
    /**
     * @return void
     * @throws GuzzleException
     */
    public function index(): void
    {
        $apiClient = new Api(Config::get('api.fake.url'));

        try {
            $headers = [
                'Authorization' => Credentials::getBearer(),
                'Accept' => 'application/json',
            ];

            $response = $apiClient->post('airports', $headers, ['major' => true]);

            echo new TwigRenderer()->renderPage('airports/view.html.twig', [
                'airports' => $response->data,
            ]);
        } catch (\Exception $e) {
            error_log('Airports page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airports. Please try again later.';
        }
    }

}
