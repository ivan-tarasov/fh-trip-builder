<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bookings DB table
    |--------------------------------------------------------------------------
    |
    | Lorem ipsum...
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    'columns' => [
        [
            'name' => 'id',
            'type' => 'int',
            'length' => 6,
            'default' => false,
            'nullable' => false,
            'auto_inc' => true,
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
            'name' => 'flight_a',
            'type' => 'json',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'flight_b',
            'type' => 'json',
            'length' => null,
            'default' => ['NULL'],
            'nullable' => true,
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
    ],

];
