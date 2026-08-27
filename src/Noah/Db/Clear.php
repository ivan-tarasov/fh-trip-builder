<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Db;

use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\Config;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: 'db:clear',
    description: 'Purge data from database tables.',
    aliases: ['database:clear', 'mysql:clear'],
    hidden: false,
)]

class Clear extends AbstractCommand
{
    private const ARG_NAME = 'table';
    private const ARG_DESCRIPTION = 'Database table to clear';

    private const MESSAGE_WARNING = 'WARNING!!! ';
    private const MESSAGE_DONE = 'Table(s) was successfully purged';

    private const ALL_TABLES = 'all';

    private const CONFIRM_QUESTION_ONE = 'You\'re about to purge ALL DATA from CHOSEN TABLE(S)! Are you sure?';
    private const CONFIRM_QUESTION_TWO = 'Think twice! One more time - ARE YOU SURE?';

    private const SQL_QUERY_DELETE_FROM = 'DELETE FROM %s';
    private const SQL_QUERY_ALTER = 'ALTER TABLE %s AUTO_INCREMENT = %s';

    protected function configure(): void
    {
        $this->addArgument(self::ARG_NAME, InputArgument::OPTIONAL, self::ARG_DESCRIPTION);
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $existingTables = $this->getAllDatabaseTables();

        $chosenTable = $input->getArgument(self::ARG_NAME)
            ?? $this->io->choice(
                'Which table(s) are being cleared?',
                array_merge([self::ALL_TABLES], $existingTables),
            );

        if ($chosenTable !== self::ALL_TABLES && !in_array($chosenTable, $existingTables)) {
            throw new InvalidArgumentException(
                sprintf('Table %s doesn’t exist in database. Try another table.', $chosenTable),
            );
        }

        // Show RED WARNING
        $this->io->writeln(
            sprintf(
                "\e[5m <danger>  %s </danger> \e[0m",
                str_repeat(self::MESSAGE_WARNING, 3),
            ),
        );

        $answer = $this->io->confirm(self::CONFIRM_QUESTION_ONE, false);
        $answer && $answer = $this->io->confirm(self::CONFIRM_QUESTION_TWO, false);

        // Go deeper if the user twice answered YES
        if ($answer) {
            $clearingTables = $chosenTable == self::ALL_TABLES
                ? $existingTables
                : [$chosenTable];

            // Build config from the DB tables directory
            new Config(self::CONFIG_DIR_TABLES);

            foreach ($clearingTables as $table) {
                // $table comes from getAllDatabaseTables() (validated above), not user text.
                $this->connection()->execute(sprintf(self::SQL_QUERY_DELETE_FROM, $table));

                // Altering AUTO_INCREMENT if needed
                $autoIncrement = Config::get(sprintf('%s.auto_increment', $table));

                if (!empty($autoIncrement)) {
                    $this->connection()->execute(sprintf(self::SQL_QUERY_ALTER, $table, (int) $autoIncrement));
                }
            }

            $this->io->success(self::MESSAGE_DONE);
        }

        $this->io->newLine();

        return Command::SUCCESS;
    }
}
