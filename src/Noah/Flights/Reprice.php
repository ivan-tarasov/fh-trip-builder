<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Database\Table;
use TripBuilder\Noah\AbstractCommand;

/**
 * Recompute price_base and price_tax on flights already in the network.
 *
 * The generator appends, so the table holds every row every past run produced,
 * all of them priced by whatever formula was current at the time. Changing
 * FarePricing only affects new rows; this brings the existing ones into line.
 *
 * It updates in place rather than regenerating, because bookings reference
 * flights by id -- flight_outbound and flight_return -- and dropping the table
 * would leave those pointing at nothing. Bookings snapshot the price they were
 * sold at in their own columns, so repricing the network does not rewrite
 * anybody's receipt.
 *
 * Runs in id batches so the whole table is never locked at once.
 */
#[AsCommand(
    name: 'flights:reprice',
    description: 'Recalculate fares on existing flights with the current formula.',
    aliases: [],
    hidden: false,
)]
class Reprice extends AbstractCommand
{
    private const int BATCH_SIZE = 20000;

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what the new fares would look like without writing anything.',
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flights = Table::Flights->value;

        try {
            $bounds = $this->connection()->fetchAll(
                'SELECT MIN(id) AS lo, MAX(id) AS hi, COUNT(*) AS total FROM ' . $flights,
            )[0] ?? null;
        } catch (Throwable $e) {
            $this->io->error('Could not read the flights table: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($bounds === null || (int) $bounds['total'] === 0) {
            $this->io->warning('No flights to reprice.');

            return Command::SUCCESS;
        }

        $total = (int) $bounds['total'];
        $this->formatOutput('Flights to reprice', number_format($total), 'info');

        $this->reportSample($flights);

        if ($input->getOption('dry-run')) {
            $this->io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $sql = sprintf(
            'UPDATE %s SET price_base = %s, price_tax = %s WHERE id BETWEEN ? AND ?',
            $flights,
            FarePricing::sqlBase(),
            // price_base is assigned first in the same statement, and MySQL
            // evaluates SET left to right, so this taxes the new fare.
            FarePricing::sqlTax(),
        );

        $changed = 0;

        try {
            for ($lo = (int) $bounds['lo']; $lo <= (int) $bounds['hi']; $lo += self::BATCH_SIZE) {
                $changed += $this->connection()->execute($sql, [$lo, $lo + self::BATCH_SIZE - 1]);
            }
        } catch (Throwable $e) {
            $this->io->error('Repricing failed after ' . number_format($changed) . ' rows: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Repriced', number_format($changed), 'info');
        $this->reportSample($flights, 'After');

        return Command::SUCCESS;
    }

    /** One flight from each of a few distance bands, so the effect is visible. */
    private function reportSample(string $flights, string $label = 'Now'): void
    {
        foreach ([[1, 400], [900, 1500], [1900, 2200], [5000, 6500], [9500, 11000], [15000, 99999]] as [$lo, $hi]) {
            $row = $this->connection()->fetchAll(
                'SELECT distance, price_base, price_tax FROM ' . $flights
                . ' WHERE distance BETWEEN ? AND ? LIMIT 1',
                [$lo, $hi],
            )[0] ?? null;

            if ($row === null) {
                continue;
            }

            $this->formatOutput(
                sprintf('%s @ %s km', $label, number_format((int) $row['distance'])),
                sprintf(
                    '$%s + $%s tax = $%s',
                    number_format((float) $row['price_base'], 2),
                    number_format((float) $row['price_tax'], 2),
                    number_format((float) $row['price_base'] + (float) $row['price_tax'], 2),
                ),
                'comment',
            );
        }
    }
}
