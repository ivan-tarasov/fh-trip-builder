<?php

declare(strict_types=1);

namespace TripBuilder\Api\Airlines;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Repository\AirlineRepository;

class Response extends AbstractApi
{
    private const string DATA_KEY_SELECTED = 'selected';
    private const string DATA_KEY_MAJOR = 'major';

    /**
     * @throws Exception
     */
    public function get(): void
    {
        $selected = $this->data[self::DATA_KEY_SELECTED] ?? null;
        $codes = is_string($selected) && $selected !== '' ? explode(',', $selected) : null;

        $majorOnly = !empty($this->data[self::DATA_KEY_MAJOR]) && $this->data[self::DATA_KEY_MAJOR];

        $airlines = new AirlineRepository($this->connection())->search($codes, $majorOnly);

        $this->sendResponse(HttpStatus::Ok, $airlines);
    }
}
