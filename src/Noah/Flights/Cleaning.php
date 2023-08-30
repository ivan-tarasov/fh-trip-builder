<?php

namespace TripBuilder\Noah\Flights;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name:        'flights:cleaning',
    description: 'Deleting old flights from database.',
    aliases:     [],
    hidden:      false
)]

class Cleaning extends AbstractCommand
{
    /**
     * Execute the command
     *
     * @param  $input
     * @param  $output
     * @return int 0 if everything went fine, or an exit code.
     * @throws \Exception
     */
    protected function execute($input, $output): int
    {
        $this->db->where('DATE(departure_time)', date('Y-m-d'), '<');

        if ($this->db->delete('flights')) {
            $this->formatOutput('Deleted records', number_format($this->db->count), 'info');
        }

        return Command::SUCCESS;
    }

}
