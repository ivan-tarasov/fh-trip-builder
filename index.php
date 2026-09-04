<?php

declare(strict_types=1);
/**
 * Index page
 *
 * @author Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version 2.2.2
 */

require_once __DIR__ . '/vendor/autoload.php';

use TripBuilder\Config;
use TripBuilder\Http\Request;
use TripBuilder\Routes;
use TripBuilder\Timer;
use TripBuilder\View\TwigRenderer;

try {
    Timer::start();

    // The one place the superglobals are read. Everything downstream takes
    // this object, so what a class reads from the request is visible in its
    // signature. Captured before the session so both can see the same scheme.
    $request = Request::capture();

    // We using sessions here...
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $request->isSecure(),
    ]);
    session_start();

    // Enable .env file variables
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    // Building config
    new Config();

    // Get the current URL and put it to Routes class
    $url = $request->path();
    Routes::setCurrentPage($url);

    // Find the corresponding controller and action
    [$controllerName, $actionName] = explode('@', Routes::ENABLED_ROUTES[$url] ?? 'NotFound@index');

    // Unknown route: set the status now, before any layout output locks the headers
    if (!isset(Routes::ENABLED_ROUTES[$url])) {
        http_response_code(404);
    }

    // Load and execute the controller action
    $controllerClassName = sprintf(
        '%s\%sController',
        Routes::ROUTES_CONTROLLERS_PATH,
        ucfirst($controllerName),
    );

    $controller = new $controllerClassName($request);

    // A fragment request asks for a piece of a page — the search list's "load
    // more" is one — so its output is appended to a document that already
    // exists and must not be wrapped in a second header and footer.
    $isFragment = $request->isFragment();

    $needsLayout = !$isFragment
        && !in_array($controllerName, Routes::EXCLUDE_HEADER_FOOTER)
        && !in_array($url, Routes::EXCLUDE_HEADER_FOOTER_ROUTES);

    // API/Ajax endpoints emit their own payload with no header/footer.
    if (!$needsLayout) {
        $controller->$actionName();
    } else {
        // Capture the page body. Page controllers render the full document
        // themselves (their templates extend layout.html.twig); a controller
        // that emits only a body fragment instead (e.g. the search
        // redirect-guard) gets wrapped in the base layout here.
        ob_start();
        $controller->$actionName();
        $output = ob_get_clean();

        if (!str_starts_with(ltrim($output), '<!DOCTYPE')) {
            $output = new TwigRenderer()->renderPage('layout.html.twig', [
                'page_content' => $output,
            ]);
        }

        echo $output;
    }

    // This is the end...
} catch (Throwable $e) {
    error_log(sprintf(
        'Unhandled %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));

    if (!headers_sent()) {
        http_response_code(500);
    }

    echo 'Something went wrong on our side. Please try again later.';
}
