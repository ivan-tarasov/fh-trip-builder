<?php

namespace TripBuilder\Config;

class Routs
{
    const ENABLED_ROUTS = [
        /*
        |--------------------------------------------------------------------------
        | Index controller
        |--------------------------------------------------------------------------
        */
        '/' => 'Home@index',

        /*
        |--------------------------------------------------------------------------
        | Index controller
        |--------------------------------------------------------------------------
        */
        '/api'          => 'Api@index',
        '/api/airports' => 'Api@airports',
        '/api/airlines' => 'Api@airlines',
    ];

    const ROUTS_CONTROLLERS_PATH = 'TripBuilder\Controllers';

}