<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Db;

use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: self::NAME,
    description: 'Purge data from database tables.',
    aliases: ['database:clear', 'mysql:clear'],
    hidden: false,
)]

class Clear extends AbstractCommand
{
    public const string NAME = 'db:clear';

    private const string ARG_NAME = 'table';
    private const string ARG_DESCRIPTION = 'Database table to clear';

    private const string OPT_NO_BACKUP = 'no-backup';

    private const string MESSAGE_WARNING = 'WARNING!!! ';
    private const string MESSAGE_DONE = 'Table(s) was successfully purged';

    private const string ALL_TABLES = 'all';

    private const string CONFIRM_QUESTION_ONE = 'You\'re about to purge ALL DATA from CHOSEN TABLE(S)! Are you sure?';
    private const string CONFIRM_QUESTION_TWO = 'Think twice! One more time - ARE YOU SURE?';

    private const string SQL_QUERY_DELETE_FROM = 'DELETE FROM %s';
    private const string SQL_QUERY_ALTER = 'ALTER TABLE %s AUTO_INCREMENT = %s';

    protected function configure(): void
    {
        $this->addArgument(self::ARG_NAME, InputArgument::OPTIONAL, self::ARG_DESCRIPTION);
        $this->addOption(
            self::OPT_NO_BACKUP,
            null,
            InputOption::VALUE_NONE,
            'Skip the backup. For data you know is disposable.',
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $existingTables = $this->getAllDatabaseTables();

        /** @var string $chosenTable */
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

        if (!$answer) {
            $this->io->newLine();

            return Command::SUCCESS;
        }

        // Two confirmations establish that you meant it. They do not establish
        // that you can undo it, which is what this is for (E18, #176).
        if (!$input->getOption(self::OPT_NO_BACKUP) && !$this->backedUp($output)) {
            $this->io->error('No backup was taken, so nothing was cleared. Use --no-backup to clear anyway.');

            return Command::FAILURE;
        }

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
        $this->io->newLine();

        return Command::SUCCESS;
    }

    /**
     * Take a dump first, and say so if it could not be taken.
     *
     * Failing closed: a `db:clear` that could not back up stops rather than
     * deleting. The flag is there for the case where the data is genuinely
     * disposable and somebody knows it, which is not the same as the backup
     * quietly not happening.
     */
    private function backedUp(OutputInterface $output): bool
    {
        $application = $this->getApplication();

        if ($application === null) {
            return false;
        }

        try {
            return $application->find(Backup::NAME)->run(new ArrayInput([]), $output) === Command::SUCCESS;
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return false;
        }
    }
}
