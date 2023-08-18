<?php

namespace TripBuilder\Noah\Db;

use Symfony\Component\Console\Command\Command;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Config;

class Install extends AbstractCommand
{
    /**
     * The name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'install';

    /**
     * The command description shown when running `list` command.
     *
     * @var string
     */
    protected static $defaultDescription = 'Installing necessary database tables and seeding it with data';

    const CONFIG_DIR_TABLES = 'noah/db/tables';
    const CONFIG_DIR_SEEDERS = 'noah/db/seeders';

    const MESSAGE_CREATING_TABLE = 'Creating `%s` table',
          MESSAGE_SEEDING_TABLE  = 'Seeding `%s` table';

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
        // Creating DB tables
        $this->createTables();

        // Seeding database tables
        $this->seedingTables();

        $this->io->newLine();

        return Command::SUCCESS;
    }

    /**
     * @return void
     */
    private function createTables(): void
    {
        // Build config from DB tables directory
        new Config(self::CONFIG_DIR_TABLES);

        // Creating DB tables
        foreach (Config::get() as $table => $data) {
            $action = sprintf(self::MESSAGE_CREATING_TABLE, $table);

            if ($this->db->tableExists($table)) {
                $this->formatOutput($action, 'exist', 'info');
                continue;
            }

            $query = sprintf(
                'CREATE TABLE %s (%s, PRIMARY KEY (%s)) ENGINE=%s DEFAULT CHARSET=%s;',
                $table,
                implode(', ', array_map(function ($column) {
                    return sprintf(
                        '%s %s%s%s%s%s%s',
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
                            : null
                    );
                }, $data['columns'])),
                $data['primary'],
                $data['engine'],
                $data['charset']
            );

            $this->db->rawQueryOne($query);

            if ($this->db->getLastErrno() === 0) {
                $this->formatOutput($action, 'created', 'success');
            } else {
                $this->formatOutput($action, 'failed', 'danger');
            }
        }

        $this->io->newLine();
    }

    /**
     * @return void
     */
    private function seedingTables(): void
    {
        // Build config from DB tables directory
        new Config(self::CONFIG_DIR_SEEDERS);

        foreach (Config::get() as $table => $data) {
            $action = sprintf(self::MESSAGE_SEEDING_TABLE, $table);

            $columns = $data['columns'];

            foreach ($data['seeds'] as $seed) {
                // If table already seeded – updating data from seed array
                $this->db->onDuplicate($columns);

                $values = array_pad($seed, count($columns), null);

                $id = $this->db->insert($table, array_combine($columns, $values));

                if (! $id) {
                    // $this->db->getLastError();
                    $this->formatOutput($action, 'failed', 'danger');
                    break;
                }
            }

            $this->formatOutput($action, 'done', 'success');
        }
    }

}