<?php

declare(strict_types=1);
/**
 * Index page
 *
 * @author Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version 2.2.2
 */

// One level below the project root: this directory is the document root
// and holds nothing but pages, so everything the app needs is above it.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// UTC, stated here rather than inherited (E17, #171).
//
// Two things depended on `date.timezone` in a php.ini this repository does not
// hold. The database is put on PHP's offset when a Connection is built, so a
// server whose PHP moved to a zone that observes DST would give a long-running
// command -- `flights:add` runs for minutes -- the offset it started with and
// an hour of silent drift after a transition. And a host upgrade changing that
// setting would shift every timestamp the application writes, with nothing
// anywhere to say it had.
//
// UTC has no transitions, so the offset is +00:00 forever and the question
// stops existing.
date_default_timezone_set('UTC');

use TripBuilder\Config;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\Kernel;
use TripBuilder\Http\Request;
use TripBuilder\Http\SecurityHeaders;
use TripBuilder\Log;
use TripBuilder\ScheduleWatch;
use TripBuilder\Timer;

try {
    Timer::start();

    // The one place the superglobals are read. Everything downstream takes
    // this object, so what a class reads from the request is visible in its
    // signature. Captured before the session so both can see the same scheme.
    $request = Request::capture();

    // Before anything can echo, because headers are fixed once output starts.
    SecurityHeaders::send($request);

    // One id for this request: on the response so somebody reporting a broken
    // page can quote it, and on every line it writes to the log so the two can
    // be put side by side. Registers the one-line-per-request handler too.
    header('X-Request-Id: ' . Log::begin());

    // A shutdown function, not a line at the end of this file: the request
    // most worth a log line is the one that died before reaching the end.
    register_shutdown_function(Log::finish(...), $request->method(), $request->path());

    // The site is the only part of this still running when cron is not, so an
    // ordinary page request is what notices (E16.2, #169). After the response,
    // and at most once an hour.
    register_shutdown_function(ScheduleWatch::warnIfStale(...));

    // We using sessions here...
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $request->isSecure(),
    ]);
    session_start();

    // Enable .env file variables
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();

    // Building config
    new Config();

    // Routing, rendering and the layout rule, which the integration suite drives
    // through this same class rather than through a copy of it (E10.2, #149).
    echo new Kernel($request)->handle();

    // This is the end...
} catch (Throwable $e) {
    Log::error(sprintf(
        'Unhandled %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));

    if (!headers_sent()) {
        http_response_code(HttpStatus::InternalServerError->value);
    }

    echo 'Something went wrong on our side. Please try again later.';
}
