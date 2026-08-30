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
use TripBuilder\Database\Table;
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
    private const int FLIGHTS_COUNT = 10000;
    private const int NUMBERS_POOL = 9999;
    private const int PRICE_MULTIPLIER = 8;
    private const array PRICE_ADD_DOLLARS = [5, 800];

    // Nonstop convenience premium: a convex (distance^2) surcharge so a single
    // long leg is priced above two shorter legs covering the same route —
    // mirroring real fares where nonstops carry a premium, which makes the
    // cheapest itinerary often a connection. Bounded (max ~4000 at the
    // reference distance) so price_base stays within decimal(6,2).
    private const int PRICE_NONSTOP_PREMIUM_MAX = 4000;
    private const int PRICE_PREMIUM_REF_KM = 20000;
    private const array PRICE_TAX_PERCENT = [5, 90];
    private const array DURATION_ADD_KM = [10, 55];
    private const array DATE_ADD_DAYS = [1, 90];
    private const array FLIGHT_SPEED_KMH = [700, 900];

    private const string PROGRESS_FORMAT = " %current%/%max% %bar% %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory%\n %message%";
    private const string PROGRESS_CHARACTER_EMPTY = '<fg=default>░</>';
    private const string PROGRESS_CHARACTER_CURRENT = '<fg=green>▓</>';
    private const string PROGRESS_CHARACTER_DONE = '<fg=green>▓</>';

    private const int PROGRESS_MSG_BREAK = 300;
    private const string PROGRESS_MSG_FORMAT = '> %s...';

    private const array PROGRESS_MSG_POOL = [
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

    private const string COUNT_DUPLICATES = 'Deleted duplicate flights';
    private const string COUNT_TOTAL = 'Total added';

    // How quickly route traffic falls off with distance: at this many km a
    // route carries half the flights an adjacent-airport route of the same
    // size would. Keeps short-haul frequent without starving long-haul.
    private const int ROUTE_DISTANCE_HALVING_KM = 2000;

    private const int INSERT_BATCH_SIZE = 500;

    private array $count = [
        self::COUNT_DUPLICATES => 0,
        self::COUNT_TOTAL => 0,
    ];

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
            (string) self::FLIGHTS_COUNT,
            function (string $number): int {
                if (!is_numeric($number)) {
                    throw new RuntimeException('You must type a number.');
                }
                return (int) $number;
            },
        );

        if (!is_numeric($flightsToAdd) || (int) $flightsToAdd < 1) {
            $this->io->error('The "flights" argument must be a positive number.');

            return Command::INVALID;
        }

        $flightsToAdd = (int) $flightsToAdd;

        // Get airlines and enabled major airports from the database
        $airlines = $this->connection()->fetchAll('SELECT * FROM ' . Table::Airlines->value);
        $airports = $this->connection()->fetchAll(
            'SELECT * FROM ' . Table::Airports->value
            . ' WHERE enabled = 1 AND is_major = 1 AND traffic_weight > 0',
        );

        if (count($airports) < 2) {
            $this->io->error('Need at least two airports with a traffic weight to build a network.');

            return Command::INVALID;
        }

        // Precompute how the network is shaped before generating anything (see
        // routeDistribution): which route each flight takes is then a single
        // lookup, and the distance for it is already known.
        [$routes, $cumulative, $distances, $totalWeight] = $this->routeDistribution($airports);

        // Show the progress bar
        $progressBar = new ProgressBar($output, $flightsToAdd);
        $progressBar->setBarCharacter(self::PROGRESS_CHARACTER_DONE);
        $progressBar->setEmptyBarCharacter(self::PROGRESS_CHARACTER_EMPTY);
        $progressBar->setProgressCharacter(self::PROGRESS_CHARACTER_CURRENT);
        $progressBar->setFormat(self::PROGRESS_FORMAT);
        $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, 'Starting'));
        $progressBar->start();

        // Do the magic
        $flights = [];

        while ($this->count[self::COUNT_TOTAL] < $flightsToAdd) {
            $this->count[self::COUNT_TOTAL]++;

            // Pick a route in proportion to how much traffic it should carry,
            // so hubs and trunk routes get the flights they would in reality.
            $route = $this->pickRoute($cumulative, $totalWeight);
            $airportCount = count($airports);
            $departAirport = $airports[intdiv($routes[$route], $airportCount)];
            $arriveAirport = $airports[$routes[$route] % $airportCount];
            $airline = $airlines[rand(0, count($airlines) - 1)]['code'];

            // Already measured while building the distribution.
            $distance = $distances[$route];

            // Calculating flight duration between airports
            $duration = $this->getDurationFromDistance($distance) + Helper::random(self::DURATION_ADD_KM);

            // Render departure date and time (UNIX timestamps for random day)
            $departureDateTime = date(
                'Y-m-d H:i:s',
                strtotime(
                    sprintf('+ %d days', Helper::random(self::DATE_ADD_DAYS)),
                    rand(
                        strtotime(date('Y-m-d') . ' 00:00:01'),
                        strtotime(date('Y-m-d') . ' 23:59:59'),
                    ),
                ),
            );

            // Base price: distance-linear fare + a convex nonstop premium (so a
            // direct leg is dearer than two shorter connecting legs), then tax
            // as a percentage of that same base.
            $nonstopPremium = self::PRICE_NONSTOP_PREMIUM_MAX * ($distance / self::PRICE_PREMIUM_REF_KM) ** 2;
            $priceBase = ($distance * self::PRICE_MULTIPLIER / 100) + $nonstopPremium + Helper::random(self::PRICE_ADD_DOLLARS);
            $priceTax = $priceBase * (Helper::random(self::PRICE_TAX_PERCENT) / 100);

            $flights[] = new Flight(
                airline: $airline,
                number: rand(1, self::NUMBERS_POOL),
                departureAirport: $departAirport['code'],
                departureTime: $departureDateTime,
                arrivalAirport: $arriveAirport['code'],
                arrivalTime: $this->calculateArriveTime(
                    $departureDateTime,
                    $departAirport['timezone_name'],
                    $arriveAirport['timezone_name'],
                    $duration,
                ),
                distance: $distance,
                duration: $duration,
                priceBase: $priceBase,
                priceTax: $priceTax,
                rating: rand(1, 4) + rand(0, 100) / 100,
            );

            // Show random messages every X loop
            if ($this->count[self::COUNT_TOTAL] % self::PROGRESS_MSG_BREAK == 0) {
                $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, $this->getRandomProgressMessage()));
            }

            $progressBar->advance();
        }

        $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, 'Landing'));
        $progressBar->finish();

        $this->insertFlights($flights);

        $this->io->newLine(2);

        $this->removeDuplicates();

        // Show statistic
        $this->io->writeln('<primary> Summary: </primary>');
        foreach ($this->count as $key => $count) {
            $this->formatOutput($key, number_format((int) $count), 'info');
        }

        // Total rows in the flight table
        $this->formatOutput(
            'Total in Database',
            number_format((int) $this->connection()->fetchValue('SELECT count(1) FROM ' . Table::Flights->value)),
            'info',
            true,
        );

        return Command::SUCCESS;
    }

    /**
     * Batch-insert generated flights inside a single transaction.
     *
     * @param list<Flight> $flights
     */
    private function insertFlights(array $flights): void
    {
        if ($flights === []) {
            return;
        }

        $columns = Flight::columns();
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $connection = $this->connection();

        $connection->beginTransaction();

        try {
            foreach (array_chunk($flights, self::INSERT_BATCH_SIZE) as $chunk) {
                $sql = 'INSERT INTO ' . Table::Flights->value . ' (' . implode(', ', $columns) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), $rowPlaceholder));

                $params = [];
                foreach ($chunk as $flight) {
                    foreach ($flight->toValues() as $value) {
                        $params[] = $value;
                    }
                }

                $connection->execute($sql, $params);
            }

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * Build the route distribution: every ordered pair of airports, weighted by
     * a gravity model — the product of the two airports' traffic weights,
     * divided by how far apart they are. Big airports close together (JFK-BOS,
     * JFK-LAX) end up with many daily flights; small airports far apart end up
     * with almost none, which is the shape a real network has and the reason
     * connections exist at all.
     *
     * Returns the packed pair index, the cumulative weights to sample from, the
     * distance of each pair in km, and the total weight.
     *
     * @param list<array<string, mixed>> $airports
     * @return array{0: list<int>, 1: list<float>, 2: list<int>, 3: float}
     */
    private function routeDistribution(array $airports): array
    {
        $count = count($airports);
        $routes = [];
        $cumulative = [];
        $distances = [];
        $running = 0.0;

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }

                $distance = (int) ($this->distanceOnEarthSurface(
                    (float) $airports[$i]['latitude'],
                    (float) $airports[$i]['longitude'],
                    (float) $airports[$j]['latitude'],
                    (float) $airports[$j]['longitude'],
                ) / 1000);

                $running += (int) $airports[$i]['traffic_weight'] * (int) $airports[$j]['traffic_weight']
                    / (1 + $distance / self::ROUTE_DISTANCE_HALVING_KM);

                $routes[] = $i * $count + $j;
                $cumulative[] = $running;
                $distances[] = $distance;
            }
        }

        return [$routes, $cumulative, $distances, $running];
    }

    /**
     * Sample one route from the cumulative weights (binary search).
     *
     * @param list<float> $cumulative
     */
    private function pickRoute(array $cumulative, float $totalWeight): int
    {
        $target = mt_rand() / mt_getrandmax() * $totalWeight;

        $low = 0;
        $high = count($cumulative) - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($cumulative[$mid] < $target) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
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
                $depart = new DateTimeImmutable('@' . $departDateTime->getTimestamp())->setTimezone($tzDepart);
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

        // A single PDO connection so the TEMPORARY TABLE stays visible across steps.
        $connection = $this->connection();

        // 1. Creating a temporary table to keep duplicates
        $progressIndicator->advance();
        $connection->pdo()->exec(sprintf(
            'CREATE TEMPORARY TABLE %s AS
            SELECT airline, number, DATE(departure_time) AS flight_date, MIN(id) AS min_id
            FROM ' . Table::Flights->value . '
            GROUP BY airline, number, DATE(departure_time)
            HAVING COUNT(*) > 1;',
            $tempTable,
        ));

        $progressIndicator->advance();
        $this->count[self::COUNT_DUPLICATES] = (int) $connection->fetchValue('SELECT count(*) FROM ' . $tempTable);
        $this->count[self::COUNT_TOTAL] -= $this->count[self::COUNT_DUPLICATES];

        // 2. Deleting duplicate rows from the flight table
        $progressIndicator->advance();
        $connection->pdo()->exec(sprintf(
            'DELETE flight FROM ' . Table::Flights->value . ' flight
            JOIN %s temp ON
            flight.airline = temp.airline AND flight.number = temp.number AND
            DATE(flight.departure_time) = temp.flight_date
            WHERE flight.id <> temp.min_id',
            $tempTable,
        ));

        // 3. Deleting temporary table
        $progressIndicator->advance();
        $connection->pdo()->exec(sprintf('DROP TEMPORARY TABLE IF EXISTS %s', $tempTable));

        $progressIndicator->finish('Done');

        $this->io->newLine();
    }

}
