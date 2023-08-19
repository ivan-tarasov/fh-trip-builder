<?php

namespace TripBuilder\Noah\Flights;

use Symfony\Component\Console\Command\Command;
use TripBuilder\Noah\AbstractCommand;

class Generate extends AbstractCommand
{
    /**
     * The name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'flights:add';

    /**
     * The command description shown when running `list` command.
     *
     * @var string
     */
    protected static $defaultDescription = 'Generate flights to database';

    const DEFAULT_FLIGHTS_COUNT       = 1000;
    const FLIGHT_NUMBERS_POOL = 9999;
    const ATTEMPTS_LIMIT      = 10;
    const PRICE_MULTIPLIER    = 8;
    const PRICE_ADD_MIN       = 5;
    const PRICE_ADD_MAX       = 800;
    const PRICE_TAX_PERCENT   = 10;
    const DURATION_ADD_MIN    = 10;
    const DURATION_ADD_MAX    = 55;
    const DATE_ADD_MIN        = 1;
    const DATE_ADD_MAX        = 30;

    const COUNT_IDENTICAL_AIRPORTS = 'identical_airports',
          COUNT_SAME_FLIGHT_NUMBER = 'same_flight_number',
          COUNT_TOTAL = 'total';

    private array $count = [
        self::COUNT_IDENTICAL_AIRPORTS => 0,
        self::COUNT_SAME_FLIGHT_NUMBER => 0,
        self::COUNT_TOTAL => 0,
    ];

    private string $airline;

    private int $flightNumber;

    private array $departAirport;

    private array $arriveAirport;

    private string $departureDateTime;

    private string $arrivalDateTime;

    private int $distance;

    private int $duration;

    private float $priceBase;

    private float $priceTax;

    private float $rating;

    /**
     * Execute the command
     *
     * @param  $input
     * @param  $output
     * @return int 0 if everything went fine, or an exit code.
     * @throws \Exception
     */
    protected function execute($input, $output): int
    {
        $flightsToAdd = $this->io->ask('Number of flights to add', self::DEFAULT_FLIGHTS_COUNT, function (string $number): int {
            if (!is_numeric($number)) {
                throw new \RuntimeException('You must type a number.');
            }

            return (int) $number;
        });

        // Get airlines from database
        $airlinesResponse = $this->db->get('airlines');

        // Get enabled airports from database
        $this->db->where('enabled', 1);
        $airportsResponse = $this->db->get('airports');

        // Do the magic
        for ($flightNumber = 1; $flightNumber <= $flightsToAdd; $flightNumber++) {
            // Get 2 random airports
            $rand_keys = array_rand($airportsResponse, 2);

            // Departure airport and arrival airport SHOULD be different
            if ($rand_keys[0] === $rand_keys[1]) {
                $this->count[self::COUNT_IDENTICAL_AIRPORTS]++;
                continue;
            }

            // Get random airline
            $this->setAirline($airlinesResponse[rand(0, count($airlinesResponse) - 1)]['code']);

            // Faking flight number
            $this->fakeFlightNumber();

            // Depart airport code
            $this->setDepartAirport($airportsResponse[$rand_keys[0]]);

            // Arrive airport code
            $this->setArriveAirport($airportsResponse[$rand_keys[1]]);

            // Calculating flight distance between airports
            $this->setDistance(
                intval($this->distanceOnEarthSurface(
                        $this->departAirport['latitude'],
                        $this->departAirport['longitude'],
                        $this->arriveAirport['latitude'],
                        $this->arriveAirport['longitude'],
                    ) / 1000)
            );

            // Calculating flight duration between airports
            $this->setDuration(
                $this->getDistanceFromDuration($this->distance) +
                rand(self::DURATION_ADD_MIN, self::DURATION_ADD_MAX)
            );

            // Render departure date and time (UNIX timestamps for random day)
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
                    strtotime(sprintf('+ %d minutes', $this->duration), strtotime($this->departureDateTime))
                )
            );

            // Faking base price
            $this->setPriceBase(
                ($this->distance * self::PRICE_MULTIPLIER / 100) + rand(self::PRICE_ADD_MIN, self::PRICE_ADD_MAX)
            );

            // Calculate tax price
            $this->setPriceTax($this->priceBase * (self::PRICE_TAX_PERCENT / 100));

            // Faking flight rating
            $this->setRating(rand(1, 4) + rand(0, 100) / 100);

            // Inserting row to MySQL table
            if (! $this->db->insertMulti(
                'flights',
                [
                    [
                        $this->airline,
                        $this->flightNumber,
                        $this->departAirport['code'],
                        $this->departureDateTime,
                        $this->arriveAirport['code'],
                        $this->arrivalDateTime,
                        $this->distance,
                        $this->duration,
                        $this->priceBase,
                        $this->priceTax,
                        $this->rating,
                    ],
                ],
                [
                    'airline',
                    'number',
                    'departure_airport',
                    'departure_time',
                    'arrival_airport',
                    'arrival_time',
                    'distance',
                    'duration',
                    'price_base',
                    'price_tax',
                    'rating'
                ]
            )) {
                echo 'insert failed: ' . $this->db->getLastError();
            }

            $this->count['total']++;
        }

        foreach ($this->count as $key => $count) {
            $this->formatOutput($key, number_format($count), 'info');
        }

        return Command::SUCCESS;
    }

    /**
     * Distance between two points on Earth using the Vincenty formula
     *
     * @param float $latFrom Start point latitude (degrees decimal)
     * @param float $lonFrom Start point longitude (degrees decimal)
     * @param float $latTo   End point latitude (degrees decimal)
     * @param float $lonTo   End point longitude (degrees decimal)
     * @return float|int     Distance between points in metres
     */
    public function distanceOnEarthSurface(float $latFrom, float $lonFrom, float $latTo, float $lonTo): float|int
    {
        $earthRadius = 6371000;

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
     * @throws \Exception
     */
    public function fakeFlightNumber(): void
    {
        $check = 0;

        while (true) {
            $check++;

            $flightNumber = rand(1, self::FLIGHT_NUMBERS_POOL);

            $this->db->where('airline', $this->airline);
            $this->db->where('number', $flightNumber);
            // $this->db->where('departure_time', 'John%', 'like'); // TODO: add date check
            $this->db->get('flights');

            // If we have the same airline with the same flight number in this date - we skip it
            if ($this->db->count != 1) {
                $this->setFlightNumber($flightNumber);
                return;
            }

            $this->count[self::COUNT_SAME_FLIGHT_NUMBER]++;

            if ($check > self::ATTEMPTS_LIMIT) {
                throw new \Exception('All flight numbers is given. Limit per one airline is ' . self::FLIGHT_NUMBERS_POOL);
            }
        }
    }

    /**
     * @param $airlineCode
     * @return void
     */
    private function setAirline($airlineCode): void
    {
        $this->airline = $airlineCode;
    }

    /**
     * @param $flightNumber
     * @return void
     */
    private function setFlightNumber($flightNumber): void
    {
        $this->flightNumber = $flightNumber;
    }

    /**
     * @param $airport
     * @return void
     */
    private function setDepartAirport($airport): void
    {
        $this->departAirport = $airport;
    }

    /**
     * @param $airport
     * @return void
     */
    private function setArriveAirport($airport): void
    {
        $this->arriveAirport = $airport;
    }

    /**
     * @param string $dateTime
     * @return void
     */
    private function setDepartureDateTime(string $dateTime): void
    {
        $this->departureDateTime = $dateTime;
    }

    /**
     * @param string $dateTime
     * @return void
     */
    private function setArrivalDateTime(string $dateTime): void
    {
        $this->arrivalDateTime = $dateTime;
    }

    /**
     * @param int $distance
     * @return void
     */
    private function setDistance(int $distance): void
    {
        $this->distance = $distance;
    }

    /**
     * @param int $duration
     * @return void
     */
    private function setDuration(int $duration): void
    {
        $this->duration = $duration;
    }

    /**
     * @param $amount
     * @return void
     */
    private function setPriceBase($amount): void
    {
        $this->priceBase = $amount;
    }

    /**
     * @param $amount
     * @return void
     */
    private function setPriceTax($amount): void
    {
        $this->priceTax = $amount;
    }

    /**
     * @param $rating
     * @return void
     */
    private function setRating($rating): void
    {
        $this->rating = $rating;
    }

}
