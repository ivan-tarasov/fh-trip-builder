<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $deleted = $this->connection()->execute(
                'DELETE FROM ' . Table::Flights->value . ' WHERE DATE(departure_time) < ?',
                [date('Y-m-d')],
            );
        } catch (\Throwable $e) {
            $this->io->error('Deleting old flights failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Deleted records', number_format($deleted), 'info');

        return Command::SUCCESS;
    }
}
