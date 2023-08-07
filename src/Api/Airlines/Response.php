<?php

namespace TripBuilder\Api\Airlines;

use TripBuilder\Api\AbstractApi;
use TripBuilder\DataBase\MySql;

class Response extends AbstractApi
{
    /**
     * @throws \Exception
     */
    public function get(): void
    {
        $db = MySql::connect();

        $db->orderBy('traffic', 'desc');

        $airlines = $db->get('airlines');

        $this->sendResponse(200, $airlines);
    }

}