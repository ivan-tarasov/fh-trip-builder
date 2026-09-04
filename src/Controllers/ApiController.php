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
     * @throws Exception
     */
    public function airports(): void
    {
        $airports = new Airports\Response($this->request);

        $airports->get();
    }

    /**
     * @throws Exception
     */
    public function airportsAutofill(): void
    {
        $airports = new Airports\Response($this->request, HttpMethod::Get);

        $airports->getAutofill();
    }

    /**
     * @throws Exception
     */
    public function airlines(): void
    {
        $airlines = new Airlines\Response($this->request);

        $airlines->get();
    }

    /**
     * @throws Exception
     */
    public function flights(): void
    {
        $flights = new Flights\Response($this->request);

        $flights->get();
    }

    /**
     * @throws Exception
     */
    public function flightsOne(): void
    {
        $flights = new Flights\Response($this->request);

        $flights->getOne();
    }

}
