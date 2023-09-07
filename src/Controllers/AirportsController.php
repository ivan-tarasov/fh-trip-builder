<?php

namespace TripBuilder\Controllers;

use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Config;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
use TripBuilder\Templater;
use GuzzleHttp\Exception\GuzzleException;

class AirportsController
{
    const DEFAULT_ALTITUDE  = '45.469539',
          DEFAULT_LONGITUDE = '-73.744296';

    const MAP_URL           = 'https://static-maps.yandex.ru/1.x/',
          MAP_ZOOM          = 10,
          MAP_LANGUAGE      = 'en-US',
          MAP_SIZE          = '450,200';

    /**
     * @return void
     * @throws GuzzleException
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

            $response = $apiClient->post('airports', $headers, $data);

            $templater = new Templater('airports', 'card');

            foreach ($response->data as $airport) {
                $templater
                    ->setPlaceholder('airport_iata_code', $airport->code)
                    ->setPlaceholder('airport_title',     $airport->title)
                    ->setPlaceholder('airport_country',   $airport->country)
                    ->setPlaceholder('airport_city',      $airport->city)
                    ->setPlaceholder('airport_timezone',  $airport->timezone_name)
                    ->setPlaceholder('airport_latitude',  $airport->latitude)
                    ->setPlaceholder('airport_longitude', $airport->longitude)
                    ->setPlaceholder('airport_altitude',  number_format($airport->altitude))
                    ->setPlaceholder('airport_map_img',   $this->getAirportMap($airport->latitude, $airport->longitude))
                    ->save();
            }

            $airport_cards = $templater->render();

            echo $templater
                ->setFilename('view')
                ->set()
                ->setPlaceholder('airport-cards', $airport_cards)
                ->save()
                ->render();
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

    /**
     * @param string $latitude
     * @param string $longitude
     * @return string
     */
    private function getAirportMap(string $latitude = self::DEFAULT_ALTITUDE, string $longitude = self::DEFAULT_LONGITUDE): string
    {
        return sprintf(
            '%s?ll=%s,%s&z=%d&l=map&lang=%s&size=%s',
            self::MAP_URL,
            $longitude,
            $latitude,
            self::MAP_ZOOM,
            self::MAP_LANGUAGE,
            self::MAP_SIZE
        );
    }

}
