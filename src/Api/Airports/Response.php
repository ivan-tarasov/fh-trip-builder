<?php

declare(strict_types=1);

namespace TripBuilder\Api\Airports;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpStatus;
use TripBuilder\Repository\AirportRepository;

class Response extends AbstractApi
{
    /**
     * @throws Exception
     */
    public function get(): void
    {
        $majorOnly = !empty($this->data['major']) && $this->data['major'];

        $airports = new AirportRepository($this->connection())->enabled($majorOnly);

        $this->sendResponse(HttpStatus::Ok, $airports);
    }

}
