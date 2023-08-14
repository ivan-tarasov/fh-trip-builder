<?php

namespace TripBuilder\Controllers;

use TripBuilder\Api\Server;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\Airports;
use TripBuilder\Api\Airlines;
use TripBuilder\Api\Flights;
use TripBuilder\Debug\dBug;

class ApiController extends AbstractController
{
    /**
     * @return void
     */
    public function index(): void
    {
        // $this->sendResponse(200, ['All works']);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function server(): void
    {
        $server = new Server\Response();

        $server->get();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function airports(): void
    {
        $airports = new Airports\Response();

        $airports->get();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function airportsAutofill(): void
    {
        $airports = new Airports\Response(AbstractApi::REQUEST_METHOD_GET);

        $airports->getAutofill();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function airlines(): void
    {
        $airlines = new Airlines\Response();

        $airlines->get();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function flights(): void
    {
        $flights = new Flights\Response();

        $flights->get();
    }

}
