<?php

namespace TripBuilder\Noah\Db;

use Symfony\Component\Console\Command\Command;
use TripBuilder\Config;
use TripBuilder\Noah\AbstractCommand;

class Clear extends AbstractCommand
{
    /**
     * The name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'db:clear';

    /**
     * The command description shown when running `list` command.
     *
     * @var string
     */
    protected static $defaultDescription = 'Purge all data from database tables';

    const MESSAGE_WARNING          = 'WARNING!!! ',
          MESSAGE_DONE             = 'All tables was successfully purged';

    const CONFIRM_QUESTION_ONE     = 'You’re about to purge ALL DATA from ALL TABLES! Are you sure?',
          CONFIRM_QUESTION_TWO     = 'Think twice! One more time - ARE YOU SURE?';

    const SQL_QUERY_DELETE_FROM    = 'DELETE FROM %s',
          SQL_QUERY_ALTER          = 'ALTER TABLE %s AUTO_INCREMENT = %s';

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
        $this->io->writeln(sprintf(
            "\e[5m <danger>  %s </danger> \e[0m",
            str_repeat(self::MESSAGE_WARNING, 3)
        ));

        $answer = $this->io->confirm(self::CONFIRM_QUESTION_ONE, false);

        if ($answer) {
            $answer = $this->io->confirm(self::CONFIRM_QUESTION_TWO, false);
        }

        if ($answer) {
            // Build config from DB tables directory
            new Config(self::CONFIG_DIR_TABLES);

            $tables = $this->getAllDatabaseTables();

            foreach ($tables as $table) {
                $this->db->rawQuery(sprintf(self::SQL_QUERY_DELETE_FROM, $table));

                // Altering AUTO_INCREMENT if needed
                $auto_increment = Config::get(sprintf('%s.auto_increment', $table));

                if (!empty($auto_increment)) {
                    $this->db->rawQuery(sprintf(self::SQL_QUERY_ALTER, $table, $auto_increment));
                }
            }

            $this->io->success(self::MESSAGE_DONE);
        }

        $this->io->newLine();

        return Command::SUCCESS;
    }

}
