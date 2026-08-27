<?php

declare(strict_types=1);

namespace TripBuilder\Api\Airlines;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpStatus;
use TripBuilder\Repository\AirlineRepository;

class Response extends AbstractApi
{
    private const DATA_KEY_SELECTED = 'selected';
    private const DATA_KEY_MAJOR = 'major';

    /**
     * @throws Exception
     */
    public function get(): void
    {
        $codes = !empty($this->data[self::DATA_KEY_SELECTED])
            ? explode(',', $this->data[self::DATA_KEY_SELECTED])
            : null;

        $majorOnly = !empty($this->data[self::DATA_KEY_MAJOR]) && $this->data[self::DATA_KEY_MAJOR];

        $airlines = (new AirlineRepository($this->connection()))->search($codes, $majorOnly);

        $this->sendResponse(HttpStatus::Ok, $airlines);
    }
}
