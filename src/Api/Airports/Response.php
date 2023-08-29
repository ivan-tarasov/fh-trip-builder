<?php

namespace TripBuilder\Api\Airports;

use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpException;
use TripBuilder\DataBase\MySql;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
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
        'a.title',
        'c.title AS country',
        'a.city_code',
        'a.city',
        'a.timezone',
        'a.timezone_name',
        'a.latitude',
        'a.longitude',
        'a.altitude',
    ];

    private array $airports = [];

    public function __construct($method = false) {
        parent::__construct($method);
    }

    /**
     * @throws \Exception
     */
    public function get(): void
    {
        if (! empty($this->data['major']) && $this->data['major']) {
            $this->db->where('is_major', 1);
        }

        $this->db->where('a.enabled', 1);
        $this->db->join('countries c', 'a.country_code=c.code', 'LEFT');
        $this->db->orderBy('a.title', 'asc');

        $this->airports = $this->db->get('airports a', null, $this->columns);

        $this->sendResponse(200, $this->airports);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function getAutofill(): void
    {
        $query = $_GET['query'] ?? '';

        if (empty($query) || strlen($query) < 3) {
            $this->sendResponse(200);
            return;
        }

        $this->db->where('a.enabled', 1);
        $this->db->where ('a.code', '%' . $query . '%', 'like');
        $this->db->orWhere ('a.title', '%' . $query . '%', 'like');
        $this->db->orWhere ('a.city_code', '%' . $query . '%', 'like');
        $this->db->orWhere ('a.city', '%' . $query . '%', 'like');

        $this->db->join('countries c', 'a.country_code=c.code', 'LEFT');
        $this->db->orderBy('a.title', 'asc');

        $this->airports = $this->db->get('airports a', null, $this->columns);

        $airportsGroups =
        $response       = [];

        foreach ($this->airports as $airport) {
            $airportsGroups[$airport['city']]['code']       = $airport['city_code'];
            $airportsGroups[$airport['city']]['country']    = $airport['country'];
            $airportsGroups[$airport['city']]['timezone']   = $airport['timezone'];
            $airportsGroups[$airport['city']]['airports'][] = $airport;
        }

        $templater = new Templater('api/airports/autofill', 'city-span');

        foreach ($airportsGroups as $city => $group) {
            $response[] = $templater->setFilename('city-span')->set()
                ->setPlaceholder('city-code', $group['code'])
                ->setPlaceholder('city-name', $city)
                ->setPlaceholder('country-name', $group['country'])
                ->setPlaceholder('time-zone', Helper::getUTCTime($group['timezone']))
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
