<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\FlightRepository;

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
        // `departure_utc`, and an instant to compare it against.
        //
        // This read `departure_time < date('Y-m-d')` -- a wall-clock reading at
        // the departure airport against a UTC date, which is the frame mistake
        // E20 (#180) fixed in ten places that *read* and missed in the one that
        // deletes. Measured against a cutoff thirty days out: of 67,503 rows
        // the old predicate removed, 144 had not departed yet and 280 that had
        // were left behind. The worst was a Honolulu departure at -10.00,
        // deleted 9 hours 23 minutes before it left -- and with it, anybody's
        // ability to find the flight they were about to board.
        //
        // Now rather than midnight: "has it gone" is a question about this
        // moment, and a flight that left an hour ago is as gone as one that
        // left yesterday.
        $before = new DateTimeImmutable()->format('Y-m-d H:i:s');
        $deleted = 0;

        try {
            $deleted = new FlightRepository($this->connection())
                ->forgetDepartedBefore($before, self::BATCH_SIZE);
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
