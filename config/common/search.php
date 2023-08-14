<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Search forms
    |
    */

    'form' => [
        'input' => [
            'triptype'     => 'triptype',
            'depart_place' => 'from',
            'arrive_place' => 'to',
            'depart_date'  => 'departDate',
            'return_date'  => 'returnDate',
        ],
    ],

    'triptype' => [
        'roundtrip' => 'rt',
        'oneway'    => 'ow',
    ],

];
