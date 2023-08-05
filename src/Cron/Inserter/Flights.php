<?php
namespace TripBuilder\Cron\Inserter;

use TripBuilder\Config\MainConfig as Config;

class Flights {
    const FLIGHTS_COUNT       = 5000;
    const FLIGHT_NUMBERS_POOL = 9999;
    const ATTEMPTS_LIMIT      = 10;
    const PRICE_MULTIPLIER    = 8;
    const PRICE_ADD_MIN       = 5;
    const PRICE_ADD_MAX       = 800;
    const DURATION_ADD_MIN    = 10;
    const DURATION_ADD_MAX    = 55;
    const DATE_ADD_MIN        = 1;
    const DATE_ADD_MAX        = 30;

    const MYSQL_TABLE_COLUMNS = [
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

    protected $db;

    protected $airports = [];

    protected $airline;

    protected $distance;

    protected $duration;

    protected $departureDateTime;

    protected $arrivalDateTime;

    function __construct()
    {
        $this->connectMySQL();
    }

    /**
     * @throws \Exception
     */
    public function run()
    {
        $airlinesResponse = $this->db->get('airlines');

        $this->db->where('enabled', 1);
        $airportsResponse = $this->db->get('airports');

        for ($flightNumber = 1; $flightNumber <= self::FLIGHTS_COUNT; $flightNumber++) {
            // Departure airport and arrival airport SHOULD be different
            $rand_keys = [
                array_rand($airportsResponse, 1),
                array_rand($airportsResponse, 1)
            ];

            if ($rand_keys[0] === $rand_keys[1]) {
                continue;
            }

            $this->setAirports(
                $airportsResponse[$rand_keys[1]],
                $airportsResponse[$rand_keys[0]]
            );

            $this->setAirline($airlinesResponse[rand(0, count($airlinesResponse) - 1)]['code']);

            // Calculating flight distance between airports
            $this->setDistance(
                intval($this->distanceOnEarthSurface(
                    $this->getAirportDepartureLatitude(),
                    $this->getAirportDepartureLongitude(),
                    $this->getAirportArrivalLatitude(),
                    $this->getAirportArrivalLongitude(),
                ) / 1000)
            );

            // Calculating flight duration between airports
            $this->setDuration(
                $this->getDistanceFromDuration($this->getDistance()) +
                rand(self::DURATION_ADD_MIN, self::DURATION_ADD_MAX)
            );

            // Render departure date and time (UNIX timestamps for current day)
            $this->setDepartureDateTime(
                date('Y-m-d H:i:s',
                    strtotime(
                        sprintf('+ %d days', rand(self::DATE_ADD_MIN, self::DATE_ADD_MAX)),
                        rand(
                            strtotime(date('Y-m-d') . ' 00:00:01'),
                            strtotime(date('Y-m-d') . ' 23:59:59')
                        )
                    )
                )
            );

            // Calculating arrival date and time
            $this->setArrivalDateTime(
                date('Y-m-d H:i:s',
                    strtotime(sprintf('+ %d minutes', $this->getDuration()), strtotime($this->getDepartureDateTime()))
                )
            );

            // Inserting row to MySQL table
            if (! $this->db->insertMulti(
                'flights',
                [
                    [
                        $this->getAirline(),
                        $this->fakeFlightNumber(),
                        $this->getAirportDepartureCode(),
                        $this->getDepartureDateTime(),
                        $this->getDuration(),
                        $this->getDistance(),
                        $this->getAirportArrivalCode(),
                        $this->getArrivalDateTime(),
                        $this->calculatePrice(),
                        $this->fakeRating(),
                    ],
                ],
                self::MYSQL_TABLE_COLUMNS
            )) {
                echo 'insert failed: ' . $this->db->getLastError();
            }
        }
    }

    /**
     * @param $departureAirport
     * @param $arrivalAirport
     * @return void
     */
    private function setAirports($departureAirport, $arrivalAirport)
    {
        $this->airports = [
            'departure' => $departureAirport,
            'arrival'   => $arrivalAirport,
        ];
    }

    /**
     * @return string
     */
    private function getAirportDepartureCode(): string
    {
        return $this->airports['departure']['code'];
    }

    /**
     * @return string
     */
    private function getAirportArrivalCode(): string
    {
        return $this->airports['arrival']['code'];
    }

    /**
     * @return float
     */
    private function getAirportDepartureLatitude(): float
    {
        return $this->airports['departure']['latitude'];
    }

    /**
     * @return float
     */
    private function getAirportDepartureLongitude(): float
    {
        return $this->airports['departure']['longitude'];
    }

    /**
     * @return float
     */
    private function getAirportArrivalLatitude(): float
    {
        return $this->airports['arrival']['latitude'];
    }

    /**
     * @return float
     */
    private function getAirportArrivalLongitude(): float
    {
        return $this->airports['arrival']['longitude'];
    }

    /**
     * @param $airlineCode
     * @return void
     */
    private function setAirline($airlineCode)
    {
        $this->airline = $airlineCode;
    }

    /**
     * @return string
     */
    private function getAirline(): string
    {
        return $this->airline;
    }

    /**
     * @param int $distance
     * @return void
     */
    private function setDistance(int $distance)
    {
        $this->distance = $distance;
    }

    /**
     * @return int
     */
    private function getDistance(): int
    {
        return $this->distance;
    }

    /**
     * @param int $duration
     * @return void
     */
    private function setDuration(int $duration)
    {
        $this->duration = $duration;
    }

    /**
     * @return int
     */
    private function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * @param string $dateTime
     * @return void
     */
    private function setDepartureDateTime(string $dateTime)
    {
        $this->departureDateTime = $dateTime;
    }

    /**
     * @return string
     */
    private function getDepartureDateTime(): string
    {
        return $this->departureDateTime;
    }

    /**
     * @param string $dateTime
     * @return void
     */
    private function setArrivalDateTime(string $dateTime)
    {
        $this->arrivalDateTime = $dateTime;
    }

    /**
     * @return string
     */
    private function getArrivalDateTime(): string
    {
        return $this->arrivalDateTime;
    }

    /**
     * Generating fake ticket price
     *
     * @return float
     */
    private function calculatePrice(): float
    {
        return ($this->getDistance() * self::PRICE_MULTIPLIER / 100) + rand(self::PRICE_ADD_MIN, self::PRICE_ADD_MAX);
    }

    /**
     * Generating fake flight rating
     *
     * @return float
     */
    private function fakeRating(): float
    {
        return rand(1, 4) + rand(0, 100) / 100;
    }

    /**
     * @return void
     */
    protected function connectMySQL()
    {
        try {
            if (! $this->setDb()->tableExists([
                'airlines',
                'airports',
                'bookings',
                'countries',
                'flights'
            ])) {
                throw new \Exception("Error: MySQL tables not found.");
            }
        } catch (\Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }

    /**
     * @return \MysqliDb
     */
    protected function setDb(): \MysqliDb
    {
        return $this->db = new \MysqliDb(
            Config::get('mysql')['host'],
            Config::get('mysql')['user'],
            Config::get('mysql')['pass'],
            Config::get('mysql')['db']
        );
    }

    /**
     * Distance between two points on Earth using the Vincenty formula
     *
     * @param float $latFrom     Start point latitude (degrees decimal)
     * @param float $lonFrom     Start point longitude (degrees decimal)
     * @param float $latTo       End point latitude (degrees decimal)
     * @param float $lonTo       End point longitude (degrees decimal)
     * @param float $earthRadius Earth radius (metres)
     * @return float             Distance between points in metres
     */
    public function distanceOnEarthSurface(float $latFrom, float $lonFrom, float $latTo, float $lonTo, $earthRadius = 6371000)
    {
        $latFrom = deg2rad($latFrom);
        $lonFrom = deg2rad($lonFrom);
        $latTo   = deg2rad($latTo);
        $lonTo   = deg2rad($lonTo);

        $lonDelta = $lonTo - $lonFrom;

        $a = pow(cos($latTo) * sin($lonDelta), 2) +
            pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);

        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

        $angle = atan2(sqrt($a), $b);

        return $angle * $earthRadius;
    }

    /**
     * Calculating flight duration from flight distance
     *
     * @param $distance
     * @return int
     */
    public function getDistanceFromDuration($distance): int
    {
        $speed = [750, 900];

        $time    = $distance / rand($speed[0], $speed[1]);
        $hours   = intval($time);
        $minutes = ($time - $hours) * 60;

        return round($time * 60);
    }

    /**
     * Generating faking random flight number
     *
     * @return int
     * @throws \Exception
     */
    public function fakeFlightNumber(): int
    {
        $check = 0;

        while (true) {
            $check++;

            $flightNumber = rand(1, self::FLIGHT_NUMBERS_POOL);

            $this->db->where('airline', $this->getAirline());
            $this->db->where('number', $flightNumber);
            // $this->db->where('departure_time', 'John%', 'like'); // TODO: add date check
            $this->db->get('flights');

            // If we have the same airline with the same flight number in this date - we skip it
            if ($this->db->count != 1) {
                return $flightNumber;
            }

            if ($check > self::ATTEMPTS_LIMIT) {
                throw new \Exception("All flight numbers is given. Limit per one airline is " . self::FLIGHT_NUMBERS_POOL);
            }
        }
    }

}