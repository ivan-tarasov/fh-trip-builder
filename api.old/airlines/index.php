<?php
/**
 * API / Shaw all airlines
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.3
 */

if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__FILE__, 3));
}

require_once __ROOT__ . '/vendor/autoload.php';

require_once __ROOT__ . '/config/Main.class.php';
require_once __ROOT__ . '/api/API.class.php';

$api = new API();

$api::$db->orderBy('traffic', 'desc');

$airlines = $api::$db->get("airlines");

http_response_code(200);

echo json_encode($airlines, JSON_PRETTY_PRINT);

die();
