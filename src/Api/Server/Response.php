<?php

namespace TripBuilder\Api\Server;

use Exception;
use TripBuilder\Api\AbstractApi;

class Response extends AbstractApi
{
    /**
     * @throws Exception
     */
    public function get(): void
    {
        $this->sendResponse(200, $_SERVER);
    }
}
