<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flights DB table
    |--------------------------------------------------------------------------
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    /*
    | Secondary indexes. The composite (departure_airport, arrival_airport,
    | departure_time) is the search filter: one-way and both legs of round-trip
    | range-scan it (route equality + a half-open date window), which keeps
    | `flights` the selective driving table. Its leading column also covers any
    | departure_airport-only lookup. The second index serves the flights:add
    | dedup GROUP BY (airline, number, date).
    */
    'indexes' => [
        ['name' => 'route_departure_time', 'columns' => ['departure_airport', 'arrival_airport', 'departure_time']],
        // A connecting leg's arrival is open (it can land anywhere), so the
        // route index above can't seek it by date; this one lets such legs seek
        // on (departure_airport, departure_time).
        ['name' => 'departure_airport_time', 'columns' => ['departure_airport', 'departure_time']],
        // The same shape on the UTC column, for "upcoming from here".
        //
        // `departure_airport_time` cannot serve it: its second column is the
        // local time, so a range on `departure_utc` is not seekable through it
        // and the optimizer falls to another index entirely. Measured on the
        // airline page: 761 rows examined became 3,313 and 1.06ms became
        // 3.95ms, until this existed (E20, #180).
        ['name' => 'departure_airport_utc', 'columns' => ['departure_airport', 'departure_utc']],
        ['name' => 'airline_number_departure_time', 'columns' => ['airline', 'number', 'departure_time']],
        // The maintenance commands work by distance band -- flights:realign
        // counts and deletes legs no aircraft can fly, and realign, reprice and
        // cabins all report samples per band. Every one of those was a full
        // scan of the whole table, and the delete ran one per 5,000-row batch.
        ['name' => 'distance', 'columns' => ['distance']],
        // flights:cleaning removes departures that have passed. The existing
        // indexes all lead with an airport or an airline, so a date-only sweep
        // could not seek on any of them.
        ['name' => 'departure_time', 'columns' => ['departure_time']],
        // Every index above leads with a departure, so "what lands here" could
        // seek on nothing: an airport's arrivals board was type=ALL over
        // 683,760 rows. This is the mirror of departure_airport_time and turns
        // the same 40 rows into a range scan -- 9.6ms to 1.3ms, for about 10MB
        // against the 94MB of indexes this table already carries.
        ['name' => 'arrival_airport_time', 'columns' => ['arrival_airport', 'arrival_time']],
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
            'name' => 'airline',
            'type' => 'char',
            'length' => 2,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'number',
            'type' => 'smallint',
            'length' => 4,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'aircraft',
            'type' => 'char',
            'length' => 3,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'fare_brand',
            'type' => 'char',
            'length' => 2,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'departure_airport',
            'type' => 'char',
            'length' => 3,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'departure_time',
            'type' => 'datetime',
            'length' => false,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            // The same moment as `departure_time`, in UTC.
            //
            // `departure_time` is local at the departure airport -- 08:00 at
            // YUL is 08:00 there, which is what a ticket says and what the page
            // prints. Ten queries compared it against `NOW()` to ask "has it
            // left yet", which is a wall clock against an instant: offsets in
            // the seeded data run -11 to +13, so the answer was out by up to
            // thirteen hours (E20, #180).
            //
            // A column and not a shift in the query. `NOW() + INTERVAL
            // (o.timezone * 60) MINUTE` is correct and is not sargable:
            // measured at 208,878 rows examined against 36, a scan where there
            // was a seek, on the table that drives search.
            //
            // Both columns stay. They answer different questions -- "flights on
            // the 20th" means the 20th *there*, and that is the hot path.
            //
            // Nullable, and that is not laziness. A flight whose departure
            // airport has no row has no offset to convert with, and null is the
            // true answer -- a guess would be a wrong instant that reads like a
            // right one. `>= NOW()` excludes it either way, which is correct:
            // nothing can say whether it has left.
            'name' => 'departure_utc',
            'type' => 'datetime',
            'length' => null,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'departure_time as a UTC instant, for whether it has left yet',
        ],
        [
            'name' => 'arrival_airport',
            'type' => 'char',
            'length' => 3,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'arrival_time',
            'type' => 'datetime',
            'length' => false,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'distance',
            'type' => 'int',
            'length' => 5,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'duration',
            'type' => 'int',
            'length' => 4,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'price_base',
            'type' => 'decimal',
            'length' => '6,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'price_tax',
            'type' => 'decimal',
            'length' => '6,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'cabins',
            'type' => 'tinyint',
            'length' => 3,
            'default' => ['1'],
            'nullable' => false,
            'auto_inc' => false,
            // Bitmask of the cabins this flight sells: 1 Economy, 2 Premium
            // Economy, 4 Business, 8 First. A set rather than a grade, because
            // real cabins are not nested -- a short-haul narrowbody sells
            // Economy and Business but no Premium Economy. Defaults to 1 so an
            // unpopulated row still sells the cabin every flight has.
            'comment' => 'Cabin bitmask: 1=Y 2=W 4=C 8=F',
        ],
        [
            'name' => 'rating',
            'type' => 'decimal',
            'length' => '3,2',
            'default' => '0.0',
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
