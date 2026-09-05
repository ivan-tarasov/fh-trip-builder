<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Booking passengers DB table
    |--------------------------------------------------------------------------
    |
    | Everyone travelling on one booking, one row each. `bookings` held a single
    | traveller in four columns and its own comment anticipated this: a second
    | one turns them into rows of their own rather than a wider table.
    |
    | A table rather than JSON on the booking, because everything that happens
    | after a booking is per passenger — a ticket number is issued to one
    | traveller, check-in and seats and bags belong to one, and a name
    | correction or a cancellation can apply to one of four. Each of those is a
    | column here later and a WHERE; in JSON none of it could be looked up.
    |
    | The lead passenger is still written to `bookings.passenger_*` as well. That
    | is deliberate: the bookings list renders a name for every row and reads it
    | in one query, so keeping the lead on the parent saves a join on the only
    | page that shows many bookings at once.
    |
    | Keyed by its own id, like `bookings` and `flights` and unlike the reference
    | tables, which key on an immutable external code. A passenger is a record
    | other records will point at: a ticket number, a seat, a bag and a check-in
    | each belong to one traveller. A composite (booking_id, position) key would
    | make every one of those carry two columns, and position is not stable —
    | remove one traveller from a party of four and the rest either go sparse or
    | get renumbered, silently repointing anything that referenced position 3.
    |
    | (booking_id, position) is indexed rather than unique because the installer
    | emits only KEY. Only createFor() writes these rows, and it writes them in
    | sequence, so the pairing holds in practice.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    'indexes' => [
        // Every read is one booking's travellers in the order they were entered.
        ['name' => 'booking_position', 'columns' => ['booking_id', 'position']],
    ],

    'columns' => [
        [
            'name' => 'id',
            'type' => 'int',
            'length' => 9,
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
            'comment' => 'Joins bookings.id',
        ],
        [
            'name' => 'position',
            'type' => 'tinyint',
            'length' => 2,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Order entered, 1 is the lead passenger',
        ],
        [
            'name' => 'type',
            'type' => 'char',
            'length' => 1,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A adult, C child, I infant on lap',
        ],
        [
            'name' => 'first_name',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'As printed on the travel document',
        ],
        [
            'name' => 'last_name',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'As printed on the travel document',
        ],
        [
            'name' => 'dob',
            'type' => 'date',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Date of birth',
        ],
        [
            'name' => 'gender',
            'type' => 'char',
            'length' => 1,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'F M X',
        ],
    ],
];
