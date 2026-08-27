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
use TripBuilder\Controllers\AbstractController;
use TripBuilder\Routes;
use TripBuilder\Timer;

try {
    Timer::start();

    // We using sessions here...
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => ! empty($_SERVER['HTTPS']),
    ]);
    session_start();

    // Enable .env file variables
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    // Building config
    new Config();

    // Get the current URL and put it to Routes class
    $url = rtrim(strtok($_SERVER['REQUEST_URI'], '?'), '/') ?: '/';
    Routes::setCurrentPage($url);

    // Find the corresponding controller and action
    [$controllerName, $actionName] = explode('@', Routes::ENABLED_ROUTES[$url] ?? 'NotFound@index');

    // Unknown route: set the status now, before any layout output locks the headers
    if (! isset(Routes::ENABLED_ROUTES[$url])) {
        http_response_code(404);
    }

    // Load and execute the controller action
    $controllerClassName = sprintf(
        '%s\%sController',
        Routes::ROUTES_CONTROLLERS_PATH,
        ucfirst($controllerName),
    );

    $controller = new $controllerClassName();

    $needsLayout = ! in_array($controllerName, Routes::EXCLUDE_HEADER_FOOTER);

    // The layout renderer opens its own DB connection, so only build it for
    // routes that actually render the header/footer (not API/Ajax endpoints).
    $layout = $needsLayout ? new AbstractController() : null;

    $layout?->header();

    $controller->$actionName();

    $layout?->footer();

    // This is the end...
} catch (Throwable $e) {
    error_log(sprintf(
        'Unhandled %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));

    if (! headers_sent()) {
        http_response_code(500);
    }

    echo 'Something went wrong on our side. Please try again later.';
}
