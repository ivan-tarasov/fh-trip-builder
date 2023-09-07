<?php

namespace TripBuilder\Noah\Flights;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name:        'flights:add',
    description: 'Generate flights to database.',
    aliases:     ['flights:generate'],
    hidden:      false
)]

class Generate extends AbstractCommand
{
    const FLIGHTS_COUNT    = 10000;
    const NUMBERS_POOL     = 9999;
    const PRICE_MULTIPLIER = 8;
    const PRICE_ADD        = [5, 800];
    const PRICE_TAX        = [5, 90];
    const DURATION_ADD     = [10, 55];
    const DATE_ADD         = [1, 90];
    const FLIGHT_SPEED     = [700, 900];

    const PROGRESS_FORMAT = " %current%/%max% %bar% %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory%\n %message%",
          PROGRESS_CHARACTER_EMPTY = '<fg=default>░</>',
          PROGRESS_CHARACTER_CURRENT = '<fg=green>▓</>',
          PROGRESS_CHARACTER_DONE = '<fg=green>▓</>';

    const PROGRESS_MSG_BREAK = 300,
          PROGRESS_MSG_FORMAT = '> %s...';

    const PROGRESS_MSG_POOL = [
        'Raising the ailerons',
        'Removing the flaps',
        'Removing the chassis',
        'Refueling the fuel',
        'Distributing snacks',
        'Selling the tickets',
        'Passing registration',
        'Starting taxiing',
        'Joining the "10k" club',
    ];

    const COUNT_DUPLICATES = 'Deleted duplicate flights',
          COUNT_TOTAL      = 'Total added';

    private array $count = [
        self::COUNT_DUPLICATES => 0,
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
     * Configure command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('flights', InputArgument::OPTIONAL, 'Flights to add');
    }

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
        // If flights to add not provided – ask
        $flightsToAdd = $input->getArgument('flights') ?? $this->io->ask(
            'Number of flights to add', self::FLIGHTS_COUNT, function (string $number): int {
                if (!is_numeric($number)) {
                    throw new \RuntimeException('You must type a number.');
                }

                return (int) $number;
            }
        );

        // Get airlines from database
        $airlinesResponse = $this->db->get('airlines');

        // Get enabled airports from database
        $this->db->where('enabled', 1);
        $this->db->where('is_major', 1);
        $airportsResponse = $this->db->get('airports');

        // Show progress bar
        $progressBar = new ProgressBar($output, $flightsToAdd);
        $progressBar->setBarCharacter(self::PROGRESS_CHARACTER_DONE);
        $progressBar->setEmptyBarCharacter(self::PROGRESS_CHARACTER_EMPTY);
        $progressBar->setProgressCharacter(self::PROGRESS_CHARACTER_CURRENT);
        $progressBar->setFormat(self::PROGRESS_FORMAT);
        $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, 'Starting'));
        $progressBar->start();

        // Do the magic..
        while (++$this->count[self::COUNT_TOTAL] < $flightsToAdd) {
            // Get 2 random airports. Depart and arrive airports should be different
            shuffle($airportsResponse);
            $airkey = array_rand($airportsResponse, 2);

            // Depart airport code
            $this->setDepartAirport($airportsResponse[$airkey[0]]);

            // Arrive airport code
            $this->setArriveAirport($airportsResponse[$airkey[1]]);

            // Get random airline
            $this->setAirline($airlinesResponse[rand(0, count($airlinesResponse) - 1)]['code']);

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
            $this->setDuration($this->getDistanceFromDuration($this->distance) + Helper::random(self::DURATION_ADD));

            // Render departure date and time (UNIX timestamps for random day)
            $this->setDepartureDateTime(
                date('Y-m-d H:i:s',
                    strtotime(
                        sprintf('+ %d days', Helper::random(self::DATE_ADD)),
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
            $this->setPriceBase(($this->distance * self::PRICE_MULTIPLIER / 100) + Helper::random(self::PRICE_ADD));

            // Calculate tax price
            $this->setPriceTax($this->priceBase * (Helper::random(self::PRICE_TAX) / 100));

            // Faking flight rating
            $this->setRating(rand(1, 4) + rand(0, 100) / 100);

            // Faking flight number
            $this->fakeFlightNumber();

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

            // Show random messages every X loop
            if ($this->count[self::COUNT_TOTAL] % self::PROGRESS_MSG_BREAK == 0) {
                $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, $this->getRandomProgressMessage()));
            }

            $progressBar->advance();
        }

        $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, 'Landing'));
        $progressBar->finish();

        $this->io->newLine(2);

        // Deleting duplicates
        $this->removeDuplicates();

        // Show statistic
        $this->io->writeln('<primary> Summary: </primary>');
        foreach ($this->count as $key => $count) {
            $this->formatOutput($key, number_format($count), 'info');
        }

        // Total rows in flights table
        $this->formatOutput('Total in Database', number_format($this->db->getValue('flights', 'count(1)')), 'info', true);

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
        $time    = $distance / Helper::random(self::FLIGHT_SPEED);
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
        $flightNumber = rand(1000, self::NUMBERS_POOL);

        $this->setFlightNumber($flightNumber);
    }

    /**
     * @return string
     */
    private function getRandomProgressMessage(): string
    {
        return self::PROGRESS_MSG_POOL[rand(0,count(self::PROGRESS_MSG_POOL)-1)];
    }

    private function removeDuplicates()
    {
        $tempTable = 'TempTable';

        $progressIndicator = new ProgressIndicator($this->output, 'very_verbose', 100, ['>','>']);
        $progressIndicator->start('Deleting duplicates...');

        // 1. Creating temporary table to keep duplicates
        $progressIndicator->advance();
        $query = sprintf(
            'CREATE TEMPORARY TABLE %s AS
            SELECT airline, number, DATE(departure_time) AS flight_date, MIN(id) AS min_id
            FROM flights
            GROUP BY airline, number, DATE(departure_time)
            HAVING COUNT(*) > 1;',
            $tempTable
        );
        $this->db->rawQueryOne($query);

        $progressIndicator->advance();
        $this->count[self::COUNT_DUPLICATES] = $this->db->getValue($tempTable, 'count(*)');
        $this->count[self::COUNT_TOTAL] -= $this->count[self::COUNT_DUPLICATES];

        // 2. Deleting duplicate rows from flights table
        $progressIndicator->advance();
        $query = sprintf(
            'DELETE flight FROM flights flight
            JOIN %s temp ON
            flight.airline = temp.airline AND flight.number = temp.number AND
            DATE(flight.departure_time) = temp.flight_date
            WHERE flight.id <> temp.min_id',
            $tempTable
        );
        $this->db->rawQueryOne($query);

        // 3. Deleting temporary table
        $progressIndicator->advance();
        $query = sprintf(
            'DROP TEMPORARY TABLE IF EXISTS %s',
            $tempTable
        );
        $this->db->rawQueryOne($query);

        $progressIndicator->finish('Done');

        $this->io->newLine();
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
