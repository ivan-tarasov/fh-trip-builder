<?php

declare(strict_types=1);

namespace TripBuilder\Api\Airports;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpStatus;
use TripBuilder\Helper;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\View\TwigRenderer;

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

        $renderer = new TwigRenderer();

        foreach ($airportsGroups as $city => $group) {
            $response[] = $renderer->render('api/airports/autofill/city-span.html.twig', [
                'city_code'    => $group['code'],
                'city_name'    => $city,
                'country_name' => $group['country'],
                'time_zone'    => Helper::getUTCTime((float) $group['timezone']),
            ]);

            foreach ($group['airports'] as $airport) {
                $response[] = $renderer->render('api/airports/autofill/airport-span.html.twig', [
                    'airport_code'    => $airport['code'],
                    'airport_name'    => $airport['title'],
                    'city_name'       => $airport['city'],
                    'airport_country' => $airport['country'],
                ]);
            }
        }

        $this->sendResponse(HttpStatus::Ok, $response);
    }
}
