<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking remarks
    |--------------------------------------------------------------------------
    |
    | A note an operator leaves for whoever looks at this booking next,
    | append-only like `booking_events` and for the same reason: a note
    | somebody can edit or delete after the fact is not a trustworthy one
    | (G8.2, #337).
    |
    | Unlike `booking_events`, `body` is the whole content rather than a
    | secondary detail beside a named event, which is why it is `text` rather
    | than a 190-character `varchar`.
    |
    | `actor` is the kind of party, not a person, same as `booking_events` --
    | in practice always `operator`, since only the panel has this form.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'indexes' => [
        // Every read is one booking's remarks, newest first.
        ['name' => 'booking_created', 'columns' => ['booking_id', 'created']],
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
            'name' => 'booking_id',
            'type' => 'int',
            'length' => 9,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'actor',
            'type' => 'varchar',
            'length' => 16,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'visitor or operator -- a kind, not a person',
        ],
        [
            // A RemarkTone case. Not an enum column, same reasoning as
            // `booking_events.event`: a new tone should not need a migration.
            'name' => 'tone',
            'type' => 'varchar',
            'length' => 16,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'What kind of note this is, as a RemarkTone case',
        ],
        [
            'name' => 'body',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
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
    ],
];
