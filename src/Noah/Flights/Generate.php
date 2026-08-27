<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: 'flights:add',
    description: 'Generate flights to database.',
    aliases: ['flights:generate'],
    hidden: false,
)]

class Generate extends AbstractCommand
{
    private const FLIGHTS_COUNT = 10000;
    private const NUMBERS_POOL = 9999;
    private const PRICE_MULTIPLIER = 8;
    private const PRICE_ADD_DOLLARS = [5, 800];
    private const PRICE_TAX_PERCENT = [5, 90];
    private const DURATION_ADD_KM = [10, 55];
    private const DATE_ADD_DAYS = [1, 90];
    private const FLIGHT_SPEED_KMH = [700, 900];

    private const PROGRESS_FORMAT = " %current%/%max% %bar% %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory%\n %message%";
    private const PROGRESS_CHARACTER_EMPTY = '<fg=default>░</>';
    private const PROGRESS_CHARACTER_CURRENT = '<fg=green>▓</>';
    private const PROGRESS_CHARACTER_DONE = '<fg=green>▓</>';

    private const PROGRESS_MSG_BREAK = 300;
    private const PROGRESS_MSG_FORMAT = '> %s...';

    private const PROGRESS_MSG_POOL = [
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

    private const COUNT_DUPLICATES = 'Deleted duplicate flights';
    private const COUNT_TOTAL = 'Total added';

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

    protected function configure(): void
    {
        $this->addArgument('flights', InputArgument::OPTIONAL, 'Flights to add');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // If flights to add not provided – ask
        $flightsToAdd = $input->getArgument('flights') ?? $this->io->ask(
            'Number of flights to add',
            self::FLIGHTS_COUNT,
            function (string $number): int {
                if (!is_numeric($number)) {
                    throw new RuntimeException('You must type a number.');
                }
                return (int) $number;
            },
        );

        if (! is_numeric($flightsToAdd) || (int) $flightsToAdd < 1) {
            $this->io->error('The "flights" argument must be a positive number.');

            return Command::INVALID;
        }

        $flightsToAdd = (int) $flightsToAdd;

        // Get airlines from a database
        $airlinesResponse = $this->db->get('airlines');

        // Get enabled airports from a database
        $this->db->where('enabled', 1);
        $this->db->where('is_major', 1);
        $airportsResponse = $this->db->get('airports');

        // Show the progress bar
        $progressBar = new ProgressBar($output, $flightsToAdd);
        $progressBar->setBarCharacter(self::PROGRESS_CHARACTER_DONE);
        $progressBar->setEmptyBarCharacter(self::PROGRESS_CHARACTER_EMPTY);
        $progressBar->setProgressCharacter(self::PROGRESS_CHARACTER_CURRENT);
        $progressBar->setFormat(self::PROGRESS_FORMAT);
        $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, 'Starting'));
        $progressBar->start();

        // Do the magic
        while ($this->count[self::COUNT_TOTAL] < $flightsToAdd) {
            $this->count[self::COUNT_TOTAL]++;

            // Get 2 random airports. Depart and arrive airports should be different
            shuffle($airportsResponse);
            $airportKey = array_rand($airportsResponse, 2);

            // Depart airport code
            $this->setDepartAirportData($airportsResponse[$airportKey[0]]);
            // Arrive airport code
            $this->setArriveAirportData($airportsResponse[$airportKey[1]]);
            // Get random airline
            $this->setAirline($airlinesResponse[rand(0, count($airlinesResponse) - 1)]['code']);

            // Calculating flight distance between airports
            $this->setDistance(
                intval(
                    $this->distanceOnEarthSurface(
                        (float) $this->departAirport['latitude'],
                        (float) $this->departAirport['longitude'],
                        (float) $this->arriveAirport['latitude'],
                        (float) $this->arriveAirport['longitude'],
                    ) / 1000,
                ),
            );

            // Calculating flight duration between airports
            $this->setDuration($this->getDurationFromDistance($this->distance) + Helper::random(self::DURATION_ADD_KM));

            // Render departure date and time (UNIX timestamps for random day)
            $this->setDepartureDateTime(
                date(
                    'Y-m-d H:i:s',
                    strtotime(
                        sprintf('+ %d days', Helper::random(self::DATE_ADD_DAYS)),
                        rand(
                            strtotime(date('Y-m-d') . ' 00:00:01'),
                            strtotime(date('Y-m-d') . ' 23:59:59'),
                        ),
                    ),
                ),
            );

            // Calculating arrival date and time
            $this->setArrivalDateTime(
                $this->calculateArriveTime(
                    $this->departureDateTime,
                    $this->departAirport['timezone_name'],
                    $this->arriveAirport['timezone_name'],
                    $this->duration,
                ),
            );

            // Faking base price
            $this->setPriceBase(($this->distance * self::PRICE_MULTIPLIER / 100) + Helper::random(self::PRICE_ADD_DOLLARS));

            // Calculate tax price
            $this->setPriceTax($this->priceBase * (Helper::random(self::PRICE_TAX_PERCENT) / 100));

            // Faking flight rating
            $this->setRating(rand(1, 4) + rand(0, 100) / 100);

            // Faking flight number
            $this->fakeFlightNumber();

            // Inserting row to a MySQL table
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
                    'rating',
                ],
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

        $this->removeDuplicates();

        // Show statistic
        $this->io->writeln('<primary> Summary: </primary>');
        foreach ($this->count as $key => $count) {
            $this->formatOutput($key, number_format((int) $count), 'info');
        }

        // Total rows in the flight table
        $this->formatOutput('Total in Database', number_format((int) $this->db->getValue('flights', 'count(1)')), 'info', true);

        return Command::SUCCESS;
    }

    /**
     * Distance between two points on Earth using the Vincenty formula
     *
     * @param float $latFrom Start point latitude (degrees decimal)
     * @param float $lonFrom Start point longitude (degrees decimal)
     * @param float $latTo End point latitude (degrees decimal)
     * @param float $lonTo End point longitude (degrees decimal)
     * @return float|int Distance between points in metres
     */
    private function distanceOnEarthSurface(float $latFrom, float $lonFrom, float $latTo, float $lonTo): float
    {
        $earthRadius = 6371000;

        $latFrom = deg2rad($latFrom);
        $lonFrom = deg2rad($lonFrom);
        $latTo = deg2rad($latTo);
        $lonTo = deg2rad($lonTo);

        $lonDelta = $lonTo - $lonFrom;

        $a = pow(cos($latTo) * sin($lonDelta), 2) +
            pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);

        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

        $angle = atan2(sqrt($a), $b);

        return $angle * $earthRadius;
    }

    /**
     * Calculate flight duration (in minutes) from flight distance.
     *
     * @param float|int $distance Flight distance in kilometers
     * @return int Duration in minutes
     */
    private function getDurationFromDistance(float|int $distance): int
    {
        if ($distance <= 0) {
            return 0;
        }

        $speedKmh = Helper::random(self::FLIGHT_SPEED_KMH);
        if ($speedKmh <= 0) {
            throw new RuntimeException("Flight speed must be greater than zero.");
        }

        $timeHours = $distance / $speedKmh;
        $timeMinutes = $timeHours * 60;

        return (int) round($timeMinutes);
    }

    /**
     * Calculate arrival local time given a departure time, timezones, and duration in minutes.
     *
     * @param DateTimeInterface|string $departDateTime  Departure datetime (object or parseable string)
     * @param DateTimeZone|string $departTimezone  Departure timezone (object or IANA string)
     * @param DateTimeZone|string $arriveTimezone  Arrival timezone (object or IANA string)
     * @param int $durationMinutes Flight duration in minutes (can be negative)
     * @return string Arrival datetime formatted as 'Y-m-d H:i'
     * @throws Exception If inputs are invalid (e.g., bad timezone or date string)
     */
    private function calculateArriveTime(
        DateTimeInterface|string $departDateTime,
        DateTimeZone|string $departTimezone,
        DateTimeZone|string $arriveTimezone,
        int $durationMinutes,
    ): string {
        try {
            $tzDepart = $departTimezone instanceof DateTimeZone ? $departTimezone : new DateTimeZone((string) $departTimezone);
            $tzArrive = $arriveTimezone instanceof DateTimeZone ? $arriveTimezone : new DateTimeZone((string) $arriveTimezone);

            // Normalize departure to an immutable instance in the specified departure TZ
            if ($departDateTime instanceof DateTimeInterface) {
                // Rebase via timestamp to avoid double-parsing and then set the intended depart TZ
                $depart = (new DateTimeImmutable('@' . $departDateTime->getTimestamp()))->setTimezone($tzDepart);
            } else {
                $depart = new DateTimeImmutable((string) $departDateTime, $tzDepart);
            }

            // Add minutes (supports negative durations), then convert to arrival TZ
            $arrival = $depart
                ->modify(sprintf('%+d minutes', $durationMinutes))
                ->setTimezone($tzArrive);

            return $arrival->format('Y-m-d H:i');
        } catch (Throwable $e) {
            // Re-throw as Exception to match signature while preserving context
            throw new Exception('Unable to calculate arrival time: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws Exception
     */
    private function fakeFlightNumber(): void
    {
        $flightNumber = rand(1, self::NUMBERS_POOL);
        $this->setFlightNumber($flightNumber);
    }

    private function getRandomProgressMessage(): string
    {
        return self::PROGRESS_MSG_POOL[rand(0, count(self::PROGRESS_MSG_POOL) - 1)];
    }

    /**
     * @throws Exception
     */
    private function removeDuplicates(): void
    {
        $tempTable = 'TempTable';

        $progressIndicator = new ProgressIndicator($this->output, 'very_verbose', 100, ['>','>']);
        $progressIndicator->start('Deleting duplicates...');

        // 1. Creating a temporary table to keep duplicates
        $progressIndicator->advance();
        $query = sprintf(
            'CREATE TEMPORARY TABLE %s AS
            SELECT airline, number, DATE(departure_time) AS flight_date, MIN(id) AS min_id
            FROM flights
            GROUP BY airline, number, DATE(departure_time)
            HAVING COUNT(*) > 1;',
            $tempTable,
        );
        $this->db->rawQueryOne($query);

        $progressIndicator->advance();
        $this->count[self::COUNT_DUPLICATES] = $this->db->getValue($tempTable, 'count(*)');
        $this->count[self::COUNT_TOTAL] -= $this->count[self::COUNT_DUPLICATES];

        // 2. Deleting duplicate rows from the flight table
        $progressIndicator->advance();
        $query = sprintf(
            'DELETE flight FROM flights flight
            JOIN %s temp ON
            flight.airline = temp.airline AND flight.number = temp.number AND
            DATE(flight.departure_time) = temp.flight_date
            WHERE flight.id <> temp.min_id',
            $tempTable,
        );
        $this->db->rawQueryOne($query);

        // 3. Deleting temporary table
        $progressIndicator->advance();
        $query = sprintf(
            'DROP TEMPORARY TABLE IF EXISTS %s',
            $tempTable,
        );
        $this->db->rawQueryOne($query);

        $progressIndicator->finish('Done');

        $this->io->newLine();
    }

    private function setAirline(string $airlineCode): void
    {
        $this->airline = $airlineCode;
    }

    private function setFlightNumber(int $flightNumber): void
    {
        $this->flightNumber = $flightNumber;
    }

    private function setDepartAirportData(array $data): void
    {
        $this->departAirport = $data;
    }

    private function setArriveAirportData(array $data): void
    {
        $this->arriveAirport = $data;
    }

    private function setDepartureDateTime(string $dateTime): void
    {
        $this->departureDateTime = $dateTime;
    }

    private function setArrivalDateTime(string $dateTime): void
    {
        $this->arrivalDateTime = $dateTime;
    }

    private function setDistance(int $distance): void
    {
        $this->distance = $distance;
    }

    private function setDuration(int $duration): void
    {
        $this->duration = $duration;
    }

    private function setPriceBase(float $amount): void
    {
        $this->priceBase = $amount;
    }

    private function setPriceTax(float $amount): void
    {
        $this->priceTax = $amount;
    }

    private function setRating(float $rating): void
    {
        $this->rating = $rating;
    }
}
