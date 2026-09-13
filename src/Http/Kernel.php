<?php

declare(strict_types=1);

namespace TripBuilder\Http;

use Throwable;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * A request in, a body out: the routing and rendering half of the front
 * controller, with nothing process-level in it.
 *
 * Lifted out of `public/index.php` for E10.2 (#149), which needed to ask every
 * controller for its page. Doing that against a copy of these twenty lines
 * would have tested the copy: the layout rule below is subtle enough that a
 * test reimplementing it would drift, and the drift would show up as a test
 * that keeps passing while the site stops working.
 *
 * What stayed in `index.php` is everything that belongs to the process rather
 * than the request -- the session, the environment, the security headers, the
 * log, the shutdown functions, and the last-resort catch. A test wants none of
 * those and a server cannot do without them.
 */
final readonly class Kernel
{
    public function __construct(private Request $request) {}

    /**
     * The body to send.
     *
     * The status is set rather than returned, because a controller sets its own
     * and this has no way to collect it -- `NotFound` is the only one this
     * method answers for, and only when the router found nothing at all.
     */
    public function handle(): string
    {
        $url = $this->request->path();

        // Read during rendering, by the header's active link and by the robots
        // meta tag, so it has to be set before a controller runs.
        Routes::setCurrentPage($url);

        $route = Routes::resolve($url);
        [$controller, $action] = explode('@', $route ?? 'NotFound@index');

        // Before any output: once the layout starts, the headers are fixed.
        if ($route === null) {
            http_response_code(HttpStatus::NotFound->value);
        }

        $body = $this->run($controller, $action);

        return $this->needsLayout($controller, $url) ? self::wrapped($body) : $body;
    }

    private function run(string $controller, string $action): string
    {
        $class = sprintf('%s\%sController', Routes::ROUTES_CONTROLLERS_PATH, ucfirst($controller));
        $page = new $class($this->request);

        ob_start();

        try {
            $page->$action();

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            // Or the buffer outlives the request and the error page is rendered
            // inside a half-drawn document.
            ob_end_clean();

            throw $e;
        }
    }

    /**
     * A fragment is appended to a document that already exists -- the search
     * list's "load more" is one -- so a second header and footer around it
     * would draw a whole page inside a list. API and Ajax endpoints emit their
     * own payload and are named in Routes::EXCLUDE_HEADER_FOOTER.
     */
    private function needsLayout(string $controller, string $url): bool
    {
        return !$this->request->isFragment()
            && !in_array($controller, Routes::EXCLUDE_HEADER_FOOTER, true)
            && !Routes::emitsOwnPayload($url);
    }

    /**
     * Page controllers render the whole document themselves, because their
     * templates extend `layout.html.twig`. One that returns a body fragment
     * instead -- the search redirect guard -- is wrapped here.
     *
     * Nothing is not a fragment. A controller that emitted no bytes meant to
     * emit none: `bounce()` sets `Location` and a 3xx and returns, and there is
     * no page to put inside a layout. Wrapping it anyway drew the whole
     * document -- the currency table, the footer's git information, the
     * navigation -- and answered a redirect with 47 KB that no client displays
     * (E26, #199). Eighteen call sites do this, and the canonical-slug
     * redirects on `/airline`, `/airport` and `/airside` are the ones a crawler
     * triggers most.
     */
    private static function wrapped(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        if (str_starts_with(ltrim($body), '<!DOCTYPE')) {
            return $body;
        }

        return new TwigRenderer()->renderPage('layout.html.twig', ['page_content' => $body]);
    }
}
