<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use TripBuilder\Database\Connection;

/**
 * Base controller providing a lazily-opened database connection.
 *
 * Header/footer rendering now lives in the Twig base layout (see
 * View\LayoutData); this class only shares the DB accessor with page
 * controllers that read from the database.
 */
class AbstractController
{
    private ?Connection $connection = null;

    protected function connection(): Connection
    {
        return $this->connection ??= Connection::fromEnv();
    }
}
