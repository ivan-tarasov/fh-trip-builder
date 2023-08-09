<?php
/**
 * Index page
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   2.0.1
 */

require_once 'vendor/autoload.php';

use TripBuilder\Routs;

// Enable .env file variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Get the current URI
$uri = rtrim($_SERVER['REQUEST_URI'], '/') ?: '/';

// Find the corresponding controller and action
[$controllerName, $actionName] = explode('@', Routs::ENABLED_ROUTS[$uri] ?? 'NotFound@index');

// Load and execute the controller action
$controllerClassName = sprintf(
    '%s\%sController',
    Routs::ROUTS_CONTROLLERS_PATH,
    ucfirst($controllerName)
);

$controller = new $controllerClassName();
$controller->$actionName();
