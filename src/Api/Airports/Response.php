<?php

namespace TripBuilder\Api\Airports;

use TripBuilder\Api\AbstractApi;
use TripBuilder\DataBase\MySql;

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
        'a.timezone',
        'a.title',
        'a.latitude',
        'a.longitude'
    ];

    /**
     * @throws \Exception
     */
    public function get(): void
    {
        $db = MySql::connect();

        $db->join('countries c', 'a.country_code=c.code', 'LEFT');
        $db->orderBy('a.title', 'asc');

        $airports = $db->get('airports a', null, $this->columns);

        $this->sendResponse(200, $airports);
    }

}