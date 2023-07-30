<?php
/**
 * Flights generator (DEPRECATED)
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.6
 */

if (!defined('__ROOT__'))
	define('__ROOT__', dirname(__FILE__));

require_once __ROOT__ . '/class/Timer.class.php';
require_once __ROOT__ . '/class/MysqliDb.class.php';

// CONFIG class including
require_once __ROOT__ . '/config/Main.class.php';

// Quick functions class
require_once __ROOT__ . '/class/Functions.class.php';

// Check Software setup state
if (!Functions::checkSetup()) {
	header("Location: /setup/");
	die();
}

try {
	// Rows to add
	define('FLIGHTS_COUNT', 10000);
	// Flight numbers pool per Airline
	define('FLIGHT_NUMBERS_POOL', 9999);
	// Multiplier of limit of attempts to generate new flight number
	define('ATTEMPTS_LIMIT', 10);
	// Fair flight price is 8 cents per km (plus some random value)
	define('PRICE_MULTIPLIER', 8);
	// Minimum value added to price
	define('PRICE_ADD_MIN', 20);
	// Maximum value added to price
	define('PRICE_ADD_MAX', 800);
	// Minimum value added to duration time
	define('DURATION_ADD_MIN', 10);
	// Maximum value added to duration time
	define('DURATION_ADD_MAX', 55);
	// Minimum days increase
	define('DATE_ADD_MIN', 1);
	// Maximum days increase
	define('DATE_ADD_MAX', 14);

	$airlinesQuery = Functions::$db->get('airlines');

	Functions::$db->where('enabled', 1);

	$airportsQuery = Functions::$db->get('airports');

	$sql_data = [];

	for ($i = 1; $i <= FLIGHTS_COUNT; $i++) {
		// Departure airport and arrival airport SHOULD be different
		$rand_keys = [
            array_rand($airportsQuery, 1),
            array_rand($airportsQuery, 1)
        ];

		if ($rand_keys[0] === $rand_keys[1]) {
            continue;
        }

		$airports = [
			'departure' => $airportsQuery[$rand_keys[1]],
			'arrival'   => $airportsQuery[$rand_keys[0]],
		];

		$airline = $airlinesQuery[rand(0, count($airlinesQuery) - 1)]['code'];

		// Calculating flight distance between airports
		$distance = Functions::earthDistance(
			$airports['departure']['latitude'],
			$airports['departure']['longitude'],
			$airports['arrival']['latitude'],
			$airports['arrival']['longitude'],
		);
		$distance = intval($distance / 1000);

		// Calculating flight duration between airports
		$duration  = Functions::distance2duration($distance);
		$duration += rand(DURATION_ADD_MIN, DURATION_ADD_MAX);

		// Render departure date and time (UNIX timestamps for current day)
		$int_date = rand(
            strtotime(date('Y-m-d') . ' 00:00:01'),
            strtotime(date('Y-m-d') . ' 23:59:59')
        );
		$departure_days_add = rand(DATE_ADD_MIN, DATE_ADD_MAX);
		$departure = date('Y-m-d H:i:s', strtotime('+ ' . $departure_days_add . ' days', $int_date));
		$arrival   = date('Y-m-d H:i:s', strtotime('+ ' . $duration . ' minutes', strtotime($departure)));

		// Generate and check flight number
		$check = 0;

		$check_break = FLIGHTS_COUNT * ATTEMPTS_LIMIT;

		while (true) {
			$check++;

			$flight_number = rand(1, FLIGHT_NUMBERS_POOL);
			Functions::$db->where("airline", $airline);
			Functions::$db->where("number", $flight_number);
			$flight = Functions::$db->get('flights');

			if (Functions::$db->count != 1) {
                break;
            }

			if ($check > $check_break) {
                throw new Exception("All flight numbers is given. Limit per one airline is " . FLIGHT_NUMBERS_POOL);
            }
		}

		$price = ($distance * PRICE_MULTIPLIER / 100) + rand(PRICE_ADD_MIN, PRICE_ADD_MAX);

		$rating = rand(1, 4) + rand(0, 100) / 100;

		/**
		 * Collecting data
		 */

		// SQL insert keys
		$sql_keys = [
			'airline',
			'number',
			'departure_airport',
			'departure_time',
			'duration',
			'distance',
			'arrival_airport',
			'arrival_time',
			'price',
			'rating'
		];

		$sql_data = [
			$airline,
			$flight_number,
			$airports['departure']['code'],
			$departure,
			$duration,
			$distance,
			$airports['arrival']['code'],
			$arrival,
			$price,
			$rating
		];

		if (! Functions::$db->insertMulti('flights', [$sql_data], $sql_keys)) {
            echo 'insert failed: ' . Functions::$db->getLastError();
        }
	}

	echo sprintf(
		'<strong>%s</strong> flights is generated in <strong>%s</strong> seconds',
		FLIGHTS_COUNT,
		Timer::finish()
	);
} catch (Exception $e) {
	echo 'Message: ' . $e->getMessage();
}
