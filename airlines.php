<?php
/**
 * Airlines
 *
 * Show all airlines from DB
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.1
 */

if (!defined('__ROOT__'))
  define('__ROOT__', dirname(__FILE__));

include(__ROOT__ . '/header.inc.php');

/** Initializing API connector */
require_once __ROOT__ . '/class/api.class.php';
$api = new APIConnector(Config::get('api')['url'], Config::get('api')['token']);

/** Getting airports */
$airlines = $api->getAllAirlines();

/** Airport card */
$card_airline = null;

foreach ($airlines as $id => $airline) {
  $params_card = [
    '%AIRLINE_LOGO%' => Functions::airlineLogo($airline['code']),
    '%AIRLINE_TITLE%' => $airline['title'],
    '%AIRLINE_TRAFFIC%' => $airline['traffic']
  ];
  $card_airline .= Functions::template('card', $params_card, 'airlines');
}

$params_index = [
  '%AIRLINES_CARD%' => $card_airline
];

$html .= Functions::template('index', $params_index, 'airlines');

include(__ROOT__ . '/footer.inc.php');
?>
