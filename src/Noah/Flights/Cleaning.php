<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Database\Table;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: 'flights:cleaning',
    description: 'Deleting old flights from database.',
    aliases: [],
    hidden: false,
)]

class Cleaning extends AbstractCommand
{
    private const int BATCH_SIZE = 5000;

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Compared against the column, not DATE(column): wrapping it made the
        // departure_time index unusable, so this walked all 716k rows to find
        // the day's worth it wanted. Midnight is the same boundary either way.
        $before = date('Y-m-d');
        $deleted = 0;

        try {
            // Bounded per statement, the same way Realign deletes over-cap legs:
            // one open-ended DELETE holds row locks for its whole duration, and
            // every concurrent search queues behind it.
            do {
                $removed = $this->connection()->execute(
                    'DELETE FROM ' . Table::Flights->value
                    . ' WHERE departure_time < ? LIMIT ' . self::BATCH_SIZE,
                    [$before],
                );
                $deleted += $removed;
            } while ($removed > 0);
        } catch (Throwable $e) {
            $this->io->error(sprintf(
                'Deleting old flights failed after %s rows: %s',
                number_format($deleted),
                $e->getMessage(),
            ));

            return Command::FAILURE;
        }

        $this->formatOutput('Deleted records', number_format($deleted), 'info');

        return Command::SUCCESS;
    }
}
