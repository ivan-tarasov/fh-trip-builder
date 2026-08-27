<?php
/**
 * Index page
 *
 * @author Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version 2.2.2
 */

require_once __DIR__ . '/vendor/autoload.php';

use TripBuilder\Config;
use TripBuilder\Timer;
use TripBuilder\Routs;
use TripBuilder\Controllers\AbstractController;

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

    // Get the current URL and put it to Routs class
    $url = rtrim(strtok($_SERVER['REQUEST_URI'], '?'), '/') ?: '/';
    Routs::setCurrentPage($url);

    // Find the corresponding controller and action
    [$controllerName, $actionName] = explode('@', Routs::ENABLED_ROUTS[$url] ?? 'NotFound@index');

    // Unknown route: set the status now, before any layout output locks the headers
    if (! isset(Routs::ENABLED_ROUTS[$url])) {
        http_response_code(404);
    }

    // Load and execute the controller action
    $controllerClassName = sprintf(
        '%s\%sController',
        Routs::ROUTS_CONTROLLERS_PATH,
        ucfirst($controllerName),
    );

    $abstractController = new AbstractController();
    $controller = new $controllerClassName();

    $needsLayout = ! in_array($controllerName, Routs::EXCLUDE_HEADER_FOOTER);

    // Build and show page header
    if ($needsLayout) {
        $abstractController->header();
    }

    $controller->$actionName();

    // Build and show page footer
    if ($needsLayout) {
        $abstractController->footer();
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

    if (! headers_sent()) {
        http_response_code(500);
    }

    echo 'Something went wrong on our side. Please try again later.';
}
