<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use Exception;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Database\Table;
use TripBuilder\Noah\AbstractCommand;

/**
 * Put the right aircraft on legs already in the network, and drop the ones no
 * aircraft can fly.
 *
 * The generator appends, so the table holds every row every past run produced,
 * each assigned an aircraft by whatever rule was current at the time. An older
 * rule was far too shallow to hold back the long-haul frames -- 30% of every
 * leg under 1,500 km was flown by a widebody -- and it left ~12k legs of up to
 * 19,940 km with no aircraft at all, because nothing in the fleet could reach.
 * Changing LegBuilder only affects new rows; this brings the existing ones
 * into line.
 *
 * Two passes, in this order:
 *
 * 1. Delete every leg longer than LegBuilder::MAX_NONSTOP_KM. These are not
 *    mis-assigned, they are legs that should never have been scheduled: no
 *    aircraft can operate them and no airline sells a nonstop that long. The
 *    city pairs stay reachable, as the connecting tiers of the search build
 *    them from legs that do exist.
 * 2. Redraw the aircraft on everything that remains, and with it the duration
 *    -- the type's cruise speed sets it -- the arrival time that follows, and
 *    the cabins the type has fitted.
 *
 * Nothing here rewrites a receipt. A booking stores its whole itinerary as
 * JSON, every leg's times and prices included, and is read back from that
 * snapshot rather than re-fetched by id, so deleting or retiming a flight
 * cannot change what somebody was sold. A saved flight is a cookie of leg ids
 * rebuilt fresh on view, and that rebuild already drops an itinerary whose ids
 * no longer resolve or connect -- which is the right outcome for a leg the
 * network no longer flies.
 *
 * Fares are left alone: FarePricing keys off distance, which does not change.
 * Runs in id batches so the whole table is never locked at once.
 */
#[AsCommand(
    name: 'flights:realign',
    description: 'Reassign aircraft on existing flights and drop unflyable legs.',
    aliases: [],
    hidden: false,
)]
class Realign extends AbstractCommand
{
    private const int BATCH_SIZE = 5000;

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would change without writing anything.',
        );

        $this->addOption(
            'keep-unflyable',
            null,
            InputOption::VALUE_NONE,
            'Leave legs longer than any aircraft can fly in place instead of deleting them.',
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
            $legs = LegBuilder::fromConnection($this->connection());
        } catch (RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::INVALID;
        }

        $maxLegKm = $legs->maxLegKm();

        $this->formatOutput('Longest flyable nonstop', number_format($maxLegKm) . ' km', 'info');
        $this->reportShape($flights, 'Before');

        $overCap = (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM ' . $flights . ' WHERE distance > ?',
            [$maxLegKm],
        );

        $this->formatOutput(
            $input->getOption('keep-unflyable') ? 'Unflyable legs (kept)' : 'Unflyable legs to delete',
            number_format($overCap),
            $overCap > 0 ? 'comment' : 'info',
        );

        if ($dryRun) {
            $this->io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('keep-unflyable') && $overCap > 0 && !$this->deleteOverCap($flights, $maxLegKm)) {
            return Command::FAILURE;
        }

        $realigned = $this->realign($flights, $legs);

        if ($realigned === null) {
            return Command::FAILURE;
        }

        $this->formatOutput('Flights realigned', number_format($realigned), 'info');
        $this->reportShape($flights, 'After', true);

        return Command::SUCCESS;
    }

    /**
     * Delete legs longer than anything in the fleet can fly. Returns false on
     * failure.
     */
    private function deleteOverCap(string $flights, int $maxLegKm): bool
    {
        $deleted = 0;

        try {
            // Bounded per statement rather than one open-ended DELETE, so a
            // multi-thousand row delete does not hold a single long lock.
            do {
                $removed = $this->connection()->execute(
                    'DELETE FROM ' . $flights . ' WHERE distance > ? LIMIT ' . self::BATCH_SIZE,
                    [$maxLegKm],
                );
                $deleted += $removed;
            } while ($removed > 0);
        } catch (Throwable $e) {
            $this->io->error('Deleting unflyable legs failed after ' . number_format($deleted) . ' rows: ' . $e->getMessage());

            return false;
        }

        $this->formatOutput('Unflyable legs deleted', number_format($deleted), 'info');

        return true;
    }

    /**
     * Redraw the aircraft, duration, arrival time and cabins on every leg.
     *
     * Returns the number of rows written, or null on failure.
     *
     * The draw is a weighted random one, so it cannot be expressed as SQL --
     * each row is assigned in PHP and written back. Timezone names come along
     * on the read because the arrival time has to be recomputed in the arrival
     * airport's own zone, not by adding minutes to a local clock.
     */
    private function realign(string $flights, LegBuilder $legs): ?int
    {
        $airports = Table::Airports->value;

        $read = sprintf(
            'SELECT f.id, f.distance, f.departure_time, d.timezone_name AS depart_tz,'
            . ' a.timezone_name AS arrive_tz'
            . ' FROM %s f'
            . ' INNER JOIN %s d ON f.departure_airport = d.code'
            . ' INNER JOIN %s a ON f.arrival_airport = a.code'
            . ' WHERE f.id BETWEEN ? AND ?',
            $flights,
            $airports,
            $airports,
        );

        $write = 'UPDATE ' . $flights
            . ' SET aircraft = ?, duration = ?, arrival_time = ?, cabins = ? WHERE id = ?';

        $bounds = $this->connection()->fetchAll(
            'SELECT MIN(id) AS lo, MAX(id) AS hi, COUNT(*) AS total FROM ' . $flights,
        )[0] ?? null;

        if ($bounds === null || (int) $bounds['total'] === 0) {
            $this->io->warning('No flights to realign.');

            return 0;
        }

        $progress = $this->io->createProgressBar((int) $bounds['total']);
        $progress->start();

        $written = 0;
        $connection = $this->connection();

        try {
            for ($lo = (int) $bounds['lo']; $lo <= (int) $bounds['hi']; $lo += self::BATCH_SIZE) {
                $rows = $connection->fetchAll($read, [$lo, $lo + self::BATCH_SIZE - 1]);

                if ($rows === []) {
                    continue;
                }

                // One transaction per batch: 700k single-row updates committed
                // individually would spend all their time on fsync.
                $connection->beginTransaction();

                try {
                    foreach ($rows as $row) {
                        $leg = $legs->assign((int) $row['distance']);

                        $connection->execute($write, [
                            $leg->aircraft,
                            $leg->duration,
                            LegBuilder::arrivalTime(
                                (string) $row['departure_time'],
                                (string) $row['depart_tz'],
                                (string) $row['arrive_tz'],
                                $leg->duration,
                            ),
                            $leg->cabins,
                            $row['id'],
                        ]);

                        $written++;
                    }

                    $connection->commit();
                } catch (Throwable $e) {
                    $connection->rollBack();

                    throw $e;
                }

                $progress->advance(count($rows));
            }
        } catch (Throwable $e) {
            $progress->finish();
            $this->io->newLine(2);
            $this->io->error('Realigning failed after ' . number_format($written) . ' rows: ' . $e->getMessage());

            return null;
        }

        $progress->finish();
        $this->io->newLine(2);

        return $written;
    }

    /**
     * Widebody share by distance band -- the measure this command exists to
     * move. A widebody on a short hop is the symptom of a bad assignment.
     */
    private function reportShape(string $flights, string $label, bool $last = false): void
    {
        $bands = [
            ['under 1,500 km', 0, 1499],
            ['1,500-4,500 km', 1500, 4499],
            ['4,500-8,000 km', 4500, 7999],
            ['over 8,000 km', 8000, 99999],
        ];

        foreach ($bands as $i => [$name, $lo, $hi]) {
            $row = $this->connection()->fetchAll(sprintf(
                'SELECT COUNT(*) AS legs, SUM(a.is_widebody = 1) AS wide, SUM(f.aircraft IS NULL) AS none'
                . ' FROM %s f LEFT JOIN %s a ON f.aircraft = a.code'
                . ' WHERE f.distance BETWEEN ? AND ?',
                $flights,
                Table::Aircraft->value,
            ), [$lo, $hi])[0] ?? null;

            $count = (int) ($row['legs'] ?? 0);

            if ($count === 0) {
                continue;
            }

            $unassigned = (int) $row['none'];

            $this->formatOutput(
                sprintf('%s widebody, %s', $label, $name),
                sprintf(
                    '%.1f%% of %s%s',
                    (int) $row['wide'] / $count * 100,
                    number_format($count),
                    $unassigned > 0 ? sprintf(' (%s with no aircraft)', number_format($unassigned)) : '',
                ),
                'comment',
                $last && $i === count($bands) - 1,
            );
        }
    }
}
