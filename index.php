<?php
/**
 * Index page
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   2.2.1
 */

require_once 'vendor/autoload.php';

use TripBuilder\Config;
use TripBuilder\Timer;
use TripBuilder\Routs;
use TripBuilder\Controllers\AbstractController;
use TripBuilder\Debug\dBug;

try {
    Timer::start();

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

    // Load and execute the controller action
    $controllerClassName = sprintf(
        '%s\%sController',
        Routs::ROUTS_CONTROLLERS_PATH,
        ucfirst($controllerName)
    );

    $abstractController = new AbstractController();
    $controller = new $controllerClassName();

    // Build and show page header
    in_array($controllerName, Routs::EXCLUDE_HEADER_FOOTER) ?: $abstractController->header();

    // Handle dynamic parameters if present
    $controller->$actionName();

    // Build and show page footer
    in_array($controllerName, Routs::EXCLUDE_HEADER_FOOTER) ?: $abstractController->footer();

    // This is the end...
} catch (Exception $e) {
    // Do something
}
