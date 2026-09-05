<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Db;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

/**
 * Apply the schema changes `app:install` cannot make.
 *
 * The installer is declarative and additive: it reads the table configs and
 * adds whatever is missing. That covers a new table, a new column and a new
 * index, and nothing else. It cannot drop a column, change a type or a length,
 * alter a primary key, add a UNIQUE or a foreign key, or convert a charset --
 * and because it compares column *names* only, two branches that add the same
 * column with different types leave two databases silently disagreeing with
 * nothing able to detect it.
 *
 * So anything the installer cannot express is written here as a numbered
 * migration and recorded once it has run. `schema_migrations` is the first
 * thing in this project that says what state a database is actually in, rather
 * than re-deriving a guess from information_schema on every run.
 *
 * A migration is a PHP file returning a list of SQL statements, which is the
 * same shape the table configs use and needs no SQL parser:
 *
 *     // config/noah/db/migrations/0001_widen_status.php
 *     return ['ALTER TABLE bookings MODIFY status VARCHAR(32) NOT NULL'];
 *
 * Statements run in file order and each migration is recorded only once all of
 * its statements have succeeded. They are deliberately **not** wrapped in a
 * transaction: MySQL commits implicitly on DDL, so a rollback would be a
 * promise this cannot keep. A migration that fails halfway stops the run and
 * stays unrecorded -- write them so that re-running the whole file is safe.
 */
#[AsCommand(
    name: 'db:migrate',
    description: 'Apply schema changes the installer cannot make.',
    aliases: ['migrate'],
    hidden: false,
)]
class Migrate extends AbstractCommand
{
    private const string MIGRATIONS_DIR = 'config/noah/db/migrations';
    private const string TABLE = 'schema_migrations';

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List what would run without applying anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ensureTable();

        $applied = $this->applied();
        $pending = array_values(array_filter(
            $this->available(),
            static fn(string $version): bool => !in_array($version, $applied, true),
        ));

        $this->formatOutput('Applied', (string) count($applied), 'info');
        $this->formatOutput('Pending', (string) count($pending), $pending === [] ? 'info' : 'comment');

        if ($pending === []) {
            $this->io->success('Schema is up to date.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $this->io->listing($pending);
            $this->io->note('Dry run: nothing was applied.');

            return Command::SUCCESS;
        }

        foreach ($pending as $version) {
            if (!$this->apply($version)) {
                return Command::FAILURE;
            }
        }

        $this->io->success(sprintf('Applied %d migration(s).', count($pending)));

        return Command::SUCCESS;
    }

    /**
     * Run one migration and record it, or report why it stopped.
     */
    private function apply(string $version): bool
    {
        $statements = require $this->path($version);
        $action = sprintf('Applying `%s`', $version);

        if (!is_array($statements)) {
            $this->formatOutput($action, 'invalid', 'danger');
            $this->io->error(sprintf('%s must return an array of SQL statements.', $version));

            return false;
        }

        foreach ($statements as $sql) {
            try {
                $this->connection()->pdo()->exec((string) $sql);
            } catch (Throwable $e) {
                $this->formatOutput($action, 'failed', 'danger');
                // Named, so a half-applied migration says which statement to
                // pick up from -- the run stops here and stays unrecorded.
                $this->io->error(sprintf('%s failed: %s', $version, $e->getMessage()));

                return false;
            }
        }

        $this->connection()->execute(
            'INSERT INTO ' . self::TABLE . ' (version, applied_at) VALUES (?, NOW())',
            [$version],
        );

        $this->formatOutput($action, 'applied', 'success');

        return true;
    }

    /**
     * The ledger itself, which no config file can declare -- it has to exist
     * before anything can be recorded in it.
     */
    private function ensureTable(): void
    {
        $this->connection()->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . ' version VARCHAR(191) NOT NULL,'
            . ' applied_at DATETIME NOT NULL,'
            . ' PRIMARY KEY (version)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * @return list<string>
     */
    private function applied(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['version'],
            $this->connection()->fetchAll('SELECT version FROM ' . self::TABLE),
        );
    }

    /**
     * Every migration on disk, in the order their names sort -- which is why
     * they are numbered rather than named after the day they were written.
     *
     * @return list<string>
     */
    private function available(): array
    {
        $files = glob($this->directory() . '/*.php') ?: [];
        sort($files);

        return array_map(static fn(string $f): string => basename($f, '.php'), $files);
    }

    private function path(string $version): string
    {
        return sprintf('%s/%s.php', $this->directory(), $version);
    }

    private function directory(): string
    {
        return Helper::getRootDir() . '/' . self::MIGRATIONS_DIR;
    }
}
