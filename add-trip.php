<?php
/**
 * Adding trip
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.2
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__FILE__));
}

require_once __ROOT__ . '/class/MysqliDb.class.php';

// CONFIG class including
require_once __ROOT__ . '/config/Main.class.php';

// Quick functions class
require_once __ROOT__ . '/class/Functions.class.php';

Functions::DBCheck();

Functions::$db->where('id', $_GET['departing_id']);
$flight = Functions::$db->getOne('flights', 'departure_time');

$sql_data = [
    'session_id'     => session_id(),
    'flight_a'       => $_GET['departing_id'],
    'flight_b'       => $_GET['returning_id'] ?? null,
    'departure_time' => $flight['departure_time']
];

$id = Functions::$db->insert('bookings', $sql_data);

$json = (! $id)
    ? ['status' => 'error',  'message' => 'insert failed: ' . Functions::$db->getLastError()]
    : ['status' => 'success','message' => 'Trip added with id ' . $id];

echo $json = json_encode($json);
