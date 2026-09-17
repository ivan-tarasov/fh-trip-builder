<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled jobs DB table
    |--------------------------------------------------------------------------
    |
    | What `schedule:run` runs and when -- the live schedule itself, editable
    | from `/admin/schedule` (G19, #377). Replaces `config/noah/schedule.php`:
    | before this, adding or changing a scheduled command was a pull request
    | and a deploy; now it is a row in this table, and it takes effect on the
    | very next tick.
    |
    | Seeded once, by `config/noah/db/migrations/0008_seed_scheduled_jobs.php`,
    | with the four jobs the static file used to carry -- not by the CSV
    | seeder, which re-applies on every `app:install` and would silently
    | overwrite an operator's edit back to the default.
    |
    | The five cron columns are the same five fields `Cron::fromFields()`
    | already takes, named the same way, so a row here reads against the old
    | config's own comments without translation.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'indexes' => [
        ['name' => 'command', 'columns' => ['command'], 'unique' => true],
    ],

    'columns' => [
        [
            'name' => 'id',
            'type' => 'int',
            'length' => 11,
            'default' => false,
            'nullable' => false,
            'auto_inc' => true,
            'comment' => false,
        ],
        [
            'name' => 'command',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'As Run.php spells it: the noah command and its arguments together',
        ],
        [
            'name' => 'minute',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'hour',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'day',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'month',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'weekday',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'enabled',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [1],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A disabled job is kept, not deleted -- it drops out of due() but stays editable',
        ],
        [
            'name' => 'created',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'updated',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Set explicitly with NOW() on every write -- no ON UPDATE clause here',
        ],
    ],
];
