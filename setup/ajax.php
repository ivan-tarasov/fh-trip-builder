<?php
/**
 * Software setup script
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.4
 */

ob_start();

header("Content-Type: text/event-stream; charset=UTF-8");
header("Cache-Control: no-cache, must-revalidate");
header("X-Accel-Buffering: no");

ob_end_clean();
ob_end_flush();

if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__FILE__, 2));
}

require_once __ROOT__ . '/class/Timer.class.php';
require_once __ROOT__ . '/class/MysqliDb.class.php';

// CONFIG class including
require_once __ROOT__ . '/config/Main.class.php';

// Quick functions class
require_once __ROOT__ . '/class/Functions.class.php';

Functions::DBCheck();

$stages = [
    'createMySQL',
    'generateFlights',
    'finalStep'
];

$stagesCount = sizeof($stages);

$id = 0;

$progress['now'] = 0;

esSend('START', 'step', null);

foreach ($stages as $id => $data) {
    $id++;
    $progress['past'] = $progress['now'];
    $progress['now'] += ceil(100 / $stagesCount);
    $progress['now']  = $progress['now'] >= 100
        ? 100
        : $progress['now'];

    call_user_func($data);
}

esSend('CLOSE', 'step', 'Done');

/**
 * FINCTIONS ===========================================================================================================
 */

/**
 * @param $id
 * @param $type
 * @param $message
 * @param $progress
 * @param $inline
 * @return void
 */
function esSend($id, $type, $message, $progress = 0, $inline = false)
{
    $count = 30;

    $symbol_1 = '#';
    $symbol_0 = '_';

    $percent_count = ($count / 100) * $progress;

    $progressbar = str_repeat($symbol_1, floor($percent_count)) . str_repeat($symbol_0, $count - floor($percent_count));

    $template = !$inline
        ? '<div class="output mb-0">%s</div>'
        : '%s';

    $d = [
        'rtype'       => $type,
        'message'     => sprintf($template, $message),
        'progress'    => $progress,
        'progressbar' => $progressbar
    ];

    echo "id: $id" . PHP_EOL;
    echo "data: " . json_encode($d) . PHP_EOL;
    echo PHP_EOL;

    @ob_flush();
    flush();
}

/**
 * @return void
 */
function createMySQL()
{
    global $progress;

    esSend(1, 'step', 'Adding tables to MySQL DB <span id="progress_2"></span>', 1);

    $db_tables = ['airlines', 'airports', 'bookings', 'countries', 'flights'];

    foreach ($db_tables as $table) {
        if (!Functions::$db->tableExists($table)) {
            $lines = file('mysql/' . $table . '.sql');

            foreach ($lines as $line) {
                // Skip it if it's a comment
                if (substr($line, 0, 2) == '--' || $line == '')
                    continue;

                // Add this line to the current segment
                $templine .= $line;

                // If it has a semicolon at the end, it's the end of the query
                if (substr(trim($line), -1, 1) == ';') {
                    // Perform the query
                    Functions::$db->rawQuery($templine);

                    if (Functions::$db->getLastErrno() !== 0)
                        esSend('ERROR', 'status', 'MySQL ERROR: ' . Functions::$db->getLastError());

                    // Reset temp variable to empty
                    $templine = '';
                }
            }
        }
        esSend(2, 'status', ' .', $progress['past'], true, true);
    }

    sleep(2);
    esSend(2, 'status', '<span class="strong ms-3">[DONE]</span>', $progress['now'], true);
}

/**
 * @return void
 */
function generateFlights()
{
    global $progress;
    global $_GET;

    $flights_count = $_GET['generate_flights'] ?? Config::$setup['flights'];

    esSend(3, 'step', sprintf('Generating %s flights <span id="progress_4"></span>', number_format($flights_count)));

    try {
        /** Rows to add */
        define('FLIGHTS_COUNT', $flights_count);
        /** Flight numbers pool per Airline */
        define('FLIGHT_NUMBERS_POOL', 9999);
        /** Multiplier of limit of attempts to generate new flight number */
        define('ATTEMPTS_LIMIT', 10);
        /** Fair flight price is 8 cents per km (plus some random value) */
        define('PRICE_MULTIPLIER', 8);
        /** Minimum value added to price */
        define('PRICE_ADD_MIN', 20);
        /** Maximum value added to price */
        define('PRICE_ADD_MAX', 800);
        /** Minimum value added to duration time */
        define('DURATION_ADD_MIN', 10);
        /** Maximum value added to duration time */
        define('DURATION_ADD_MAX', 55);
        /** Minimum days encrease */
        define('DATE_ADD_MIN', 1);
        /** Maximum days encrease */
        define('DATE_ADD_MAX', 14);

        $airlinesQuery = Functions::$db->get('airlines');

        Functions::$db->where('enabled', 1);

        $airportsQuery = Functions::$db->get('airports');

        require_once __ROOT__ . '/class/Timer.class.php';

        $unixtime = time();
        $sql_data = [];

        for ($i = 1; $i <= FLIGHTS_COUNT; $i++) {
            // Departure airport and arrival airport MUST be different
            $rand_keys = [
                array_rand($airportsQuery, 1),
                array_rand($airportsQuery, 1)
            ];

            if ($rand_keys[0] === $rand_keys[1]) {
                continue;
            }

            $airports = [
                'departure' => $airportsQuery[$rand_keys[1]],
                'arrival' => $airportsQuery[$rand_keys[0]],
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
            $int_date = rand(strtotime(date('Y-m-d') . ' 00:00:01'), strtotime(date('Y-m-d') . ' 23:59:59'));
            $departure_days_add = rand(DATE_ADD_MIN, DATE_ADD_MAX);
            $departure = date('Y-m-d H:i:s', strtotime('+ ' . $departure_days_add . ' days', $int_date));
            $arrival = date('Y-m-d H:i:s', strtotime('+ ' . $duration . ' minutes', strtotime($departure)));

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
                $rating,
            ];

            $ids = Functions::$db->insertMulti('flights', [$sql_data], $sql_keys);

            if (!$ids) {
                echo 'insert failed: ' . Functions::$db->getLastError();
            }

            if ($unixtime < time()) {
                $unixtime = time();
                esSend(4, 'status', ' .', $progress['past'], true, true);
            }
        }

        esSend(4, 'status', sprintf('<span class="strong ms-3">[DONE] (%s sec)</span>', Timer::finish()), $progress['now'], true);
    } catch (Exception $e) {
        esSend(4, 'status', sprintf('<span class="strong ms-3 text-danger text-bold">[ERROR] %s</span>', $e->getMessage()), $progress['past'], true);
    }
}

/**
 * @return void
 */
function finalStep()
{
    global $progress;

    esSend(5, 'step', 'All is done! You will be redirecting to main page<span id="progress_6"></span>');

    for ($i = 2; $i >= 0; $i--) {
        esSend(6, 'status', ' .', $progress['now'], true, true);
        sleep(1);
    }

    esSend(6, 'status', '<span class="strong ms-3">[REDIRECTING]</span>', $progress['now'], true);
}
