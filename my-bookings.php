<?php
/**
 * Bookings list
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.2
 */

if (!defined('__ROOT__'))
    define('__ROOT__', dirname(__FILE__));

include(__ROOT__ . '/header.inc.php');

Functions::DBCheck();
$db = Functions::$db;

$columns = ['id', 'created', 'flight_a', 'flight_b'];

$db->where('session_id', session_id());
$db->orderBy('departure_time', 'asc');

// Paginating bookings results
$page = $_GET['page'] ?? 1;

$db->pageLimit = Config::$site['pagination']['booking'];

$bookings = $db->arraybuilder()->paginate('bookings', $page, $columns);

$tickets = [];

foreach ($bookings as $id => $booking) {
    $ticket = [];

    foreach (['a', 'b'] as $direction) {
        if (! empty($booking['flight_' . $direction])) {
            $ticket['flights'][$direction] = getFlightInfo($booking['flight_' . $direction], $direction)[0];
        }
    }

    $ticket['booking_id'] = $booking['id'];
    $ticket['created']    = $booking['created'];
    $ticket['direction'] = count($ticket['flights']) == 2
        ? 'roundtrip'
        : 'oneway';
    $ticket['price'] = count($ticket['flights']) == 2
        ? $ticket['flights']['a']['price'] + $ticket['flights']['b']['price']
        : $ticket['flights']['a']['price'];

    $tickets[] = $ticket;
}

$orders_count = count($bookings);

$html_bookings = '';

foreach ($tickets as $orders) {
    foreach ($orders['flights'] as $direction => $info) {
        $info['booking_id_formatted'] = sprintf(
            '%s%s',
            date('Ymd', strtotime($orders['created'])),
            str_pad($orders['booking_id'], 4, '0', STR_PAD_LEFT)
        );

        $info['price'] = Functions::calculateTax($orders['price']);

        if ('roundtrip' == $orders['direction']) {
            $roundtrip_merge = $direction == 'a'
                ? 'pb-0 mb-0 border-bottom-0'
                : 'pt-0 pb-3 mb-1 border-top-0';
        } else {
            $roundtrip_merge = null;
        }

        $d_none = $direction == 'b'
            ? 'd-none'
            : null;

        $params = [
            '%ROUNDTRIP_MERGE%'    => $roundtrip_merge,
            '%ROUNDTRIP_HIDE_ID%'  => $direction == 'a'
                ? 'mb-2 mt-3 d-flex'
                : 'd-none',
            '%BOOKING_ID%'         => $info['booking_id_formatted'],
            '%FLIGHT_NUMBER%'      => $info['flight_number'],
            '%BOOKING_CREATED%'    => date('Y-m-d H:i', strtotime($orders['created'])),
            '%FLIGHT_PRICE%'       => $info['price']['amount'],
            '%FLIGHT_PRICE_GST%'   => $info['price']['GST'],
            '%FLIGHT_PRICE_QST%'   => $info['price']['QST'],
            '%FLIGHT_PRICE_TAXES%' => $info['price']['taxes'],
            '%FLIGHT_PRICE_TOTAL%' => $info['price']['total'],
            '%AIRLINE_CODE%'       => $info['airline_code'],
            '%AIRLINE_TITLE%'      => $info['airline_title'],
            '%AIRLINE_LOGO%'       => Functions::airlineLogo($info['airline_code']),
            '%DEPARTURE_CITY%'     => $info['departure_city'],
            '%ARRIVAL_CITY%'       => $info['arrival_city'],
            '%DEPARTURE_TIME%'     => date('Y-m-d H:i', strtotime($info['departure_time'])),
            '%DISPLAY_NONE%'       => $d_none
        ];

        $html_bookings .= Functions::template('list-item', $params, 'my-bookings');
    }
}

$my_booking_params = [
    '%BOOKINGS_TITLE%' => 'TITLE',
    '%TRIPS_COUNT%'    => $orders_count,
    '%BOOKINGS_LIST%'  => $html_bookings
];

$html .= Functions::template('index', $my_booking_params, 'my-bookings');

include(__ROOT__ . '/footer.inc.php');

/**
 * Getting info about flight
 */
function getFlightInfo($id, $direction)
{
    global $db;

    $columns = [
        'f.airline          AS airline_code',
        'l.title            AS airline_title',
        'f.number           AS flight_number',
        'da.title           AS departure_airport',
        'da.city            AS departure_city',
        'f.departure_time',
        'aa.title           AS arrival_airport',
        'aa.city            AS arrival_city',
        'f.arrival_time',
        'f.price'
    ];

    $db->where('f.id', $id);

    $db->join('flights f', 'b.flight_' . $direction . '=f.id', 'LEFT');
    $db->join('airlines l', 'f.airline=l.code', 'LEFT');
    $db->join('airports da', 'f.departure_airport=da.code', 'LEFT');
    $db->join('airports aa', 'f.arrival_airport=aa.code', 'LEFT');

    // Paginating bookings results
    return $db->get('bookings b', null, $columns);
}
