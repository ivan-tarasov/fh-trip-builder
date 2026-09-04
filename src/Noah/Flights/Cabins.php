<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table as TableHelper;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Database\Table;
use TripBuilder\Noah\AbstractCommand;

/**
 * Copy each flight's cabins onto `flights.cabins` from the type flying it.
 *
 * Idempotent: the mask is a pure function of the aircraft, so a second run
 * computes the same values. The column itself is declared in the flights table
 * config and created by app:install.
 *
 * The masks are derived, not authored. `aircraft_cabins` says what is fitted on
 * each type and this denormalises it onto the flight, so a search can filter on
 * cabin without joining two tables on its hot path. Re-run it after changing
 * the fleet's cabins, the same way `flights:reprice` follows a fare change.
 *
 * Updates in place rather than rebuilding the table, for the reason Reprice
 * gives: bookings reference flights by id through flight_outbound and
 * flight_return, so a drop-and-recreate would orphan them.
 *
 * Runs in id batches so the whole table is never locked at once.
 */
#[AsCommand(
    name: 'flights:cabins',
    description: 'Populate the cabins each flight sells from its aircraft.',
    aliases: [],
    hidden: false,
)]
class Cabins extends AbstractCommand
{
    private const int BATCH_SIZE = 20000;

    private const string COLUMN = 'cabins';

    /** Distance bands the fare sample is drawn from, in km. */
    private const array SAMPLE_BANDS = [[1, 400], [900, 1500], [1900, 2200], [5000, 6500], [9500, 11000], [14000, 99999]];

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what the cabins and their fares would look like without writing anything.',
        );

        $this->addOption(
            'fleet',
            null,
            InputOption::VALUE_NONE,
            'Also list what every aircraft type has fitted on board.',
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flights = Table::Flights->value;
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $bounds = $this->connection()->fetchAll(
                'SELECT MIN(id) AS lo, MAX(id) AS hi, COUNT(*) AS total FROM ' . $flights,
            )[0] ?? null;
        } catch (Throwable $e) {
            $this->io->error('Could not read the flights table: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($bounds === null || (int) $bounds['total'] === 0) {
            $this->io->warning('No flights to populate.');

            return Command::SUCCESS;
        }

        if ($input->getOption('fleet') || $dryRun) {
            $this->reportFleet();
        }

        $this->formatOutput('Flights in network', number_format((int) $bounds['total']), 'info');

        // Reported from the fleet rather than the column, so it reads the same
        // whether or not the column exists yet.
        $this->reportOffered($flights);
        $this->reportFares($flights);

        if ($dryRun) {
            $this->io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        if (!$this->columnExists($flights)) {
            $this->io->error(sprintf(
                'The `%s` table has no `%s` column yet. Run app:install to add it.',
                $flights,
                self::COLUMN,
            ));

            return Command::FAILURE;
        }

        // LEFT JOIN and COALESCE together: a flight whose type is unassigned has
        // no cabin rows to fold, and must still come out selling economy.
        $sql = sprintf(
            'UPDATE %s f LEFT JOIN %s ON m.aircraft = f.aircraft'
            . ' SET f.%s = COALESCE(m.mask, %d) WHERE f.id BETWEEN ? AND ?',
            $flights,
            CabinAvailability::sqlMaskByAircraft('m'),
            self::COLUMN,
            CabinClass::Economy->bit(),
        );

        $changed = 0;

        try {
            for ($lo = (int) $bounds['lo']; $lo <= (int) $bounds['hi']; $lo += self::BATCH_SIZE) {
                $changed += $this->connection()->execute($sql, [$lo, $lo + self::BATCH_SIZE - 1]);
            }
        } catch (Throwable $e) {
            $this->io->error('Populating failed after ' . number_format($changed) . ' rows: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Rows written', number_format($changed), 'info', true);

        return Command::SUCCESS;
    }

    /**
     * Whether the column exists yet. Declared in the flights table config and
     * created by app:install, which is also what adds it to a database that
     * predates it -- so this only has to notice when that has not been run.
     */
    private function columnExists(string $flights): bool
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$flights, self::COLUMN],
        ) > 0;
    }

    /**
     * Every type in the fleet and what it has fitted, one row per cabin.
     *
     * This is the data every mask is derived from, so it is worth being able to
     * read it: a business fare three times the economy one makes sense next to
     * a 60" flat bed and much less next to a 37" recliner.
     */
    private function reportFleet(): void
    {
        $rows = $this->connection()->fetchAll(sprintf(
            'SELECT a.code, a.title, a.manufacturer, a.max_range_km, a.cruise_speed_kmh,'
            . ' a.engine_count, a.is_widebody, c.cabin, c.layout, c.pitch_inches,'
            . ' c.width_inches, c.seats, c.is_flat_bed'
            . ' FROM %s a LEFT JOIN %s c ON c.aircraft = a.code'
            // Grouped by type -- the code breaks a tie on range, or two types
            // sharing one would interleave their cabins.
            . ' ORDER BY a.is_widebody, a.max_range_km, a.code,'
            // Cabin order on board rather than alphabetical: Y W C F.
            . ' FIELD(c.cabin, %s)',
            Table::Aircraft->value,
            Table::AircraftCabins->value,
            implode(', ', array_map(
                static fn(CabinClass $c): string => "'" . $c->code() . "'",
                CabinClass::cases(),
            )),
        ));

        if ($rows === []) {
            $this->io->warning('No aircraft are seeded — run app:install first.');

            return;
        }

        $table = new TableHelper($this->output);
        $table->setHeaders([
            'Type', 'Aircraft', 'Body', 'Range', 'Cruise', 'Cabin',
            'Layout', 'Pitch', 'Width', 'Seats', 'Bed',
        ]);

        $seen = null;

        foreach ($rows as $row) {
            $first = $row['code'] !== $seen;

            if (!$first) {
                // One block per type: the aircraft columns are printed once and
                // left blank on its remaining cabins, so the eye groups them.
                $table->addRow(['', '', '', '', '', ...$this->cabinCells($row)]);

                continue;
            }

            if ($seen !== null) {
                $table->addRow(new TableSeparator());
            }

            $seen = (string) $row['code'];

            $table->addRow([
                $row['code'],
                $row['title'],
                $row['is_widebody'] ? 'wide' : 'narrow',
                number_format((int) $row['max_range_km']) . ' km',
                number_format((int) $row['cruise_speed_kmh']) . ' km/h',
                ...$this->cabinCells($row),
            ]);
        }

        $table->render();

        $this->io->newLine();
    }

    /**
     * The cabin half of a fleet row.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function cabinCells(array $row): array
    {
        if ($row['cabin'] === null) {
            return ['—', '', '', '', '', ''];
        }

        $cabin = CabinClass::tryFromCode((string) $row['cabin']);

        return [
            $cabin?->label() ?? (string) $row['cabin'],
            (string) $row['layout'],
            $row['pitch_inches'] . '"',
            $row['width_inches'] . '"',
            (string) $row['seats'],
            $row['is_flat_bed'] ? 'flat' : '',
        ];
    }

    /**
     * How much of the network sells each cabin.
     *
     * A flight counts once per cabin it sells, so these overlap by design --
     * every flight is in the economy row.
     */
    private function reportOffered(string $flights): void
    {
        $sums = [];

        foreach (CabinClass::cases() as $cabin) {
            $sums[] = sprintf('SUM((mask & %d) > 0) AS %s', $cabin->bit(), strtolower($cabin->code()));
        }

        $row = $this->connection()->fetchAll(sprintf(
            'SELECT COUNT(*) AS total, %s FROM ('
            . 'SELECT COALESCE(m.mask, %d) AS mask FROM %s f LEFT JOIN %s ON m.aircraft = f.aircraft'
            . ') masks',
            implode(', ', $sums),
            CabinClass::Economy->bit(),
            $flights,
            CabinAvailability::sqlMaskByAircraft('m'),
        ))[0] ?? null;

        if ($row === null) {
            return;
        }

        $total = max(1, (int) $row['total']);

        foreach (CabinClass::cases() as $cabin) {
            $count = (int) $row[strtolower($cabin->code())];

            $this->formatOutput(
                'Flights selling ' . $cabin->label(),
                sprintf('%s (%.1f%%)', number_format($count), $count / $total * 100),
                'comment',
            );
        }
    }

    /**
     * One flight from each distance band, priced in every cabin it sells.
     *
     * This is the point of the whole change, so it is worth showing: the same
     * leg across the cabins, and the premium widening with the haul.
     */
    private function reportFares(string $flights): void
    {
        $this->io->newLine();
        $this->io->writeln('<primary> Fare by cabin (blank = not fitted on that aircraft): </primary>');

        $header = sprintf('  %-9s %-5s', 'km', 'type');
        foreach (CabinClass::cases() as $cabin) {
            $header .= sprintf(' %12s', $cabin->label());
        }
        $this->io->writeln('<comment>' . $header . '</comment>');

        foreach (self::SAMPLE_BANDS as [$lo, $hi]) {
            $row = $this->connection()->fetchAll(sprintf(
                'SELECT f.distance, f.aircraft, f.price_base, f.price_tax,'
                . ' COALESCE(m.mask, %d) AS mask'
                . ' FROM %s f LEFT JOIN %s ON m.aircraft = f.aircraft'
                . ' WHERE f.distance BETWEEN ? AND ? LIMIT 1',
                CabinClass::Economy->bit(),
                $flights,
                CabinAvailability::sqlMaskByAircraft('m'),
            ), [$lo, $hi])[0] ?? null;

            if ($row === null) {
                continue;
            }

            $distance = (int) $row['distance'];
            $economy = (float) $row['price_base'] + (float) $row['price_tax'];
            $mask = (int) $row['mask'];

            $line = sprintf('  %-9s %-5s', number_format($distance), $row['aircraft'] ?? '--');

            foreach (CabinClass::cases() as $cabin) {
                $line .= sprintf(' %12s', ($mask & $cabin->bit())
                    ? '$' . number_format($economy * $cabin->priceMultiplier($distance), 2)
                    : '');
            }

            $this->io->writeln($line);
        }

        $this->io->newLine();
    }
}
