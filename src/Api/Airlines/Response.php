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
        $this->db->orderBy('traffic', 'desc');

        $airlines = $this->db->get('airlines');

        $this->sendResponse(200, $airlines);
    }

}
