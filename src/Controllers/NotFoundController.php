<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use Twig\Error\Error;

class NotFoundController extends AbstractController
{
    /**
     * The route that resolved to nothing. It shares its answer with the routes
     * that resolve to something unreachable, so there is one 404 rather than
     * two that have to be kept looking alike.
     *
     * @throws Exception|Error
     */
    public function index(): void
    {
        $this->notFound();
    }
}
