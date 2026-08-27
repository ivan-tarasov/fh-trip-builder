<?php

declare(strict_types=1);

namespace TripBuilder\Api\Airports;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpStatus;
use TripBuilder\Helper;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Templater;

class Response extends AbstractApi
{
    /**
     * @throws Exception
     */
    public function get(): void
    {
        $majorOnly = !empty($this->data['major']) && $this->data['major'];

        $airports = (new AirportRepository($this->connection()))->enabled($majorOnly);

        $this->sendResponse(HttpStatus::Ok, $airports);
    }

    /**
     * @throws Exception
     */
    public function getAutofill(): void
    {
        $query = $_GET['query'] ?? '';

        if (empty($query) || strlen($query) < 3) {
            $this->sendResponse(HttpStatus::Ok);
            return;
        }

        $airports = (new AirportRepository($this->connection()))->autofill($query);

        $airportsGroups = $response = [];

        foreach ($airports as $airport) {
            $airportsGroups[$airport['city']]['code'] = $airport['city_code'];
            $airportsGroups[$airport['city']]['country'] = $airport['country'];
            $airportsGroups[$airport['city']]['timezone'] = $airport['timezone'];
            $airportsGroups[$airport['city']]['airports'][] = $airport;
        }

        $templater = new Templater('api/airports/autofill', 'city-span');

        foreach ($airportsGroups as $city => $group) {
            $response[] = $templater->setFilename('city-span')->set()
                ->setPlaceholder('city-code', $group['code'])
                ->setPlaceholder('city-name', $city)
                ->setPlaceholder('country-name', $group['country'])
                ->setPlaceholder('time-zone', Helper::getUTCTime((float) $group['timezone']))
                ->save()->render();

            foreach ($group['airports'] as $airport) {
                $response[] = $templater->setFilename('airport-span')->set()
                    ->setPlaceholder('airport-code', $airport['code'])
                    ->setPlaceholder('airport-name', $airport['title'])
                    ->setPlaceholder('city-name', $airport['city'])
                    ->setPlaceholder('airport-country', $airport['country'])
                    ->save()->render();
            }
        }

        $this->sendResponse(HttpStatus::Ok, $response);
    }
}
