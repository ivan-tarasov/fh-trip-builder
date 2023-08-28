<?php

namespace TripBuilder\Controllers;

use TripBuilder\AmazonS3;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Config;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
use TripBuilder\Templater;

class AirlinesController
{
    const AIRLINES_LOGO_PATH   = 'frontend/images/airlines',
          AIRLINES_LOGO_EXT    = 'png',
          AIRLINES_LOGO_NO_IMG = 'no-logo';

    /**
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function index(): void
    {
        $apiClient = new Api(Config::get('api.fake.url'));

        try {
            // Setting-up request headers
            $headers = [
                'Authorization' => Credentials::getBearer(),
                'Accept'        => 'application/json',
            ];

            // Setting-up request data
            $data = [
                'major' => true,
            ];

            $response = $apiClient->post('airlines', $headers, $data);

            $templater = new Templater('airlines', 'card');

            foreach ($response->data as $airline) {
                $templater
                    ->setPlaceholder('airline-logo-img', AmazonS3::getUrl(sprintf(
                        '%s/suppliers/%s.png',
                        Config::get('site.static.endpoint.images'),
                        $airline->code
                    )))
                    ->setPlaceholder('airline-title', $airline->title)
                    ->setPlaceholder('airline-traffic', $airline->traffic)
                    ->save();
            }

            $airline_cards = $templater->render();

            echo $templater->setFilename('view')->set()
                ->setPlaceholder('airlines-cards', $airline_cards)
                ->save()->render();
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

}
