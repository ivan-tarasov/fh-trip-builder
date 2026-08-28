<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Api\Airlines;
use TripBuilder\Api\Airports;
use TripBuilder\Api\Flights;
use TripBuilder\Api\HttpMethod;

class ApiController extends AbstractController
{
    /**
     * @return void
     * @throws Exception
     */
    public function airports(): void
    {
        $airports = new Airports\Response();

        $airports->get();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function airportsAutofill(): void
    {
        $airports = new Airports\Response(HttpMethod::Get);

        $airports->getAutofill();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function airlines(): void
    {
        $airlines = new Airlines\Response();

        $airlines->get();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function flights(): void
    {
        $flights = new Flights\Response();

        $flights->get();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function flightsOne(): void
    {
        $flights = new Flights\Response();

        $flights->getOne();
    }

}
