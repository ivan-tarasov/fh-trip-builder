<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

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

    private const array DATE_ADD_DAYS = [1, 90];

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

        // Only airlines that actually operate in this network, weighted by how
        // much of it they carry. Without the filter every one of the ~1,150
        // carriers flew equally, so obscure operators outnumbered the majors —
        // and many of them have no logo to show.
        $airlines = $this->connection()->fetchAll(
            'SELECT code, country, hubs, traffic FROM ' . Table::Airlines->value
            . ' WHERE is_major = 1 AND traffic > 0',
        );

        if ($airlines === []) {
            $this->io->error('No airlines are marked major with a traffic weight.');

            return Command::INVALID;
        }

        $airports = $this->connection()->fetchAll(
            'SELECT * FROM ' . Table::Airports->value
            . ' WHERE enabled = 1 AND is_major = 1 AND traffic_weight > 0',
        );

        if (count($airports) < 2) {
            $this->io->error('Need at least two airports with a traffic weight to build a network.');

            return Command::INVALID;
        }

        // Which type flies a leg, how long it takes and what it sells all come
        // from here, so a generated flight and a realigned one agree.
        try {
            $legs = LegBuilder::fromConnection($this->connection());
        } catch (RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::INVALID;
        }

        // The fare each leg is sold under. Cumulative weights so a brand is one
        // binary search rather than a scan, the same shape as the fleet draw.
        $brands = $this->connection()->fetchAll(
            'SELECT code, weight FROM ' . Table::FareBrands->value . ' WHERE weight > 0',
        );

        if ($brands === []) {
            $this->io->error('No fare brands are seeded — run app:install first.');

            return Command::INVALID;
        }

        $brandCodes = [];
        $brandCumulative = [];
        $brandTotal = 0.0;

        foreach ($brands as $brand) {
            $brandTotal += (float) $brand['weight'];
            $brandCodes[] = (string) $brand['code'];
            $brandCumulative[] = $brandTotal;
        }

        // Precompute how the network is shaped before generating anything (see
        // routeDistribution): each flight is then a single weighted draw that
        // yields the route, the airline flying it, and the distance already
        // measured.
        // No route longer than the fleet can actually fly, and none longer than
        // anyone schedules nonstop.
        $maxLegKm = $legs->maxLegKm();

        [$routes, $cumulative, $distances, $carriers, $totalWeight] =
            $this->routeDistribution($airports, $airlines, $maxLegKm);

        if ($routes === []) {
            $this->io->error('No airline serves any route in this network — check airline hubs.');

            return Command::INVALID;
        }

        $this->formatOutput('Longest nonstop scheduled', number_format($maxLegKm) . ' km', 'comment');

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

            // One draw picks the route and the carrier together, weighted by how
            // much traffic that pairing should carry.
            $pick = Helper::pickWeighted($cumulative, $totalWeight);
            $airportCount = count($airports);
            $departAirport = $airports[intdiv($routes[$pick], $airportCount)];
            $arriveAirport = $airports[$routes[$pick] % $airportCount];
            $airline = $carriers[$pick];

            // Already measured while building the distribution.
            $distance = $distances[$pick];

            // The type is settled first: it sets how fast the leg is flown and
            // which cabins are on sale, so both follow from it rather than
            // being drawn independently.
            $leg = $legs->assign($distance);

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

            // Both the fare and its tax live in FarePricing, so generated rows and
            // repriced ones cannot disagree.
            $priceBase = FarePricing::base($distance);
            $priceTax = FarePricing::tax($priceBase);

            $flights[] = new Flight(
                airline: $airline,
                number: rand(1, self::NUMBERS_POOL),
                aircraft: $leg->aircraft,
                fareBrand: $brandCodes[Helper::pickWeighted($brandCumulative, $brandTotal)],
                departureAirport: $departAirport['code'],
                departureTime: $departureDateTime,
                arrivalAirport: $arriveAirport['code'],
                arrivalTime: LegBuilder::arrivalTime(
                    $departureDateTime,
                    (string) $departAirport['timezone_name'],
                    (string) $arriveAirport['timezone_name'],
                    $leg->duration,
                ),
                distance: $distance,
                duration: $leg->duration,
                cabins: $leg->cabins,
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
     * Build the distribution every flight is drawn from: each entry is a route
     * paired with an airline that actually operates it.
     *
     * Routes are weighted by a gravity model — the product of the two airports'
     * traffic weights over how far apart they are — so big airports close
     * together carry many daily flights and small distant ones almost none.
     * That weight is then split across the carriers serving the route, in
     * proportion to each carrier's own traffic.
     *
     * A carrier serves a route only if it touches one of its hubs or stays
     * inside its home country, which is what stops Emirates flying Montreal to
     * Toronto. A route no carrier serves simply never appears.
     *
     * A route longer than `$maxLegKm` is left out entirely: nothing in the
     * fleet could fly it, and nobody schedules a nonstop that long. Those city
     * pairs are still reachable, as the connecting tiers of the search build
     * them out of legs that do exist.
     *
     * @param list<array<string, mixed>> $airports
     * @param list<array<string, mixed>> $airlines
     * @param int $maxLegKm longest nonstop this network will schedule
     * @return array{0: list<int>, 1: list<float>, 2: list<int>, 3: list<string>, 4: float}
     */
    private function routeDistribution(array $airports, array $airlines, int $maxLegKm): array
    {
        $count = count($airports);

        // Hubs and home country per carrier, resolved once.
        $carrierHubs = [];
        $carrierCountry = [];
        $carrierWeight = [];

        foreach ($airlines as $airline) {
            $code = (string) $airline['code'];
            $carrierHubs[$code] = array_flip(preg_split('/\s+/', trim((string) $airline['hubs']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $carrierCountry[$code] = (string) $airline['country'];
            $carrierWeight[$code] = (int) $airline['traffic'];
        }

        $routes = [];
        $cumulative = [];
        $distances = [];
        $carriers = [];
        $running = 0.0;

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }

                $from = (string) $airports[$i]['code'];
                $to = (string) $airports[$j]['code'];
                $fromCountry = (string) $airports[$i]['country_code'];
                $toCountry = (string) $airports[$j]['country_code'];

                $serving = [];
                $servingWeight = 0;

                foreach ($carrierHubs as $code => $hubs) {
                    $touchesHub = isset($hubs[$from]) || isset($hubs[$to]);
                    $domestic = $carrierCountry[$code] !== ''
                        && $fromCountry === $carrierCountry[$code]
                        && $toCountry === $carrierCountry[$code];

                    if ($touchesHub || $domestic) {
                        $serving[] = $code;
                        $servingWeight += $carrierWeight[$code];
                    }
                }

                if ($serving === [] || $servingWeight === 0) {
                    continue;
                }

                $distance = (int) ($this->distanceOnEarthSurface(
                    (float) $airports[$i]['latitude'],
                    (float) $airports[$i]['longitude'],
                    (float) $airports[$j]['latitude'],
                    (float) $airports[$j]['longitude'],
                ) / 1000);

                if ($distance > $maxLegKm) {
                    continue;
                }

                $routeWeight = (int) $airports[$i]['traffic_weight'] * (int) $airports[$j]['traffic_weight']
                    / (1 + $distance / self::ROUTE_DISTANCE_HALVING_KM);

                // Share the route's traffic among the carriers that fly it.
                foreach ($serving as $code) {
                    $running += $routeWeight * $carrierWeight[$code] / $servingWeight;

                    $routes[] = $i * $count + $j;
                    $cumulative[] = $running;
                    $distances[] = $distance;
                    $carriers[] = $code;
                }
            }
        }

        return [$routes, $cumulative, $distances, $carriers, $running];
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
