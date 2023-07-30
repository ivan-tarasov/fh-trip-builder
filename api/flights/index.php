<?php
/**
 * API => GET flight with parameters
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.2.1
 */

if (!defined('__ROOT__'))
  define('__ROOT__', dirname(__FILE__, 3));

// require_once __ROOT__ . '/class/dBug.class.php';
require_once __ROOT__ . '/config/Main.class.php';
require_once __ROOT__ . '/api/API.class.php';

$api = new API();

/** Check API */
if (empty($_GET['from']) || !isset($_GET['to']) || !isset($_GET['departure_date_start']) || !isset($_GET['departure_date_end']))
  throw new statusCode(400);

define('SESSION', $_GET['SESSION_DATA']);
define('FROM', $_GET['from']);
define('TO', $_GET['to']);
define('DEPARTURE_START', $_GET['departure_date_start']);
define('DEPARTURE_END', $_GET['departure_date_end']);

$flights['sort_by'] = !empty(SESSION['sortBy']) ? SESSION['sortBy'] : 'price';

define('SORT_BY', $flights['sort_by']);

$columns = [
  'f.id',
  'f.airline          AS airline_code',
  'l.title            AS airline_title',
  'f.number',
  'da.code            AS departure_code',
  'da.title           AS departure_airport',
  'da.city            AS departure_city',
  'f.departure_time',
  'da.timezone        AS departure_timezone',
  'aa.code            AS arrival_code',
  'aa.title           AS arrival_airport',
  'aa.city            AS arrival_city',
  'f.arrival_time',
  'aa.timezone        AS arrival_timezone',
  'f.duration',
  'f.price',
  'f.rating'
];

/** WHERE conditions */
$api->SqlSearchWhere('f');

/** Sorting flight results */
$api::$db->orderBy('f.' . SORT_BY, Config::$site['sort'][SORT_BY]['order']); /**/

/** JOIN tables */
$api::$db->join("airlines l", "f.airline=l.code", "LEFT");
$api::$db->join("airports da", "f.departure_airport=da.code", "LEFT");
$api::$db->join("airports aa", "f.arrival_airport=aa.code", "LEFT");

/** Get flight results */
$flights['list'] = $api::$db->get("flights f", null, $columns);

/** Counting results rows */
$api->SqlSearchWhere();
$sql = $api::$db->get("flights");
$flights['total'] = $api::$db->count;

http_response_code(200);
echo json_encode($flights, JSON_PRETTY_PRINT);
