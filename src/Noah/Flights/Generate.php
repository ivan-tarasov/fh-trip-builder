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
use TripBuilder\CabinClass;
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

    // Minutes of taxi, climb and descent on top of the cruise, which is what
    // the aircraft's cruise speed alone does not account for.
    private const array DURATION_ADD_MINUTES = [10, 55];
    private const array DATE_ADD_DAYS = [1, 90];

    // Only reached when no type could be drawn, which the route filter should
    // already have prevented -- a leg nothing can fly is not scheduled.
    private const int FALLBACK_CRUISE_KMH = 850;

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

    // How sharply a type is favoured for using more of its range. The draw is
    // weighted by utilisation -- the share of the aircraft's usable range the
    // leg actually needs -- raised to this power, so a 500 km hop strongly
    // prefers a turboprop over a frame built to cross an ocean.
    //
    // Replaces a flat "spare range" falloff, which was far too shallow to hold
    // back a long tail: with fourteen widebodies all mildly penalised on a
    // short leg, they collectively outdrew the fourteen narrowbodies and took
    // 30% of short-haul.
    private const int AIRCRAFT_FIT_EXPONENT = 2;

    // Share of its published range a narrowbody is actually scheduled over.
    // The book figure assumes a light payload; a full single-aisle does not
    // make it, and long-haul narrowbody service is rare in practice. Widebodies
    // are flown much closer to their limit, so they keep the published figure.
    private const float NARROWBODY_RANGE_SHARE = 0.75;

    // Longest leg the network will schedule as a nonstop, roughly the longest
    // scheduled nonstop in the world. Beyond this a traveller connects, which
    // is what happens in reality -- and it stops the generator inventing legs
    // no aircraft could operate, which is where ~12k unflyable flights with no
    // aircraft assigned at all came from.
    private const int MAX_NONSTOP_KM = 15300;

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

        // Aircraft types, longest-legged last so a lookup can stop at the first
        // one that cannot reach (see pickAircraft).
        $fleet = $this->connection()->fetchAll(
            'SELECT code, max_range_km, cruise_speed_kmh, is_widebody FROM ' . Table::Aircraft->value
            . ' WHERE max_range_km > 0 ORDER BY max_range_km ASC',
        );

        if ($fleet === []) {
            $this->io->error('No aircraft types are seeded — run app:install first.');

            return Command::INVALID;
        }

        // What each type has fitted, folded to one mask per type: 28 masks built
        // once, rather than the same fold repeated for every flight drawn.
        $typeCabins = [];
        foreach ($this->connection()->fetchAll(
            'SELECT aircraft, cabin FROM ' . Table::AircraftCabins->value,
        ) as $fitted) {
            $typeCabins[(string) $fitted['aircraft']][] = (string) $fitted['cabin'];
        }

        $cabinMask = array_map(CabinAvailability::bits(...), $typeCabins);

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
        $maxLegKm = min(
            self::MAX_NONSTOP_KM,
            (int) max(array_map($this->usableRange(...), $fleet)),
        );

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
            $pick = $this->pickWeighted($cumulative, $totalWeight);
            $airportCount = count($airports);
            $departAirport = $airports[intdiv($routes[$pick], $airportCount)];
            $arriveAirport = $airports[$routes[$pick] % $airportCount];
            $airline = $carriers[$pick];

            // Already measured while building the distribution.
            $distance = $distances[$pick];

            // The type is settled first: it sets how fast the leg is flown and
            // which cabins are on sale, so both follow from it rather than
            // being drawn independently.
            $type = $this->pickAircraft($fleet, $distance);
            $aircraft = $type === null ? null : (string) $type['code'];

            $duration = $this->getDurationFromDistance(
                $distance,
                $type === null ? self::FALLBACK_CRUISE_KMH : (int) $type['cruise_speed_kmh'],
            ) + Helper::random(self::DURATION_ADD_MINUTES);

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
                aircraft: $aircraft,
                fareBrand: $brandCodes[$this->pickWeighted($brandCumulative, $brandTotal)],
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
                // No type means no cabin rows to read: a leg nothing in the
                // fleet can fly still sells economy.
                cabins: $cabinMask[$aircraft] ?? CabinClass::Economy->bit(),
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
     * Sample an index from cumulative weights (binary search). Used for both
     * the route and the airline that flies it.
     *
     * @param list<float> $cumulative
     */
    private function pickWeighted(array $cumulative, float $totalWeight): int
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
     * The range a type is actually scheduled over, in km.
     *
     * @param array<string, mixed> $type
     */
    private function usableRange(array $type): int
    {
        $range = (int) $type['max_range_km'];

        return (bool) $type['is_widebody']
            ? $range
            : (int) round($range * self::NARROWBODY_RANGE_SHARE);
    }

    /**
     * An aircraft type that could actually fly this leg, or null when none can.
     *
     * Only types whose usable range covers the distance are eligible, and among
     * those the draw is weighted by utilisation: the share of that range the
     * leg needs, raised to AIRCRAFT_FIT_EXPONENT. A type sized for the leg is
     * near 1 and dominates, while one built to cross an ocean is near 0 on a
     * short hop and is drawn rarely rather than merely a little less often.
     *
     * Utilisation is measured against usable range, not the published figure,
     * so the two body types are compared on what each is really flown over.
     *
     * @param list<array<string, mixed>> $fleet
     * @return array<string, mixed>|null the chosen type's row
     */
    private function pickAircraft(array $fleet, int $distance): ?array
    {
        $types = [];
        $cumulative = [];
        $running = 0.0;

        foreach ($fleet as $type) {
            $range = $this->usableRange($type);

            // Cannot make the leg. Not a stopping point: the fleet is ordered by
            // published range, which the narrowbody haircut does not preserve.
            if ($range < $distance) {
                continue;
            }

            $running += ($distance / $range) ** self::AIRCRAFT_FIT_EXPONENT;
            $types[] = $type;
            $cumulative[] = $running;
        }

        // Longer than anything in the fleet can fly. The route filter should
        // have kept this leg out of the network altogether, so reaching here
        // means the two disagree -- leave it unassigned rather than inventing
        // an aircraft that could not make it.
        if ($types === [] || $running <= 0.0) {
            return null;
        }

        return $types[$this->pickWeighted($cumulative, $running)];
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
     * The speed is the operating type's own cruise figure rather than a random
     * draw, so a turboprop no longer crosses a leg as fast as a 787: an ATR 72
     * cruises at 510 km/h against the 917 km/h of a 747.
     *
     * @param float|int $distance Flight distance in kilometers
     * @param int $speedKmh Cruise speed of the type flying it
     * @return int Duration in minutes
     */
    private function getDurationFromDistance(float|int $distance, int $speedKmh): int
    {
        if ($distance <= 0) {
            return 0;
        }

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
