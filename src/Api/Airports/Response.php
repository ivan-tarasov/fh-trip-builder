<?php

namespace TripBuilder\Api\Airports;

use TripBuilder\Api\AbstractApi;
use TripBuilder\DataBase\MySql;
use TripBuilder\Debug\dBug;
use TripBuilder\Templater;

class Response extends AbstractApi
{
    /**
     * MySQL Airports table columns
     *
     * @var array
     */
    private array $columns = [
        'a.code',
        'a.region_code',
        'c.title AS country',
        'a.city',
        'a.city_code',
        'a.timezone',
        'a.title',
        'a.latitude',
        'a.longitude'
    ];

    private array $airports = [];

    /**
     * @throws \Exception
     */
    public function __construct($method = false)
    {
        parent::__construct($method);

        $this->db->where('a.enabled', 1);
        $this->db->join('countries c', 'a.country_code=c.code', 'LEFT');
        $this->db->orderBy('a.title', 'asc');

        $this->airports = $this->db->get('airports a', null, $this->columns);
    }

    /**
     * @throws \Exception
     */
    public function get(): void
    {
        $this->sendResponse(200, $this->airports);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function getAutofill(): void
    {
        $airportsGroups =
        $response       = [];

        foreach ($this->airports as $airport) {
            $airportsGroups[$airport['city']]['code']       = $airport['city_code'];
            $airportsGroups[$airport['city']]['country']    = $airport['country'];
            $airportsGroups[$airport['city']]['airports'][] = $airport;
        }

        $templater = new Templater('api/airports/autofill', 'city-span');

        foreach ($airportsGroups as $city => $group) {
            $response[] = $templater->setFilename('city-span')->set()
                ->setPlaceholder('city-code', $group['code'])
                ->setPlaceholder('city-name', $city)
                ->setPlaceholder('country-name', $group['country'])
                ->save()->render();

            foreach ($group['airports'] as $airport) {
                $response[] = $templater->setFilename('airport-span')->set()
                    ->setPlaceholder('airport-code', $airport['code'])
                    ->setPlaceholder('airport-name',  $airport['title'])
                    ->setPlaceholder('city-name',  $airport['city'])
                    ->setPlaceholder('airport-country',  $airport['country'])
                    ->save()->render();
            }
        }

        $this->sendResponse(200, $response);
    }

}
