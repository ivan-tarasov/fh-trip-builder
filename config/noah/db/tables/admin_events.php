<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin events
    |--------------------------------------------------------------------------
    |
    | What an operator has changed outside the two logs this panel already
    | has: bookings get `booking_events`, settings get `setting_changes`,
    | and content edits and a removed fare-alert address got nothing (G5.1,
    | #316). Append-only, the same reasoning `booking_events` gives.
    |
    | `resource`/`resource_id` name the thing changed -- a slug for content,
    | a numeric id (as a string) for a subscriber -- so a future reader can
    | ask "what happened to this one" without a second table per resource.
    |
    | No actor column, unlike `booking_events`: everything recorded here is
    | already an admin-only action, so there is no visitor/operator
    | distinction to make -- the same reasoning `setting_changes` gives for
    | leaving an actor out entirely.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'indexes' => [
        // A resource's own history, and the "everything, newest first" read
        // both want `at` last in the index.
        ['name' => 'resource_at', 'columns' => ['resource', 'resource_id', 'at']],
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
            // An AdminEventResource case. Not an enum column, the same
            // reasoning `booking_events.event` gives: a new resource should
            // not need a migration.
            'name' => 'resource',
            'type' => 'varchar',
            'length' => 32,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'What kind of thing changed, as an AdminEventResource case',
        ],
        [
            'name' => 'resource_id',
            'type' => 'varchar',
            'length' => 64,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A slug, or a numeric id as a string',
        ],
        [
            'name' => 'event',
            'type' => 'varchar',
            'length' => 32,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'What happened, as an AdminEvent case',
        ],
        [
            // Free text for the one thing the event kind cannot carry: which
            // article, or which address. Never personal data beyond what the
            // resource itself already was -- an email here is the same one
            // the fare-alert row held before it was removed.
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
