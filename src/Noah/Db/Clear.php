<?php

namespace TripBuilder\Noah\Db;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use TripBuilder\Config;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name:        'db:clear',
    description: 'Purge data from database tables.',
    aliases:     ['database:clear', 'mysql:clear'],
    hidden:      false
)]

class Clear extends AbstractCommand
{
    const ARG_1_NAME               = 'table',
          ARG_1_DESCRIPTION        = 'Database table to clear';

    const MESSAGE_WARNING          = 'WARNING!!! ',
          MESSAGE_DONE             = 'Table(s) was successfully purged';

    const ALL_TABLES               = 'all';

    const CONFIRM_QUESTION_ONE     = 'You’re about to purge ALL DATA from CHOSEN TABLE(S)! Are you sure?',
          CONFIRM_QUESTION_TWO     = 'Think twice! One more time - ARE YOU SURE?';

    const SQL_QUERY_DELETE_FROM    = 'DELETE FROM %s',
          SQL_QUERY_ALTER          = 'ALTER TABLE %s AUTO_INCREMENT = %s';

    /**
     * Configure command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument(self::ARG_1_NAME, InputArgument::OPTIONAL, self::ARG_1_DESCRIPTION);
    }

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
        $existing_tables = $this->getAllDatabaseTables();

        $chosen_table = $input->getArgument(self::ARG_1_NAME) ?? $this->io->choice(
            'Which table(s) are being cleared?',
            array_merge([self::ALL_TABLES], $existing_tables)
        );

        if ($chosen_table !== self::ALL_TABLES && !in_array($chosen_table, $existing_tables)) {
            throw new \InvalidArgumentException(sprintf('Table %s doesn’t exist in database. Try another table.', $chosen_table));
        }

        // Show RED WARNING
        $this->io->writeln(sprintf(
            "\e[5m <danger>  %s </danger> \e[0m",
            str_repeat(self::MESSAGE_WARNING, 3)
        ));

        // Answer user twice to be sure
        $answer = $this->io->confirm(self::CONFIRM_QUESTION_ONE, false);

        if ($answer) {
            $answer = $this->io->confirm(self::CONFIRM_QUESTION_TWO, false);
        }

        // Go deeper if user twice answered YES
        if ($answer) {
            $clearing_tables = $chosen_table == self::ALL_TABLES
                ? $existing_tables
                : [$chosen_table];

            // Build config from DB tables directory
            new Config(self::CONFIG_DIR_TABLES);

            foreach ($clearing_tables as $table) {
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
