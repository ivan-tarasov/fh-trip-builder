<?php

namespace TripBuilder\Noah\Flights;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
        $this->db->where('DATE(departure_time)', date('Y-m-d'), '<');

        if (! $this->db->delete('flights')) {
            $this->io->error('Deleting old flights failed: ' . $this->db->getLastError());

            return Command::FAILURE;
        }

        $this->formatOutput('Deleted records', number_format($this->db->count), 'info');

        return Command::SUCCESS;
    }
}
