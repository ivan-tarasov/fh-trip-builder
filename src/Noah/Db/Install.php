<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Db;

use Exception;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: 'app:install',
    description: 'Installing necessary database tables and seeding it with data.',
    aliases: ['install', 'setup', 'app:setup'],
    hidden: false,
)]

class Install extends AbstractCommand
{
    private const string MESSAGE_CREATING_TABLE = 'Creating `%s` table';
    private const string MESSAGE_SEEDING_TABLE = 'Seeding `%s` table';
    private const string MESSAGE_ADDING_COLUMN = 'Adding `%s`.`%s` column';
    private const string MESSAGE_ADDING_INDEX = 'Adding `%s`.`%s` index';

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->createTables();
        $this->seedingTables();

        $this->io->newLine();

        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function createTables(): void
    {
        new Config(self::CONFIG_DIR_TABLES);

        // Creating DB tables
        foreach (Config::get() as $table => $data) {
            $action = sprintf(self::MESSAGE_CREATING_TABLE, $table);

            if ($this->tableExists($table)) {
                $this->formatOutput($action, 'exist', 'info');
                // The config is the declared shape of the table, so a column
                // added to it should reach a database that predates it -- there
                // is no migration runner here, and seeding would otherwise fail
                // on a column the CSV names but the table has never had.
                $this->addMissingColumns($table, $data['columns']);
                // Same reasoning for indexes: indexClause() only runs inside
                // CREATE TABLE, so a declared index has never reached a
                // database that already had the table.
                $this->addMissingIndexes($table, $data['indexes'] ?? []);
                continue;
            }

            $query = sprintf(
                'CREATE TABLE %s (%s, PRIMARY KEY (%s)%s) ENGINE=%s DEFAULT CHARSET=%s%s;',
                $table,
                implode(', ', array_map(
                    fn(array $column): string => $this->columnDefinition($column),
                    $data['columns'],
                )),
                $data['primary'],
                $this->indexClause($data['indexes'] ?? []),
                $data['engine'],
                $data['charset'],
                isset($data['auto_increment'])
                    ? ' AUTO_INCREMENT=' . $data['auto_increment']
                    : null,
            );

            try {
                $this->connection()->pdo()->exec($query);
                $this->formatOutput($action, 'created', 'success');
            } catch (Throwable $e) {
                $this->formatOutput($action, 'failed', 'danger');
                // Every other failure in this file prints its reason; this one
                // swallowed it, which is what made the bug above so hard to see.
                $this->io->error(sprintf('Creating `%s` failed: %s', $table, $e->getMessage()));
            }
        }

        $this->io->newLine();
    }

    /**
     * One column's DDL, shared by CREATE TABLE and ADD COLUMN.
     *
     * @param array<string, mixed> $column
     */
    private function columnDefinition(array $column): string
    {
        return sprintf(
            '`%s` %s%s%s%s%s%s%s',
            $column['name'],
            strtoupper($column['type']),
            $column['length']
                ? sprintf('(%s)', $column['length'])
                : null,
            // Right after the type, which is where MySQL wants it. A column
            // holding an IATA code or a hex digest is ASCII by definition, and
            // in the table's utf8mb4 it would reserve four bytes per character
            // -- in the clustered key and in every index built on it.
            isset($column['charset'])
                ? sprintf(' CHARACTER SET %s', $column['charset'])
                : null,
            $this->defaultClause($column),
            $column['nullable']
                ? null
                : ' NOT NULL',
            $column['comment']
                ? sprintf(' COMMENT "%s"', $column['comment'])
                : null,
            $column['auto_inc']
                ? ' AUTO_INCREMENT'
                : null,
        );
    }

    /**
     * A column's DEFAULT clause, or null when it has none.
     *
     * `false` and `null` both spell "no default". Everything else is one --
     * including 0, which the truthiness test this replaced treated as absent, so
     * three columns whose config asked for a default of 0 were created without
     * one and any insert omitting them failed with 1364.
     *
     * @param array<string, mixed> $column
     */
    private function defaultClause(array $column): ?string
    {
        // `??` folds a declared null into false, so both spellings of "no
        // default" arrive here as false and there is one case to test.
        $default = $column['default'] ?? false;

        if ($default === false) {
            return null;
        }

        // An array wraps raw SQL: [0] emits DEFAULT 0 and ['CURRENT_TIMESTAMP']
        // emits the keyword, where quoting either would store it as a string.
        if (is_array($default)) {
            return sprintf(' DEFAULT %s', (string) $default[0]);
        }

        return is_string($default)
            ? sprintf(' DEFAULT "%s"', $default)
            : sprintf(' DEFAULT %s', (string) $default);
    }

    /**
     * Add any column the config declares that the table does not have yet.
     *
     * Additive only: it never drops, reorders or retypes a column, so running
     * it against a table that has drifted for any other reason is a no-op. Each
     * one lands in its declared position, so a table built by this path column
     * by column ends up shaped like one created in a single statement.
     *
     * @param list<array<string, mixed>> $columns
     */
    private function addMissingColumns(string $table, array $columns): void
    {
        $existing = array_column(
            $this->connection()->fetchAll(
                'SELECT column_name AS name FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            ),
            'name',
        );

        // Nothing to compare against: leave the table alone rather than trying
        // to add every column it already has.
        if ($existing === []) {
            return;
        }

        $previous = null;

        foreach ($columns as $column) {
            $name = (string) $column['name'];

            if (in_array($name, $existing, true)) {
                $previous = $name;
                continue;
            }

            $action = sprintf(self::MESSAGE_ADDING_COLUMN, $table, $name);

            try {
                $this->connection()->pdo()->exec(sprintf(
                    'ALTER TABLE `%s` ADD COLUMN %s%s',
                    $table,
                    $this->columnDefinition($column),
                    // Keeps the declared order: the first column goes to the
                    // front, the rest follow whichever column precedes them.
                    $previous === null ? ' FIRST' : sprintf(' AFTER `%s`', $previous),
                ));
            } catch (Throwable $e) {
                $this->formatOutput($action, 'failed', 'danger');
                $this->io->error(sprintf('Adding `%s`.`%s` failed: %s', $table, $name, $e->getMessage()));

                return;
            }

            $this->formatOutput($action, 'added', 'success');
            $previous = $name;
        }
    }

    /**
     * Add declared indexes a table is missing.
     *
     * Additive only: an index the config no longer names is left alone rather
     * than dropped, because a running database may have picked one up for a
     * reason this file does not know about.
     *
     * @param list<array<string, mixed>> $indexes
     */
    private function addMissingIndexes(string $table, array $indexes): void
    {
        if ($indexes === []) {
            return;
        }

        $existing = array_column(
            $this->connection()->fetchAll(
                'SELECT DISTINCT index_name AS name FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            ),
            'name',
        );

        foreach ($indexes as $index) {
            $name = (string) $index['name'];

            if (in_array($name, $existing, true)) {
                continue;
            }

            $action = sprintf(self::MESSAGE_ADDING_INDEX, $table, $name);

            try {
                $this->connection()->pdo()->exec(sprintf(
                    'ALTER TABLE `%s` ADD %s `%s` (%s)',
                    $table,
                    ($index['unique'] ?? false) ? 'UNIQUE KEY' : 'KEY',
                    $name,
                    implode(', ', array_map(
                        static fn(string $column): string => sprintf('`%s`', $column),
                        $index['columns'],
                    )),
                ));
            } catch (Throwable $e) {
                $this->formatOutput($action, 'failed', 'danger');
                $this->io->error(sprintf('Adding `%s`.`%s` failed: %s', $table, $name, $e->getMessage()));

                return;
            }

            $this->formatOutput($action, 'added', 'success');
        }
    }

    private function tableExists(string $table): bool
    {
        // DATABASE(), not $_ENV: Connection reads its credentials with getenv()
        // first, and phpdotenv's immutable loader will not copy a variable into
        // $_ENV when the process environment already has it. On any host where
        // DB_* are real environment variables this read returned '', every
        // table looked absent, and the whole additive-migration path below
        // silently never ran. The two sibling queries in this file already ask
        // the connection.
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ) > 0;
    }

    /**
     * Seed every table from its CSV file (header row = columns, one data row
     * per record; an empty cell is stored as NULL).
     */
    private function seedingTables(): void
    {
        $directory = sprintf('%s/config/%s', Helper::getRootDir(), self::CONFIG_DIR_SEEDERS);

        foreach (glob($directory . '/*.csv') as $file) {
            $table = pathinfo($file, PATHINFO_FILENAME);
            $action = sprintf(self::MESSAGE_SEEDING_TABLE, $table);

            $handle = fopen($file, 'r');
            $columns = fgetcsv($handle, null, ',', '"', '');
            $sql = $this->seedStatement($table, $columns);
            $failed = false;

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                // An empty cell means NULL; pad short rows to the column count.
                $values = array_pad(
                    array_map(static fn(?string $value): ?string => $value === '' ? null : $value, $row),
                    count($columns),
                    null,
                );

                try {
                    // Insert, or refresh the row's columns if it already exists.
                    $this->connection()->execute($sql, $values);
                } catch (Throwable $e) {
                    $failed = true;
                    $this->formatOutput($action, 'failed', 'danger');
                    // Without the reason a failed seed is undiagnosable on a
                    // server you cannot step through.
                    $this->io->error(sprintf('Seeding `%s` failed: %s', $table, $e->getMessage()));
                    break;
                }
            }

            fclose($handle);

            if (!$failed) {
                $this->formatOutput($action, 'done', 'success');
            }
        }
    }

    /**
     * Build the `, KEY ...` fragment for a table's secondary indexes.
     *
     * @param list<array{name: string, columns: list<string>, unique?: bool}> $indexes
     */
    private function indexClause(array $indexes): string
    {
        $clause = '';

        foreach ($indexes as $index) {
            $columns = implode(', ', array_map(static fn(string $c): string => "`$c`", $index['columns']));
            $clause .= sprintf(
                ', %s `%s` (%s)',
                ($index['unique'] ?? false) ? 'UNIQUE KEY' : 'KEY',
                $index['name'],
                $columns,
            );
        }

        return $clause;
    }

    /**
     * Build the seed upsert for a table: insert, or refresh every column on a
     * duplicate key (MySQL 8 row-alias syntax, avoiding the deprecated VALUES()).
     *
     * @param list<string> $columns
     */
    private function seedStatement(string $table, array $columns): string
    {
        $quoted = array_map(static fn(string $c): string => "`$c`", $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // The `VALUES (...) AS new` row alias only exists in MySQL 8.0.19+, and
        // not in MariaDB at all — which is what many shared hosts run. Fall back
        // to VALUES(col), which every version understands (it is deprecated on
        // newer MySQL, hence preferring the alias when it is available).
        if ($this->supportsRowAlias()) {
            $updates = implode(', ', array_map(static fn(string $c): string => "`$c` = new.`$c`", $columns));

            return "INSERT INTO `$table` (" . implode(', ', $quoted) . ")"
                . " VALUES ($placeholders) AS new"
                . " ON DUPLICATE KEY UPDATE $updates";
        }

        $updates = implode(', ', array_map(static fn(string $c): string => "`$c` = VALUES(`$c`)", $columns));

        return "INSERT INTO `$table` (" . implode(', ', $quoted) . ")"
            . " VALUES ($placeholders)"
            . " ON DUPLICATE KEY UPDATE $updates";
    }

    /**
     * Whether the server understands the `VALUES (...) AS alias` form.
     */
    private function supportsRowAlias(): bool
    {
        static $supported = null;

        if ($supported !== null) {
            return $supported;
        }

        $version = (string) $this->connection()->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return $supported = !str_contains(strtolower($version), 'mariadb')
            && version_compare($version, '8.0.19', '>=');
    }
}
