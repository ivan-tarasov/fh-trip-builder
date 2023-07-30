<?php
/**
 * Airports
 *
 * Show all airports from DB
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.2
 */

if (!defined('__ROOT__'))
    define('__ROOT__', dirname(__FILE__));

include(__ROOT__ . '/header.inc.php');

// Initializing API connector
require_once __ROOT__ . '/class/api.class.php';

$api = new APIConnector(Config::get('api')['url'], Config::get('api')['token']);

// Getting airports
$airports = $api->getAllAirports();

/** Airport card */
$card_airport = '';

foreach ($airports as $id => $airport) {
    $params_card = [
        '%AIRPORT_CODE%'        => $airport['code'],
        '%AIRPORT_REGION_CODE%' => $airport['region_code'],
        '%AIRPORT_TIMEZONE%'    => $airport['timezone'],
        '%AIRPORT_TITLE%'       => $airport['title'],
        '%AIRPORT_COUNTRY%'     => $airport['country'],
        '%AIRPORT_CITY%'        => $airport['city'],
        '%AIRPORT_LATITUDE%'    => $airport['latitude'],
        '%AIRPORT_LONGITUDE%'   => $airport['longitude'],
        '%MAP_ZOOM%'            => 10,
        '%MAP_LANGUAGE%'        => 'en-US',
        '%MAP_SIZE%'            => '450,200',
    ];

    $card_airport .= Functions::template('card', $params_card, 'airports');
}

$params_index = ['%AIRPORT_CARD%' => $card_airport];

$html .= Functions::template('index', $params_index, 'airports');

include(__ROOT__ . '/footer.inc.php');
