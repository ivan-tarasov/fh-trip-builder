<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bookings DB table
    |--------------------------------------------------------------------------
    |
    | What checkout captured, alongside the itinerary itself. Passenger and
    | contact details sit here while a booking carries one traveller; a second
    | one turns them into rows of their own rather than a wider table.
    |
    | The money and the fare brand are snapshots. Prices move and fares are
    | rebranded, so what was agreed at purchase has to be recorded rather than
    | looked up again later — `fare_brand` holds the name the fare was sold
    | under, not a key back into `fare_brands`.
    |
    | `card_brand` and `card_last4` are all the card that is ever kept. The
    | number itself is validated in the browser and never sent here.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',
    'auto_increment' => 100001,

    /*
    | Secondary indexes. Every read of this table is one session's bookings in
    | departure order, so the composite serves the lookup and the sort together
    | and its leading column covers any session_id-only filter — a lone
    | departure_time index would serve no query here, as nothing asks about
    | dates across sessions.
    |
    | `reference` is a lookup on the confirmation page and is also counted up to
    | twenty times while allocating a new one. It cannot be UNIQUE: the rows
    | written before checkout issued references all hold the empty string.
    */
    'indexes' => [
        ['name' => 'session_departure', 'columns' => ['session_id', 'departure_time']],
        // UNIQUE: unusedReference() picks a code, checks it is free and then
        // inserts, which is two steps and can be raced. Without the constraint
        // the loser wins silently and two bookings answer to the same code --
        // the one a traveller quotes down a phone, and the one findByReference()
        // resolves with LIMIT 1.
        ['name' => 'reference', 'columns' => ['reference'], 'unique' => true],
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
            'name' => 'session_id',
            'type' => 'varchar',
            'length' => 40,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'departure_time',
            'type' => 'datetime',
            'length' => null,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'flight_outbound',
            'type' => 'json',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'flight_return',
            'type' => 'json',
            'length' => null,
            'default' => ['NULL'],
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'reference',
            'type' => 'char',
            'length' => 6,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'status',
            'type' => 'varchar',
            'length' => 16,
            'default' => 'confirmed',
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'contact_email',
            'type' => 'varchar',
            'length' => 190,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'contact_phone',
            'type' => 'varchar',
            'length' => 32,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'passenger_first',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'passenger_last',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'passenger_dob',
            'type' => 'date',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'passenger_gender',
            'type' => 'char',
            'length' => 1,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'fare_brand',
            'type' => 'varchar',
            'length' => 32,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'fare_rules',
            'type' => 'json',
            'length' => null,
            'default' => ['NULL'],
            'nullable' => true,
            'auto_inc' => false,
            // What the ticket allows, kept because nothing else does. The legs
            // it was folded from are deleted once they have flown, so a booking
            // that does not hold its own rules cannot recover them.
            'comment' => 'Fare rules as sold, folded across the legs',
        ],
        [
            'name' => 'price_base',
            'type' => 'decimal',
            'length' => '10,2',
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'price_tax',
            'type' => 'decimal',
            'length' => '10,2',
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'card_brand',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'card_last4',
            'type' => 'char',
            'length' => 4,
            'charset' => 'ascii',
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
