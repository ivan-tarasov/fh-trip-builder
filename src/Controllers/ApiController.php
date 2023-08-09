<?php

namespace TripBuilder\Controllers;

use TripBuilder\Api\Server;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\Airports;
use TripBuilder\Api\Airlines;

class ApiController extends AbstractApi
{
    public function index(): void
    {
        $this->sendResponse(200, ['All works']);
    }

    /**
     * @throws \Exception
     */
    public function server(): void
    {
        $server = new Server\Response();

        $server->get();
    }

    /**
     * @throws \Exception
     */
    public function airports(): void
    {
        $airports = new Airports\Response();

        $airports->get();
    }

    /**
     * @throws \Exception
     */
    public function airlines(): void
    {
        $airports = new Airlines\Response();

        $airports->get();
    }

}