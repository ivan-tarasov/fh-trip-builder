<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

class AirlinesController
{
    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function index(): void
    {
        $apiClient = new Api(Config::get('api.fake.url'));

        try {
            $headers = [
                'Authorization' => Credentials::getBearer(),
                'Accept' => 'application/json',
            ];

            $response = $apiClient->post('airlines', $headers, ['major' => true]);

            echo new TwigRenderer()->renderPage('airlines/view.html.twig', [
                'airlines' => $response->data,
            ]);
        } catch (Exception $e) {
            error_log('Airlines page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airlines. Please try again later.';
        }
    }

}
