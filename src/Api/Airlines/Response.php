<?php

namespace TripBuilder\Api\Airlines;

use TripBuilder\Api\AbstractApi;
use TripBuilder\DataBase\MySql;
use TripBuilder\Debug\dBug;

class Response extends AbstractApi
{
    const DATA_KEY_SELECTED = 'selected',
          DATA_KEY_MAJOR    = 'major';

    /**
     * @throws \Exception
     */
    public function get(): void
    {
        // Request only provided airlines
        if (! empty($this->data[self::DATA_KEY_SELECTED])) {
            $this->db->where('code', explode(',', $this->data[self::DATA_KEY_SELECTED]), 'IN');
        }

        // Request only major airlines
        if (! empty($this->data[self::DATA_KEY_MAJOR]) && $this->data[self::DATA_KEY_MAJOR]) {
            $this->db->where('is_major', 1);
        }

        $this->db->orderBy('title', 'asc');

        $airlines = $this->db->get('airlines');

        $this->sendResponse(200, $airlines);
    }

}
