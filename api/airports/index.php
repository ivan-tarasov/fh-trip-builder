<?php
/**
 * API / Shaw all airports
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.3
 */

if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__FILE__, 3));
}

require_once __ROOT__ . '/config/Main.class.php';
require_once __ROOT__ . '/api/API.class.php';

$api = new API();

$columns = [
    'a.code',
    'a.region_code',
    'c.title AS country',
    'a.city',
    'a.timezone',
    'a.title',
    'a.latitude',
    'a.longitude'
];

$api::$db->join('countries c', 'a.country_code=c.code', 'LEFT');
$api::$db->orderBy('a.title', 'asc');

$airlines = $api::$db->get('airports a', null, $columns);

http_response_code(200);

echo json_encode($airlines, JSON_PRETTY_PRINT);
