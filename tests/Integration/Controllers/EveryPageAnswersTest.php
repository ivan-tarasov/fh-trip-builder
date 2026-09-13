<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Controllers;

use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\Input;
use TripBuilder\Http\Kernel;
use TripBuilder\Http\Request;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\Routes;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\Timer;

/**
 * Every controller is asked for its page, and what comes back is looked at.
 *
 * Three of twenty controllers had a test that made a request (E10, #147), and
 * the suite was green partly because of that: a broken template, a missing
 * route parameter or an unhandled exception all look exactly like a passing
 * build until somebody opens the page.
 *
 * Driven through `Kernel`, which is the front controller's own routing and
 * rendering, lifted out of `public/index.php` for this test. Re-implementing
 * those twenty lines here would have tested the re-implementation: the layout
 * rule in particular is subtle, and a copy of it would drift silently.
 *
 * **The addresses come from the sitemap**, for the eleven controllers that
 * appear in it. A sitemap is a promise, and one `<loc>` of each kind being
 * fetched here is that promise being kept — a hard-coded slug would only prove
 * that one airport still exists. The rest are fixed below, because they are
 * private, transient, or not pages at all.
 */
final class EveryPageAnswersTest extends IntegrationTestCase
{
    /**
     * What the front controller renders through `layout.html.twig`, and what
     * emits its own payload. A JSON monitor endpoint has no `<title>`, and
     * asking it for one would be asking the wrong question.
     */
    private const array PAYLOAD = ['Health', 'Sitemap', 'Ajax'];

    /**
     * Not asked, and why.
     *
     * `ApiController` is the one real gap. Its guards answer through
     * `ApiResponder`, whose every method ends in `die()` — so an endpoint that
     * refuses a request ends the PHP process, which in a test run means ending
     * the run. Making it testable means giving the API a way to refuse that is
     * not `die()`, which is a change to how the API answers and belongs in its
     * own issue (#198).
     */
    private const array UNASKED = [
        'AbstractController' => 'a base class, not a route target',
        'ApiController' => 'ApiResponder::sendResponse() calls die() — see #198',
    ];

    protected function setUp(): void
    {
        // The route table, the currency list and the footer's links.
        new Config('common');

        // Every page prints how long the request took, and `index.php` is what
        // normally starts the clock. Without it, rendering throws.
        Timer::start();
    }

    /**
     * One request per controller: the status, the document, and no trace of
     * the front controller's last-resort catch.
     */
    public function testEveryControllerAnswers(): void
    {
        $failures = [];

        foreach ($this->addresses() as $controller => [$method, $path, $expected]) {
            $answer = $this->ask($method, $path);

            foreach (self::complaints($controller, $answer, $expected) as $complaint) {
                $failures[] = sprintf('%s (%s %s): %s', $controller, $method, $path, $complaint);
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * A controller with no address here is a controller nothing requests, and
     * it would be added without anybody noticing — which is how three of
     * twenty came to be the number.
     */
    public function testEveryControllerHasAnAddress(): void
    {
        $asked = array_map(
            static fn(string $controller): string => $controller . 'Controller',
            array_keys($this->addresses()),
        );

        $unasked = array_values(array_diff(self::controllers(), $asked, array_keys(self::UNASKED)));

        self::assertSame(
            [],
            $unasked,
            'A controller nobody asks for a page. Add an address to this test, or a line to '
            . 'UNASKED saying why it cannot have one.',
        );
    }

    /**
     * @param array{status: int, body: string} $answer
     * @return list<string>
     */
    private static function complaints(string $controller, array $answer, HttpStatus $expected): array
    {
        $complaints = [];

        if ($answer['status'] !== $expected->value) {
            $complaints[] = sprintf('answered %d, expected %d', $answer['status'], $expected->value);
        }

        // The front controller's catch-all. If this is in a body then something
        // threw, and the page a visitor got was an apology.
        if (str_contains($answer['body'], 'Something went wrong')) {
            $complaints[] = 'the body carries the unhandled-error message';
        }

        // A redirect has no document to check, and a payload endpoint has no
        // document at all. A 404 is neither: it is a page, and being a real
        // page rather than a bare status is the whole of what #165 was about.
        $redirect = $expected->value >= 300 && $expected->value < 400;

        if ($redirect || in_array($controller, self::PAYLOAD, true)) {
            return $complaints;
        }

        if (preg_match('#<title>\s*\S[^<]*</title>#', $answer['body']) !== 1) {
            $complaints[] = 'no <title>, or an empty one';
        }

        if (!str_contains($answer['body'], '<main')) {
            $complaints[] = 'no <main> landmark';
        }

        return $complaints;
    }

    /**
     * Run one request the way the server does.
     *
     * The status is read before it is reset. Reset first and every assertion
     * would be about a 200; left set, a 404 leaks into whatever runs next.
     *
     * @return array{status: int, body: string}
     */
    private function ask(string $method, string $path): array
    {
        http_response_code(HttpStatus::Ok->value);
        Timer::start();

        try {
            $body = new Kernel(
                new Request(new Input(), new Input(), new Input(), method: $method, uri: $path),
            )->handle();

            return ['status' => (int) http_response_code(), 'body' => $body];
        } catch (Throwable $e) {
            // Named, because a bare stack trace from inside a template says
            // nothing about which of twenty pages was being drawn.
            self::fail(sprintf('%s threw %s: %s', $path, $e::class, $e->getMessage()));
        } finally {
            http_response_code(HttpStatus::Ok->value);
        }
    }

    /**
     * Controller name => [method, path, expected status].
     *
     * @return array<string, array{string, string, HttpStatus}>
     */
    private function addresses(): array
    {
        // Memoised: both tests want the table and building it renders the
        // sitemap, which is a real query against every place this site sells.
        static $addresses = null;

        if ($addresses !== null) {
            return $addresses;
        }

        $addresses = [];

        foreach ($this->fromSitemap() as $controller => $path) {
            $addresses[$controller] = ['GET', $path, HttpStatus::Ok];
        }

        return $addresses = $addresses + [
            // No sitemap entry: a route page is a pair of cities rather than a
            // record, and there are more pairs than pages worth offering.
            'Route' => ['GET', $this->aRoute(), HttpStatus::Ok],

            // Private, so deliberately absent from the sitemap.
            'My' => ['GET', '/my/bookings', HttpStatus::Ok],
            'Search' => ['GET', self::aSearch(), HttpStatus::Ok],

            // With nothing being bought, checkout sends the visitor back to the
            // homepage rather than drawing a form over an empty trip.
            'Checkout' => ['GET', '/checkout', HttpStatus::Found],

            // Not a page, and the point of it is that it is not one.
            'NotFound' => ['GET', '/no-such-page-anywhere', HttpStatus::NotFound],

            // Payloads. The Ajax endpoint is asked without a CSRF token on
            // purpose: refusing is its job, and refusing *as JSON with a 403*
            // rather than dying or drawing a page is the part worth pinning.
            'Health' => ['GET', '/health', HttpStatus::Ok],
            'Sitemap' => ['GET', '/sitemap.xml', HttpStatus::Ok],
            'Ajax' => ['POST', '/ajax/day-prices', HttpStatus::Forbidden],
        ];
    }

    /**
     * One address of each kind the sitemap offers, in the order it offers
     * them.
     *
     * @return array<string, string>
     */
    private function fromSitemap(): array
    {
        $this->connection();

        $xml = $this->ask('GET', '/sitemap.xml');

        self::assertSame(HttpStatus::Ok->value, $xml['status'], 'the sitemap is where the addresses come from');

        preg_match_all('#<loc>([^<]+)</loc>#', $xml['body'], $found);

        $first = [];

        foreach ($found[1] as $loc) {
            $path = (string) (parse_url($loc, PHP_URL_PATH) ?? '/');
            $route = Routes::resolve($path);

            if ($route !== null) {
                $first[explode('@', $route)[0]] ??= $path;
            }
        }

        self::assertNotSame([], $first, 'an empty sitemap would silently test nothing');

        return $first;
    }

    /** The busiest pair there is, so the page has something on it to draw. */
    private function aRoute(): string
    {
        $popular = new RouteRepository($this->connection())->popular(1);

        if ($popular === []) {
            self::markTestSkipped('No route has flights, so there is no route page to ask for.');
        }

        return '/route/' . Helper::slug($popular[0]['from_name']) . '-to-' . Helper::slug($popular[0]['to_name']);
    }

    /**
     * A one-way a month out, built rather than written down: a fixed date in a
     * URL is a test that starts failing on a particular morning.
     */
    private static function aSearch(): string
    {
        return sprintf('/search/YUL%sLHRY1', new DateTimeImmutable('+30 days')->format('dmy'));
    }

    /** @return list<string> */
    private static function controllers(): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Helper::getRootDir() . '/src/Controllers'),
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getBasename('.php');
            }
        }

        sort($found);

        return $found;
    }
}
