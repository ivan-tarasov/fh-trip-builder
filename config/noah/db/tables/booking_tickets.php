<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking tickets
    |--------------------------------------------------------------------------
    |
    | One row per travel document -- a ticket, an EMD -- rather than a column
    | on `booking_passengers`. A passenger can hold more than one: a ticket
    | and an EMD, or a reissue after a schedule change, and only a table can
    | say whose (G8.3, #338).
    |
    | Fake numbers. This app has no real GDS or ticketing integration behind
    | it, so a document here is whatever the operator typed in, not something
    | a supplier issued.
    |
    | Linked to `booking_passengers`, not `bookings` -- the document belongs
    | to the traveller, the same reasoning `booking_passengers`' own docblock
    | gives for a ticket number, a seat and a bag.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'indexes' => [
        // Every read is one booking's tickets, joined through the passenger.
        ['name' => 'booking_passenger', 'columns' => ['booking_passenger_id']],
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
            'name' => 'booking_passenger_id',
            'type' => 'int',
            'length' => 9,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Joins booking_passengers.id',
        ],
        [
            // A DocumentType case. Not an enum column, same reasoning as
            // `booking_events.event`: a new document type should not need a
            // migration.
            'name' => 'document_type',
            'type' => 'varchar',
            'length' => 16,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A DocumentType case',
        ],
        [
            'name' => 'document_number',
            'type' => 'varchar',
            'length' => 20,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Whatever the operator typed in -- no real GDS behind this',
        ],
        [
            'name' => 'status',
            'type' => 'varchar',
            'length' => 16,
            'charset' => 'ascii',
            'default' => 'issued',
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A TicketStatus case',
        ],
        [
            'name' => 'issue_date',
            'type' => 'date',
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
