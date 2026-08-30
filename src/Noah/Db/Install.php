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
                continue;
            }

            $query = sprintf(
                'CREATE TABLE %s (%s, PRIMARY KEY (%s)%s) ENGINE=%s DEFAULT CHARSET=%s%s;',
                $table,
                implode(', ', array_map(function ($column) {
                    return sprintf(
                        '`%s` %s%s%s%s%s%s',
                        $column['name'],
                        strtoupper($column['type']),
                        $column['length']
                            ? sprintf('(%s)', $column['length'])
                            : null,
                        $column['default']
                            ? sprintf(' DEFAULT %s', is_array($column['default'])
                                ? $column['default'][0]
                                : sprintf('"%s"', $column['default']))
                            : null,
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
                }, $data['columns'])),
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
            }
        }

        $this->io->newLine();
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$_ENV['DB_DATABASE'] ?? '', $table],
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
     * @param list<array{name: string, columns: list<string>}> $indexes
     */
    private function indexClause(array $indexes): string
    {
        $clause = '';

        foreach ($indexes as $index) {
            $columns = implode(', ', array_map(static fn(string $c): string => "`$c`", $index['columns']));
            $clause .= sprintf(', KEY `%s` (%s)', $index['name'], $columns);
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
