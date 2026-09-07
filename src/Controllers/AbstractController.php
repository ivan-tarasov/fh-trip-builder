<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Database\Connection;
use TripBuilder\Http\Request;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;
use Twig\Error\Error;

/**
 * Base controller providing the request, a lazily-opened database connection
 * and a redirect.
 *
 * Header/footer rendering now lives in the Twig base layout (see
 * View\LayoutData); this class only shares what more than one page controller
 * needs.
 *
 * The request is handed in rather than read from the superglobals, so a
 * controller's inputs are part of its construction and a test can supply them.
 */
class AbstractController
{
    private ?Connection $connection = null;

    public function __construct(protected readonly Request $request) {}

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
    protected function bounce(string $url, int $status = 302): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);

            return;
        }

        printf('<script>window.location.replace(%s);</script>', json_encode($url));
    }

    /**
     * Answer a page that does not exist.
     *
     * The status is set here rather than left to the front controller. That one
     * only knows whether a route resolved, and a route can resolve perfectly
     * well to a page that still names nothing: a search whose date is not on
     * the calendar, or one asking for more days than the search will run. Those
     * answered 200 with a body, which made them pages as far as a crawler was
     * concerned.
     *
     * @throws Exception|Error
     */
    protected function notFound(): void
    {
        // Page controllers run inside an output buffer, so nothing has reached
        // the wire yet and the status is still ours to set.
        if (!headers_sent()) {
            http_response_code(404);
        }

        // A fragment is spliced into a page that already exists. Sending a
        // whole second document would put a 404 screen inside a results list;
        // the status is the entire answer.
        if ($this->request->isFragment()) {
            return;
        }

        echo new TwigRenderer()->renderPage('error/404-not-found.html.twig', [
            // Derived from the path, the last crumb would be the segment that
            // did not resolve -- `/my/bookings/fdfsdf` read as "Home > My
            // bookings > fdfsdf", naming a page that does not exist after the
            // URL that failed. The ancestors are real and worth keeping, so
            // only the page itself is renamed, to what it actually is.
            'breadcrumbs' => Breadcrumbs::trail($this->request->path(), 'Page not found'),
        ]);
    }
}
