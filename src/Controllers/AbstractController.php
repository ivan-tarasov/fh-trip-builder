<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use TripBuilder\Database\Connection;

/**
 * Base controller providing a lazily-opened database connection and a redirect.
 *
 * Header/footer rendering now lives in the Twig base layout (see
 * View\LayoutData); this class only shares what more than one page controller
 * needs.
 */
class AbstractController
{
    private ?Connection $connection = null;

    protected function connection(): Connection
    {
        return $this->connection ??= Connection::fromEnv();
    }

    /**
     * Send the visitor elsewhere.
     *
     * Falls back to a script when the response has already started, which is
     * what happens on a page that redirects after rendering has begun.
     */
    protected function bounce(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);

            return;
        }

        printf('<script>window.location.replace(%s);</script>', json_encode($url));
    }
}
