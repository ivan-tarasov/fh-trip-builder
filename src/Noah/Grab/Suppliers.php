<?php

namespace TripBuilder\Noah\Flights;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

class Cleaning extends AbstractCommand
{
    /**
     * The name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'flights:cleaning';

    /**
     * The command description shown when running `list` command.
     *
     * @var string
     */
    protected static $defaultDescription = 'Deleting old flights from database';

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
