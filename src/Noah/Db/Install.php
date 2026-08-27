<?php

namespace TripBuilder\Noah\Db;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Config;

#[AsCommand(
    name: 'app:install',
    description: 'Installing necessary database tables and seeding it with data.',
    aliases: ['install', 'setup', 'app:setup'],
    hidden: false,
)]

class Install extends AbstractCommand
{
    private const MESSAGE_CREATING_TABLE = 'Creating `%s` table';
    private const MESSAGE_SEEDING_TABLE  = 'Seeding `%s` table';

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

            if ($this->db->tableExists([$table])) {
                $this->formatOutput($action, 'exist', 'info');
                continue;
            }

            $query = sprintf(
                'CREATE TABLE %s (%s, PRIMARY KEY (%s)) ENGINE=%s DEFAULT CHARSET=%s%s;',
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
                            : null
                    );
                }, $data['columns'])),
                $data['primary'],
                $data['engine'],
                $data['charset'],
                isset($data['auto_increment'])
                    ? ' AUTO_INCREMENT=' . $data['auto_increment']
                    : null
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
     * @throws Exception
     */
    private function seedingTables(): void
    {
        new Config(self::CONFIG_DIR_SEEDERS);

        foreach (Config::get() as $table => $data) {
            $action = sprintf(self::MESSAGE_SEEDING_TABLE, $table);

            $columns = $data['columns'];
            $failed = false;

            foreach ($data['seeds'] as $seed) {
                // If table already seeded – updating data from a seed array
                $this->db->onDuplicate($columns);

                $values = array_pad($seed, count($columns), null);

                $id = $this->db->insert($table, array_combine($columns, $values));
                if (!$id) {
                    $failed = true;
                    $this->formatOutput($action, 'failed', 'danger');
                    break;
                }
            }

            if (! $failed) {
                $this->formatOutput($action, 'done', 'success');
            }
        }
    }
}
