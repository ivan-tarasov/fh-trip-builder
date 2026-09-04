<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aircraft cabins DB table
    |--------------------------------------------------------------------------
    |
    | What is fitted on board each aircraft type: one row per cabin, holding the
    | seat layout, the pitch, the width and how many seats are in it. A type
    | with no premium economy simply has no row for it, which is why this is a
    | child table rather than columns on `aircraft` -- the cabins fitted differ
    | per type, and a flat set of `pitch_first`-style columns would be mostly
    | null and would still not say which cabins exist.
    |
    | This is the source of truth for what a flight can sell. `flights.cabins`
    | is a bitmask copied from here so a search can filter on cabin without
    | joining, and `flights:cabins` is what copies it.
    |
    | The numbers are representative mainline configurations, not any one
    | airline's: the same type is fitted differently by every operator. What
    | matters is that they are internally consistent -- a narrowbody business
    | seat is a 37" recliner while a widebody one is a 60" flat bed, which is
    | the real reason a short-haul business fare is a smaller premium than a
    | long-haul one.
    |
    */

    'primary' => 'aircraft, cabin',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    'indexes' => [
        // Reading a type's whole cabin list is what the primary key already
        // serves; this one answers the other direction -- every type that
        // sells a given cabin.
        ['name' => 'cabin', 'columns' => ['cabin']],
    ],

    'columns' => [
        [
            'name' => 'aircraft',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'IATA type code, joins aircraft.code',
        ],
        [
            'name' => 'cabin',
            'type' => 'char',
            'length' => 1,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'IATA cabin code: Y W C F',
        ],
        [
            'name' => 'layout',
            'type' => 'varchar',
            'length' => 11,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Seats abreast, aisle separated: 3-4-3',
        ],
        [
            'name' => 'pitch_inches',
            'type' => 'tinyint',
            'length' => 3,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'width_inches',
            'type' => 'decimal',
            'length' => '3,1',
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'seats',
            'type' => 'smallint',
            'length' => 4,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Seats fitted in this cabin',
        ],
        [
            'name' => 'is_flat_bed',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Seat reclines to a flat bed',
        ],
    ],

];
