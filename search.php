<?php
/**
 * Search flights page
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.2.1
 */

if (!defined('__ROOT__'))
  define('__ROOT__', dirname(__FILE__));

// if (empty($_GET['from']) && empty($_GET['to']))
if (empty($_GET['hash']))
  header('Location: /');

include(__ROOT__ . '/header.inc.php');

/** Initializing API connector */
require_once __ROOT__ . '/class/api.class.php';
$api = new APIConnector(Config::get('api')['url'], Config::get('api')['token']);

$query_hash = Functions::hash($_GET['hash']);

/**
 * Setting up variables and filters
 */
if (!empty($query_hash['returning_date']))
  $activetab['roundtrip'] = Config::$site['tab_active'];
else
  $activetab['oneway'] = Config::$site['tab_active'];

if (!empty($_POST['sortBy'])) {
  $_SESSION['sortBy'] = $_POST['sortBy'];
}
if (array_key_first($activetab) == 'roundtrip') {
  if (!empty($_SESSION['sortBy']) && 1 !== Config::$site['sort'][$_SESSION['sortBy']]['roundtrip'])
    $_SESSION['sortBy'] = 'price';
}

if (!empty($_POST['filterAirlines']))
  $_SESSION['filterAirlines'] = $_POST['filterAirlines'];

if (empty($_GET['page']))
  unset($_SESSION['time_range']);

if (!empty($_POST['time_range'])) {
  foreach ($_POST['time_range'] as $direction => $range) {
    $_SESSION['time_range'][$direction] = ('null;null' != $range) ? $range : '00:00;23:59';
  }
}

$filterAirlines = !empty($_SESSION['filterAirlines']) ? $_SESSION['filterAirlines'] : [];

if (!empty($_SESSION['time_range'])) {
  foreach ($_SESSION['time_range'] as $direction => $range) {
    if ('null;null' != $range) {
      $range = explode(';', $range);
      $range = array_combine(['start', 'end'], array_values($range));

      foreach ($range as $key => $val)
        @$search['date_' . $direction][$key] = $query_hash[$direction . '_date'] . ' ' . trim($val) . ':00';
    } else
      $search['date_' . $direction] = null;
  }
} else {
  $first = ' 00:00:00';
  $last = ' 23:59:59';

  $search['date_departure'] = !empty($query_hash['departure_date']) ? $query_hash['departure_date'] : date('Y-m-d');
  $search['date_departure'] = ['start' => $search['date_departure'] . $first, 'end' => $search['date_departure'] . $last];

  $search['date_returning'] = (!empty($query_hash['returning_date'])) ? [
      'start' => $query_hash['returning_date'] . $first,
      'end' => $query_hash['returning_date'] . $last
    ] : null;
}

$search['departure'] = !empty($query_hash['from']) ? explode(',', $query_hash['from'])[0] : null;
$search['arrival'] = !empty($query_hash['to']) ? explode(',', $query_hash['to'])[0] : null;

$flights_departure = $api->getFlights($search['departure'], $search['arrival'], $search['date_departure']);

if (empty($flights_departure['list']))
  $flights_departure['total'] = 0;

/**
 * Finding all variations of round trip flight
 */
$flights_returning = $api->getFlights($search['arrival'], $search['departure'], $search['date_returning']);

/** Getting airlines */
$airlines = $api->getAllAirlines();

$tickets = [];

if (0 !== $flights_departure['total']) {
  foreach ($flights_departure['list'] as $departure) {
    $departure['airline_logo'] = Functions::airlineLogo($departure['airline_code']);

    if (!empty($flights_returning['list'])) {
      foreach ($flights_returning['list'] as $returning) {
        $returning['airline_logo'] = Functions::airlineLogo($returning['airline_code']);

        $tickets[] = [
          'departure' => $departure,
          'returning' => $returning,
          'price' => $departure['price'] + $returning['price'],
          'duration' => $departure['duration'] + $returning['duration'],
          'rating' => round(($departure['rating'] + $returning['rating']) / 2, 2)
        ];
      }
    } else {
      $tickets[] = [
        'departure' => $departure,
        'returning' => null,
        'departure_time' => strtotime($departure['departure_time']),
        'arrival_time' => strtotime($departure['arrival_time']),
        'price' => $departure['price'],
        'duration' => $departure['duration'],
        'rating' => $departure['rating']
      ];
    }
  }
}

$flights_total = !empty($tickets) ? count($tickets) : $flights_departure['total'];

usort($tickets, function ($a, $b) {
  $sort = !empty($_SESSION['sortBy']) ? $_SESSION['sortBy'] : 'price';

  if ($sort != 'rating')
    return $a[$sort] - $b[$sort];
  else
    return $b[$sort] - $a[$sort];
});

$tickets = Functions::flightBadges($tickets);

$search_content = null;

$page = !empty($_GET['page']) ? $_GET['page'] : 1;
$start = ($page - 1) * Config::$site['pagination']['search'];
$end = $start + Config::$site['pagination']['search'];
$tickets = array_slice($tickets, $start, Config::$site['pagination']['search']);

//if ('roundtrip' == array_key_first($activetab) && 0 === $flights_returning['total']) {
if ('roundtrip' == array_key_first($activetab) && empty($flights_returning['total']))
  $flights_total = 0;

if (0 != $flights_total) {
  if (empty($tickets))
    $tickets['departure'] = $flights_departure['list'];

  foreach ($tickets as $flight) {
    /** Convert arrival time to airport timezone */
    $flight['departure']['arrival_time'] = Functions::timeZone($flight['departure']['arrival_time'], $flight['departure']['departure_timezone'], $flight['departure']['arrival_timezone']);
    if (!empty($flight['returning']))
      $flight['returning']['arrival_time'] = Functions::timeZone($flight['returning']['arrival_time'], $flight['returning']['departure_timezone'], $flight['returning']['arrival_timezone']);

    /** TAX example for QC */
    $flight['price'] = Functions::calculateTax($flight['price']);

    /** Flight badges FUTURES */
    $badge_content = null;
    if (!empty($flight['badge'])) {
      foreach ($flight['badge'] as $badge) {
        $badge_params = [
          '%FLIGHT_BADGE_ID%' => $badge['id'],
          '%FLIGHT_BADGE_TEXT%' => $badge['text'],
          '%FLIGHT_BADGE_ICON%' => $badge['icon'],
          '%FLIGHT_BADGE_COLOR%' => $badge['color']
        ];
        $badge_content .= Functions::template('badge', $badge_params, 'search/cards');
      }
    }

    $params_card_flight = [
      '%DEPARTURE_TIME%' => date('H:i', strtotime($flight['departure']['departure_time'])),
      '%ARRIVAL_TIME%' => date('H:i', strtotime($flight['departure']['arrival_time'])),
      '%DEPARTURE_DATE%' => date('Y-m-d', strtotime($flight['departure']['departure_time'])),
      '%ARRIVAL_DATE%' => date('Y-m-d', strtotime($flight['departure']['arrival_time'])),
      '%FLIGHT_DURATION%' => Functions::secondsToTime($flight['departure']['duration']),
      '%DEPARTURE_CITY%' => $flight['departure']['departure_city'],
      '%ARRIVAL_CITY%' => $flight['departure']['arrival_city'],
      '%DEPARTURE_CODE%' => $flight['departure']['departure_code'],
      '%ARRIVAL_CODE%' => $flight['departure']['arrival_code'],
      '%DEPARTURE_AIRPORT%' => $flight['departure']['departure_airport'],
      '%ARRIVAL_AIRPORT%' => $flight['departure']['arrival_airport']
    ];
    $card_flight = Functions::template('flight', $params_card_flight, 'search/cards');

    /** Airlines logo in card */
    $params_airlines_logo = [
      '%FLIGHT_AIRLINE_CODE%' => $flight['departure']['airline_code'],
      '%FLIGHT_AIRLINE_LOGO%' => $flight['departure']['airline_logo'],
      '%FLIGHT_AIRLINE_TITLE%' => $flight['departure']['airline_title'],
      '%FLIGHT_AIRLINE_NUMBER%' => sprintf('%s %d', $flight['departure']['airline_code'], $flight['departure']['number'])
    ];
    $html_airlines_logo = Functions::template('airline-logo', $params_airlines_logo, 'search/cards');

    if (!empty($activetab['roundtrip'])) {
      $params_card_flight = [
        '%DEPARTURE_TIME%' => date('H:i', strtotime($flight['returning']['departure_time'])),
        '%ARRIVAL_TIME%' => date('H:i', strtotime($flight['returning']['arrival_time'])),
        '%DEPARTURE_DATE%' => date('Y-m-d', strtotime($flight['returning']['departure_time'])),
        '%ARRIVAL_DATE%' => date('Y-m-d', strtotime($flight['returning']['arrival_time'])),
        '%FLIGHT_DURATION%' => Functions::secondsToTime($flight['returning']['duration']),
        '%DEPARTURE_CITY%' => $flight['returning']['departure_city'],
        '%ARRIVAL_CITY%' => $flight['returning']['arrival_city'],
        '%DEPARTURE_CODE%' => $flight['returning']['departure_code'],
        '%ARRIVAL_CODE%' => $flight['returning']['arrival_code'],
        '%DEPARTURE_AIRPORT%' => $flight['returning']['departure_airport'],
        '%ARRIVAL_AIRPORT%' => $flight['returning']['arrival_airport']
      ];
      $card_flight .= '<hr class="mt-3 mb-3" />';
      $card_flight .= Functions::template('flight', $params_card_flight, 'search/cards');

      /** Airlines logo in card */
      $params_airlines_logo = [
        '%FLIGHT_AIRLINE_CODE%' => $flight['returning']['airline_code'],
        '%FLIGHT_AIRLINE_LOGO%' => $flight['returning']['airline_logo'],
        '%FLIGHT_AIRLINE_TITLE%' => $flight['returning']['airline_title'],
        '%FLIGHT_AIRLINE_NUMBER%' => sprintf('%s %d', $flight['returning']['airline_code'], $flight['returning']['number'])
      ];
      $html_airlines_logo .= Functions::template('airline-logo', $params_airlines_logo, 'search/cards');
    }

    $params = [
      '%DEPARTING_FLIGHT_DB_ID%' => $flight['departure']['id'],
      '%RETURNING_FLIGHT_DB_ID%' => !empty($flight['returning']) ? $flight['returning']['id'] : null,
      '%FLIGHT_PRICE%' => $flight['price']['amount'],
      '%FLIGHT_PRICE_GST%' => $flight['price']['GST'],
      '%FLIGHT_PRICE_QST%' => $flight['price']['QST'],
      '%FLIGHT_PRICE_TOTAL%' => $flight['price']['total'],
      '%FLIGHT_BADGE%' => $badge_content,
      '%AIRLINE_LOGOS%' => $html_airlines_logo,
      '%CARD_FLIGHT%' => $card_flight
    ];
    $search_content .= Functions::template('body', $params, 'search/cards');
  }

  $params_cards = [
    '%DEPARTURE_CITY%' => $flight['departure']['departure_city'],
    '%ARRIVAL_CITY%' => $flight['departure']['arrival_city'],
    '%QUERY_FLIGHTS_COUNT%' => Functions::plural('flight', 'flights', $flights_total),
    '%CONTENT%' => $search_content,
    '%PAGINATION%' => Functions::paginationResult($flights_total, Config::$site['pagination']['search'], $page)
  ];
}

/**
 * Adding small search form
 */
$params_search_form = [
  '%API_PATH_AIRPORTS%' => Config::$api['url'] . '/airports.php',
  '%SEARCH_CITY_DEPARTURE%' => trim(html_entity_decode($query_hash['from'])),
  '%SEARCH_CITY_ARRIVAL%' => trim(html_entity_decode($query_hash['to'])),
  '%SEARCH_DATE_DEPARTURE%' => trim(html_entity_decode($query_hash['departure_date'])),
  '%SEARCH_DATE_RETURNING%' => !empty($query_hash['returning_date']) ? trim(html_entity_decode($query_hash['returning_date'])) : null,
  '%TAB_ROUNDTRIP_BTN%' => @$activetab['roundtrip']['btn'],
  '%TAB_ROUNDTRIP_ARIA%' => @$activetab['roundtrip']['aria'],
  '%TAB_ROUNDTRIP_DIV%' => @$activetab['roundtrip']['div'],
  '%TAB_ONEWAY_BTN%' => @$activetab['oneway']['btn'],
  '%TAB_ONEWAY_ARIA%' => @$activetab['oneway']['aria'],
  '%TAB_ONEWAY_DIV%' => @$activetab['oneway']['div']
];
$html .= Functions::template('search-form-up', $params_search_form, 'search');

/**
 * SIDEBAR
 */
/** Airlines filter */
//$filterAirlines = !empty($_SESSION['filterAirlines']) ? $_SESSION['filterAirlines'] : [];
$airlinesItems = null;
foreach ($airlines as $airline) {
  $params_airlines = [
    '%AIRLINE_IATA%' => $airline['code'],
    '%AIRLINE_NAME%' => $airline['title'],
    '%AIR_CHECKED%' => empty($filterAirlines) || in_array($airline['code'], $filterAirlines) ? ' checked' : null
  ];
  $airlinesItems .= Functions::template('airlines', $params_airlines, 'search/sidebar');
}

/** Combine all sorting methods */
$sortItems = null;
foreach (Config::$site['sort'] as $sort => $values) {
  $params_sort = [
    '%SORT_ID%' => $values['id'],
    '%SORT_VALUE%' => $sort,
    '%SORT_TEXT%' => $values['text'],
    '%SORT_NOTE%' => $values['note'],
    '%HIDE_SORT_ITEM%' => $values[array_key_first($activetab)] !== 1 ? ' d-none' : null,
    '%SORT_CHECKED%' => 0 !== $flights_departure['total'] && $flights_departure['sort_by'] == $sort ? ' checked' : null
  ];
  $sortItems .= Functions::template(Config::$site['templates']['sidebar']['sort'], $params_sort, 'search/sidebar/sort');
}

/** After filter applying always show 1st page */
$_GET['page'] = 1;

/** Range script */
$params_range_script = [
  '%CLOCK_RANGE%' => Functions::generateTimeRange(),
  '%RANGE_FROM_DEPARTING%' => date('H:i', strtotime($search['date_departure']['start'])),
  '%RANGE_TO_DEPARTING%' => date('H:i', strtotime($search['date_departure']['end'])),
  '%RANGE_FROM_RETURNING%' => 'roundtrip' == array_key_first($activetab) ? date('H:i', strtotime($search['date_returning']['start'])) : null,
  '%RANGE_TO_RETURNING%' => 'roundtrip' == array_key_first($activetab) ? date('H:i', strtotime($search['date_returning']['end'])) : null,
];
$html_range_script = Functions::template('range', $params_range_script, 'search/sidebar');

$params_sidebar = [
  '%SHOW_TRIP_OPTIONS%' => null,
  //0 == $flights_total ? 'd-none' : null,
  '%GET_URI_STRING%' => $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET),
  '%AIRLINES_ITEMS%' => $airlinesItems,
  '%AIRLINES_SHOW%' => !empty($filterAirlines) ? ' show' : null,
  '%SORT_ITEMS%' => $sortItems,
  '%BUTTON_UPDATE%' => '<div class="d-grid gap-2"><button class="btn btn-primary btn-sm btn-block mt-3" type="submit">Update</button></div>',
  '%DEPARTURE_CITY%' => !empty($flight['departure']['departure_city']) ? $flight['departure']['departure_city'] : null,
  '%ARRIVAL_CITY%' => !empty($flight['departure']['arrival_city']) ? $flight['departure']['arrival_city'] : null,
  '%HIDE_RETURN_RANGE%' => 'oneway' == array_key_first($activetab) ? 'd-none' : null,
  '%RANGE_SCRIPT%' => $html_range_script
];

$params_index = [
  '%SEARCH_SIDEBAR%' => Functions::template('index', $params_sidebar, 'search/sidebar'),
  '%FLIGHT_CARDS%' => $flights_total != 0 ? Functions::template('index', $params_cards, 'search/cards') : Functions::template('no-result', null, 'search'),
  '%QUERY_FLIGHTS_COUNT%' => $flights_total
];

$html .= Functions::template('index', $params_index, 'search');

include(__ROOT__ . '/footer.inc.php');
?>
