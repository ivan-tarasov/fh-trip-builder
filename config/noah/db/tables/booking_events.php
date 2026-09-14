<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking events
    |--------------------------------------------------------------------------
    |
    | What has happened to one booking, in order, append-only. Nothing here is
    | ever updated or deleted except with the booking itself (A3.8, #233).
    |
    | **It records what the application does, not what a visitor did.** The
    | steps before a booking exists -- the searches, the options looked at, the
    | checkout form being filled -- are not linked to anything: `search` is an
    | aggregate keyed by a hash of the query, with a count and a last-seen and
    | no session and no booking. A log claiming to show "every step" would be
    | inventing most of it. What is true and worth keeping is every moment the
    | row changed, and who changed it.
    |
    | **And it begins the day it is installed.** A booking made before this
    | table existed has no events, and none can be recovered. The page says so
    | rather than drawing an empty timeline; the `bookings.created` column is
    | shown alongside as the one fact about the past that is not a guess.
    |
    | `actor` is the kind of party, not a person: there is one operator and no
    | accounts table, so naming them would be naming the only name there is.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'indexes' => [
        // Every read is one booking's events, oldest first.
        ['name' => 'booking_at', 'columns' => ['booking_id', 'at']],
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
            // A BookingEvent case. Not an enum column: this schema has none,
            // and a new kind of event should not need a migration.
            'name' => 'event',
            'type' => 'varchar',
            'length' => 32,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'What happened, as a BookingEvent case',
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
            // Free text for the one thing the event kind cannot carry: which
            // way a status went, or what an operator was doing. Never personal
            // data -- this table outlives nothing that `db:prune` sweeps.
            'name' => 'note',
            'type' => 'varchar',
            'length' => 190,
            'default' => '',
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
