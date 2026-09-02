<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fare brands DB table
    |--------------------------------------------------------------------------
    |
    | The rules a ticket is sold under. Airlines price a handful of branded
    | fares — a basic one that only carries a personal item, a flexible one that
    | refunds — and the restrictions follow the brand rather than the flight,
    | so a few rows here stand in for rules on every generated leg.
    |
    | `weight` is how often the generator picks a brand, out of the total across
    | all rows: the cheap restrictive fares are most of what is on sale.
    |
    | The permission columns are ordered loosest-to-strictest as integers, so an
    | itinerary that connects can take the strictest value across its legs. A
    | fare is only as generous as the tightest leg in it.
    |
    */

    'primary' => 'code',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    'columns' => [
        [
            'name' => 'code',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'title',
            'type' => 'varchar',
            'length' => 32,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'weight',
            'type' => 'smallint',
            'length' => 4,
            'default' => [1],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        // 0 personal item only, 1 carry-on included
        [
            'name' => 'carry_on',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        // 0 not allowed, 1 for a fee, 2 included
        [
            'name' => 'checked_bag',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        // 0 not allowed, 1 for a fee, 2 free
        [
            'name' => 'changes',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        // 0 not allowed, 1 for a fee, 2 free
        [
            'name' => 'cancellation',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        // 0 assigned at check-in, 1 for a fee, 2 included
        [
            'name' => 'seat_selection',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'refundable',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
