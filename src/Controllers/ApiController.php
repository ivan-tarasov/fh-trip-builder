<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Api\Airlines;
use TripBuilder\Api\Airports;
use TripBuilder\Api\ApiRefusal;
use TripBuilder\Api\ApiResponder;
use TripBuilder\Api\Flights;

class ApiController extends AbstractController
{
    /**
     * Run one endpoint, and turn a refusal into the response.
     *
     * Caught here and not in `Kernel`, which knows nothing about JSON and is
     * better for it: the catch belongs where the payload is written.
     */
    private function answer(callable $endpoint): void
    {
        try {
            $endpoint();
        } catch (ApiRefusal $refusal) {
            ApiResponder::send($refusal);
        }
    }

    /**
     * @throws Exception
     */
    public function airports(): void
    {
        $this->answer(function (): void {
            new Airports\Response($this->request)->get();
        });
    }

    /**
     * @throws Exception
     */
    public function airlines(): void
    {
        $this->answer(function (): void {
            new Airlines\Response($this->request)->get();
        });
    }

    /**
     * @throws Exception
     */
    public function flights(): void
    {
        $this->answer(function (): void {
            new Flights\Response($this->request)->get();
        });
    }

    /**
     * @throws Exception
     */
    public function flightsOne(): void
    {
        $this->answer(function (): void {
            new Flights\Response($this->request)->getOne();
        });
    }

}
