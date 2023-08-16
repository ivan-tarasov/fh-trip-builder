<?php

namespace TripBuilder\Noah\Db;

use Dotenv\Dotenv;
use TripBuilder\Config;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;

class Install
{
    const CONFIG_DIR_TABLES = 'noah/db/tables';
    const CONFIG_DIR_SEEDERS = 'noah/db/seeders';

    protected $db;

    /**
     * @return void
     * @throws \Exception
     */
    public function run(): void
    {
        // Initializing DB connection
        $this->connectDb();

        // Creating DB tables
        $this->createTables();

        // Seeding database tables
        $this->seedingTables();
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
            if ($this->db->tableExists($table)) {
                echo 'EXIST' . PHP_EOL;

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

            echo $this->db->getLastErrno() === 0
                ? 'Table create successful' . PHP_EOL
                : 'Update failed. Error: ' . $this->db->getLastError() . PHP_EOL;
        }
    }

    private function seedingTables()
    {
        // Build config from DB tables directory
        new Config(self::CONFIG_DIR_SEEDERS);

        foreach (Config::get() as $table => $data) {
            $columns = $data['columns'];

            foreach ($data['seeds'] as $seed) {
                // If table already seeded – updating data from seed array
                $this->db->onDuplicate($columns);

                $values = array_pad($seed, count($columns), null);

                $id = $this->db->insert($table, array_combine($columns, $values));

                if (! $id) {
                    echo 'insert failed: ' . $this->db->getLastError();
                }
            }
        }
    }

    /**
     * @return void
     */
    private function connectDb(): void
    {
        $dotenv = Dotenv::createImmutable(Helper::getRootDir());
        $dotenv->load();

        $this->db = new \MysqliDb(
            '127.0.0.1', // FIXME: for some reason `localhost` not working on local machine
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            $_ENV['DB_DATABASE'],
            $_ENV['DB_PORT']
        );
    }

    public function __destruct()
    {
        // Disconnecting from DB
        $this->db->disconnect();
    }

}
