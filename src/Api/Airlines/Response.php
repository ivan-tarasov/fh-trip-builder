<?php

namespace TripBuilder\Api\Airlines;

use TripBuilder\Api\AbstractApi;
use TripBuilder\DataBase\MySql;
use TripBuilder\Debug\dBug;

class Response extends AbstractApi
{
    /**
     * @throws \Exception
     */
    public function get(): void
    {
        if (! empty($this->data['major']) && $this->data['major']) {
            $this->db->where('is_major', 1);
        }

        $this->db->orderBy('traffic', 'desc');

        $airlines = $this->db->get('airlines');

        $this->sendResponse(200, $airlines);
    }

}
