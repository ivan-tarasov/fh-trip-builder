<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled run records DB table
    |--------------------------------------------------------------------------
    |
    | When each scheduled command last started, when it last worked, and what it
    | said. One row per command, written by `schedule:run`.
    |
    | The schedule itself is not here -- that is `scheduled_jobs`, edited from
    | `/admin/schedule` (G19, #377; before that `config/noah/schedule.php`, in
    | git, which was the whole point of E16, #167). This table is only what
    | happened, one row per command. `schedule_run_history` (G19) keeps every
    | attempt; this one keeps only the latest, which is what `Schedule::due()`
    | and `health()` both need.
    |
    | `last_run_at` and `last_success_at` are two columns on purpose, and the
    | difference is the entire value of the table.
    |
    | `last_run_at` is stamped when a task *starts*. It answers "is this due"
    | and it is the overlap guard: a task still running is not picked up again
    | by the next tick fifteen minutes later, and one whose process died
    | mid-run does not retry in a loop until somebody notices.
    |
    | `last_success_at` is stamped only on exit code 0, and it is what staleness
    | means in E16.2 (#169). A command that fails every night has a fresh
    | `last_run_at` and a rotting `last_success_at`; a single column would have
    | reported that as healthy, which is the failure this table exists to make
    | visible.
    |
    | Keyed by the command as written in the schedule, arguments and all, so
    | `db:prune --force` and a future `db:prune --dry` are two rows rather than
    | one row that disagrees with itself.
    |
    */

    'primary' => 'command',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'command',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The command as the schedule spells it, arguments included',
        ],
        [
            'name' => 'last_run_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'When it last started -- the due check and the overlap guard',
        ],
        [
            'name' => 'last_success_at',
            'type' => 'datetime',
            'length' => null,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'When it last exited 0 -- what staleness is measured against',
        ],
        [
            'name' => 'last_exit',
            'type' => 'smallint',
            'length' => 5,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Exit code of the last run',
        ],
    ],
];
