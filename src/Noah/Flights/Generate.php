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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Database\Table;
use TripBuilder\Helper;
use TripBuilder\Horizon;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\AirportRepository;

/**
 * @phpstan-import-type AirportFullRow from AirportRepository
 *
 * @phpstan-type AirlineSeedRow array{code: string, country: string|null, hubs: string|null, traffic: int}
 * @phpstan-type FareBrandSeedRow array{code: string, weight: int}
 */
#[AsCommand(
    name: self::NAME,
    description: 'Generate flights to database.',
    aliases: ['flights:generate'],
    hidden: false,
)]

class Generate extends AbstractCommand
{
    public const string NAME = 'flights:add';

    private const string ARG_FLIGHTS = 'flights';
    private const string OPT_DAY = 'day';
    private const string OPT_LEVEL = 'level';

    private const int FLIGHTS_COUNT = 10000;
    private const int NUMBERS_POOL = 9999;

    // The window, from the one place that says how far ahead this site goes.
    // The calendar and the flights end on the same day or the calendar
    // offers dates nothing can answer, which is E30 (#215).
    private const array DATE_ADD_DAYS = [1, Horizon::DAYS];

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

    /** @var array<string, int> */
    private array $count = [
        self::COUNT_DUPLICATES => 0,
        self::COUNT_TOTAL => 0,
    ];

    protected function configure(): void
    {
        $this->addArgument(self::ARG_FLIGHTS, InputArgument::OPTIONAL, 'Flights to add');
        $this->addOption(
            self::OPT_DAY,
            null,
            InputOption::VALUE_REQUIRED,
            'Put them all on one day: a date (2026-12-12) or days from today (90).',
        );
        $this->addOption(
            self::OPT_LEVEL,
            null,
            InputOption::VALUE_NONE,
            'Put them on the thinnest days in the window, thinnest first.',
        );
    }

    /**
     * Which days this run fills, and how many each gets -- or null to scatter
     * them across the window, which is what an empty database wants.
     *
     * @return array<string, int>|null
     * @throws Exception
     */
    private function plan(InputInterface $input, int $flightsToAdd): ?array
    {
        /** @var string|null $day */
        $day = $input->getOption(self::OPT_DAY);
        $level = $input->getOption(self::OPT_LEVEL) === true;

        if ($day !== null && $level) {
            throw new RuntimeException('`--day` names one day and `--level` finds them; pick one.');
        }

        if ($day !== null) {
            return [self::readDay($day) => $flightsToAdd];
        }

        if (!$level) {
            return null;
        }

        $window = DayPlan::window(date('Y-m-d'), self::DATE_ADD_DAYS);

        // Counted by local departure date, because that is the axis a visitor
        // searches on: "flights on the 20th" means the 20th where the plane
        // leaves from. `departure_utc` answers a different question and would
        // put a Honolulu evening on the following day.
        $have = [];

        /** @var list<array{day: string, flights: int}> $rows */
        $rows = $this->connection()->fetchAll(
            'SELECT DATE(departure_time) AS day, COUNT(*) AS flights FROM ' . Table::Flights->value
            . ' WHERE departure_time >= ? AND departure_time < ? + INTERVAL 1 DAY GROUP BY day',
            [$window[0], $window[count($window) - 1]],
        );

        foreach ($rows as $row) {
            $have[$row['day']] = $row['flights'];
        }

        return DayPlan::level($window, $have, $flightsToAdd);
    }

    /**
     * `--day` as a date, from either spelling.
     *
     * A number is days from today, which is what a crontab line wants to say;
     * a date is a date, which is what a person filling one in wants to say.
     *
     * @throws Exception
     */
    private static function readDay(string $day): string
    {
        $date = ctype_digit($day)
            ? date('Y-m-d', (int) strtotime(sprintf('+ %d days', (int) $day)))
            : $day;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
            throw new RuntimeException(sprintf('`%s` is not a date or a number of days.', $day));
        }

        // Today's flights have mostly left, and the sweep removes the rest
        // tonight. Generating into the past is generating nothing.
        if ($date <= date('Y-m-d')) {
            throw new RuntimeException(sprintf('`%s` is not in the future.', $date));
        }

        return $date;
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // If flights to add not provided – ask
        $flightsToAdd = $input->getArgument(self::ARG_FLIGHTS) ?? $this->io->ask(
            'Number of flights to add',
            (string) self::FLIGHTS_COUNT,
            function (mixed $number): int {
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
        /** @var list<AirlineSeedRow> $airlines */
        $airlines = $this->connection()->fetchAll(
            'SELECT code, country, hubs, traffic FROM ' . Table::Airlines->value
            . ' WHERE is_major = 1 AND traffic > 0',
        );

        if ($airlines === []) {
            $this->io->error('No airlines are marked major with a traffic weight.');

            return Command::INVALID;
        }

        /** @var list<AirportFullRow> $airports */
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
        /** @var list<FareBrandSeedRow> $brands */
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
            $brandCodes[] = $brand['code'];
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

        // Do the magic.
        //
        // A batch at a time, not a run at a time (E19, #178). This collected
        // every generated flight and inserted the lot at the end, which is
        // 1.06 MB per thousand: 34 MB at ten thousand, and past PHP's default
        // 128 MB somewhere between ninety and a hundred thousand -- measured,
        // and the failure is a fatal in the middle of the loop that reports
        // nothing and writes nothing. `flights:add 200000` is what CI runs and
        // what E24.3 (#193) would quadruple.
        // Which days this run fills. Null scatters across the window, which is
        // what `flights:add N` has always done and what an empty database
        // wants; `--day` and `--level` name days instead (E24.1, #191).
        $plan = $this->plan($input, $flightsToAdd);

        if ($plan !== null) {
            $this->formatOutput(
                'Filling',
                count($plan) === 1
                    ? array_key_first($plan)
                    : sprintf('%d days, thinnest first', count($plan)),
                'comment',
            );
        }

        // Walked rather than expanded: a day per flight would be a list as long
        // as the run, which is the thing E19 (#178) just took out.
        $planDays = $plan === null ? [] : array_keys($plan);
        $planIndex = 0;

        $batch = [];
        $connection = $this->connection();

        // One transaction for the whole run, as before. The batching below
        // bounds PHP's memory, not the database's -- and moving the commit
        // inside the loop would be a different change: a half-finished run
        // would leave its rows behind instead of none.
        $connection->beginTransaction();

        try {
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

                // The day comes from the plan when there is one, and from the
                // window at random when there is not. The time of day is always
                // random: a day of departures all at the same minute is not a
                // day anybody would search.
                if ($plan === null) {
                    $day = date('Y-m-d', (int) strtotime(sprintf(
                        '+ %d days',
                        Helper::random(self::DATE_ADD_DAYS),
                    )));
                } else {
                    while ($plan[$planDays[$planIndex]] < 1) {
                        $planIndex++;
                    }

                    $day = $planDays[$planIndex];
                    $plan[$day]--;
                }

                $departureDateTime = date(
                    'Y-m-d H:i:s',
                    rand(
                        (int) strtotime($day . ' 00:00:01'),
                        (int) strtotime($day . ' 23:59:59'),
                    ),
                );

                // Both the fare and its tax live in FarePricing, so generated rows and
                // repriced ones cannot disagree.
                $priceBase = FarePricing::base($distance);
                $priceTax = FarePricing::tax($priceBase);

                $batch[] = new Flight(
                    airline: $airline,
                    number: rand(1, self::NUMBERS_POOL),
                    aircraft: $leg->aircraft,
                    fareBrand: $brandCodes[Helper::pickWeighted($brandCumulative, $brandTotal)],
                    departureAirport: $departAirport['code'],
                    departureTime: $departureDateTime,
                    departureUtc: LegBuilder::departureUtc(
                        $departureDateTime,
                        $departAirport['timezone_name'],
                    ),
                    arrivalAirport: $arriveAirport['code'],
                    arrivalTime: LegBuilder::arrivalTime(
                        $departureDateTime,
                        $departAirport['timezone_name'],
                        $arriveAirport['timezone_name'],
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

                // Flushed here rather than collected: the whole point.
                if (count($batch) >= self::INSERT_BATCH_SIZE) {
                    $this->insertFlights($batch);
                    $batch = [];
                }
            }

            // Whatever the last batch did not fill.
            $this->insertFlights($batch);

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $progressBar->setMessage(sprintf(self::PROGRESS_MSG_FORMAT, 'Landing'));
        $progressBar->finish();

        $this->io->newLine(2);

        $this->removeDuplicates();

        // Show statistic
        $this->io->writeln('<primary> Summary: </primary>');
        foreach ($this->count as $key => $count) {
            $this->formatOutput($key, number_format($count), 'info');
        }

        /** @var int $totalInDatabase */
        $totalInDatabase = $this->connection()->fetchValue('SELECT count(1) FROM ' . Table::Flights->value);

        // Total rows in the flight table
        $this->formatOutput(
            'Total in Database',
            number_format($totalInDatabase),
            'info',
            true,
        );

        return Command::SUCCESS;
    }

    /**
     * Insert one batch of generated flights.
     *
     * The caller owns the transaction, because the caller is what knows when
     * the run is over -- this is called once per `INSERT_BATCH_SIZE` flights
     * while the generator streams (E19, #178).
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

        $sql = 'INSERT INTO ' . Table::Flights->value . ' (' . implode(', ', $columns) . ') VALUES '
            . implode(', ', array_fill(0, count($flights), $rowPlaceholder));

        $params = [];

        foreach ($flights as $flight) {
            foreach ($flight->toValues() as $value) {
                $params[] = $value;
            }
        }

        $connection->execute($sql, $params);
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
     * @param list<AirportFullRow> $airports
     * @param list<AirlineSeedRow> $airlines
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
            $code = $airline['code'];
            $carrierHubs[$code] = array_flip(preg_split('/\s+/', trim((string) $airline['hubs']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $carrierCountry[$code] = (string) $airline['country'];
            $carrierWeight[$code] = $airline['traffic'];
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

                $from = $airports[$i]['code'];
                $to = $airports[$j]['code'];
                $fromCountry = $airports[$i]['country_code'];
                $toCountry = $airports[$j]['country_code'];

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

                $routeWeight = $airports[$i]['traffic_weight'] * $airports[$j]['traffic_weight']
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

        // 1. Collecting the rows to delete: every flight of a duplicated
        //    airline/number/date except the lowest id, which is the one kept.
        //    Holding the rows rather than the groups is what makes the count
        //    below the number actually deleted -- a group of three reported one
        //    -- and lets step 2 match on the primary key instead of deriving
        //    DATE(departure_time) again for every row it looks at.
        $progressIndicator->advance();
        $connection->pdo()->exec(sprintf(
            'CREATE TEMPORARY TABLE %s AS
            SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY airline, number, DATE(departure_time) ORDER BY id
                ) AS row_in_group
                FROM ' . Table::Flights->value . '
            ) ranked
            WHERE row_in_group > 1;',
            $tempTable,
        ));

        $progressIndicator->advance();

        /** @var int $duplicates */
        $duplicates = $connection->fetchValue('SELECT count(*) FROM ' . $tempTable);
        $this->count[self::COUNT_DUPLICATES] = $duplicates;
        $this->count[self::COUNT_TOTAL] -= $duplicates;

        // 2. Deleting duplicate rows from the flight table
        $progressIndicator->advance();
        $connection->pdo()->exec(sprintf(
            'DELETE flight FROM ' . Table::Flights->value . ' flight
            JOIN %s temp ON flight.id = temp.id',
            $tempTable,
        ));

        // 3. Deleting temporary table
        $progressIndicator->advance();
        $connection->pdo()->exec(sprintf('DROP TEMPORARY TABLE IF EXISTS %s', $tempTable));

        $progressIndicator->finish('Done');

        $this->io->newLine();
    }

}
