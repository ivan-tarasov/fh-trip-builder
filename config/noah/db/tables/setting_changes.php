<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setting changes DB table
    |--------------------------------------------------------------------------
    |
    | Every time an override was written or removed, append-only, the same
    | shape `booking_events` uses for a row that must never be edited after
    | the fact (A3.7, #232).
    |
    | `old_value`/`new_value` are both nullable, and null means something
    | different on each side: null in `old_value` is "this key had no override
    | before now"; null in `new_value` is "this change reset it back to the
    | config default". A change that both sets and later resets a key is two
    | rows, not one that overwrites itself -- the point of a log is that
    | nothing in it moves.
    |
    | No actor column, unlike `booking_events`: there is one operator and no
    | accounts table, so it would name the only name there is.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'indexes' => [
        // Every read is one key's history, oldest first.
        ['name' => 'setting_key_changed_at', 'columns' => ['setting_key', 'changed_at']],
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
            'name' => 'setting_key',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'old_value',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'JSON-encoded; null when the key had no override before this change',
        ],
        [
            'name' => 'new_value',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'JSON-encoded; null when this change reset the key to its default',
        ],
        [
            'name' => 'changed_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
