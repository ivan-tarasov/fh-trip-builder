<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settings DB table
    |--------------------------------------------------------------------------
    |
    | An override, one row per config key. Empty on every fresh install, which
    | is the whole point: `Settings::get()` reads this table first and falls
    | back to the config file's own value when a key has no row, so an install
    | nobody has touched behaves exactly as it always has (A3.7, #232).
    |
    | `value` is JSON rather than one column per type. What is settable ranges
    | from an int (`max_stops`) to a list of country codes (`gulf_countries`),
    | and a column per type would need a new one every time a different shape
    | became settable. JSON round-trips every one of them through the same
    | column, at the cost of a decode on every read -- one row, once per
    | process (see Settings).
    |
    | `setting_key` is the config path unchanged (`search.connections.max_stops`),
    | not a shorter code: it is the one string that already means something to
    | whoever reads `config/common/search.php`, and a second vocabulary for the
    | same keys would be a second thing to keep in step.
    |
    */

    'primary' => 'setting_key',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'columns' => [
        [
            'name' => 'setting_key',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A config path, e.g. search.connections.max_stops',
        ],
        [
            'name' => 'value',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'JSON-encoded, so one column holds every settable type',
        ],
        [
            'name' => 'updated_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
