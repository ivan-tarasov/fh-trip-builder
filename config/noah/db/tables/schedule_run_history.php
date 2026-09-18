<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schedule run history DB table
    |--------------------------------------------------------------------------
    |
    | Every attempt, kept -- unlike `schedule_runs`, whose `command` primary key
    | overwrites in place and can only ever say what happened *last*. This
    | table exists because "real run history" was the whole point of G19
    | (#377): `schedule_runs` alone would have given a dedicated page nothing
    | further to show than Overview's own four rows.
    |
    | `started_at` is written by `historyStarted()` before a command runs and
    | `finished_at`/`exit_code` by `historyFinished()` once it has, the same
    | two-phase shape `schedule_runs` itself uses and for the same reason: a
    | row still showing `started_at` with nothing else set is a visible sign of
    | a run that crashed or was killed, not a run silently missing from the log.
    |
    | `DATETIME(3)`, not the plain `DATETIME` `schedule_runs` uses -- this is
    | the only table `AdminController::runDuration()` reads, and a command
    | that finishes in the same wall-clock second it started needs the
    | milliseconds to show anything other than "0s".
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'indexes' => [
        // Every read is one command's history, newest first.
        ['name' => 'command_started', 'columns' => ['command', 'started_at']],
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
            'comment' => false,
        ],
        [
            'name' => 'started_at',
            'type' => 'datetime',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'finished_at',
            'type' => 'datetime',
            'length' => 3,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Null while still running, or if the process never came back',
        ],
        [
            'name' => 'exit_code',
            'type' => 'smallint',
            'length' => 5,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Null until historyFinished() records it',
        ],
    ],
];
