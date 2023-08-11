<?php

namespace TripBuilder\Controllers;

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
        $apiClient = new Api(Config::get('FlightAPI', 'url'));

        try {
            $headers = [
                'Authorization' => Credentials::getBearer(),
                'Accept'        => 'application/json',
            ];

            $getResponse = $apiClient->post('airlines', $headers);

            $templater = new Templater('airlines', 'card');

            foreach ($getResponse['data'] as $airline) {
                $templater
                    ->setPlaceholder('airline-logo-img', $this->getAirlineLogoFromIATA($airline['code']))
                    ->setPlaceholder('airline-title', $airline['title'])
                    ->setPlaceholder('airline-traffic', $airline['traffic'])
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

    /**
     * @param $code
     * @return string
     */
    private function getAirlineLogoFromIATA($code): string
    {
        $pathReal  = sprintf('/%s/%s.%s', self::AIRLINES_LOGO_PATH, $code, self::AIRLINES_LOGO_EXT);
        $pathNoImg = sprintf('/%s/%s.%s', self::AIRLINES_LOGO_PATH, self::AIRLINES_LOGO_NO_IMG, self::AIRLINES_LOGO_EXT);

        return file_exists(sprintf('%s/%s', Helper::getRootDir(), $pathReal))
            ? $pathReal
            : $pathNoImg;
    }

}
