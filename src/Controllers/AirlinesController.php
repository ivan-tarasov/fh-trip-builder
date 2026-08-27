<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use TripBuilder\Cdn;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Config;
use TripBuilder\Templater;

class AirlinesController
{
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
                    ->setPlaceholder('airline_logo_img', Cdn::getUrl(sprintf(
                        '%s/suppliers/%s.png',
                        Config::get('site.static.endpoint.images'),
                        $airline->code
                    )))
                    ->setPlaceholder('airline_title',        $airline->title)
                    ->setPlaceholder('airline_phone_number', $airline->phone)
                    ->setPlaceholder('airline_url',          $airline->url)
                    ->save();
            }

            $airline_cards = $templater->render();

            echo $templater->setFilename('view')->set()
                ->setPlaceholder('airlines_cards', $airline_cards)
                ->save()->render();
        } catch (\Exception $e) {
            error_log('Airlines page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airlines. Please try again later.';
        }
    }

}
