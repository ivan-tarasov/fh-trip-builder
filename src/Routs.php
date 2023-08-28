<?php

namespace TripBuilder;

class Routs
{
    const ENABLED_ROUTS = [

        /*
        |--------------------------------------------------------------------------
        | Index controller with root pages
        |--------------------------------------------------------------------------
        */

        '/'            => 'Home@index',
        '/airlines'    => 'Airlines@index',
        '/airports'    => 'Airports@index',
        // '/about'       => 'About@index',

        /*
        |--------------------------------------------------------------------------
        | Personal user pages
        |--------------------------------------------------------------------------
        */

        '/my'          => 'My@index',
        '/my/bookings' => 'My@bookings',

        /*
        |--------------------------------------------------------------------------
        | Search controller
        |--------------------------------------------------------------------------
        */

        '/search' => 'Search@index',

        /*
        |--------------------------------------------------------------------------
        | API controller with endpoints
        |--------------------------------------------------------------------------
        */

        '/api'                   => 'Api@index',
        '/api/server'            => 'Api@server',
        '/api/airports'          => 'Api@airports',
        '/api/airports/autofill' => 'Api@airportsAutofill',
        '/api/airlines'          => 'Api@airlines',
        '/api/flights'           => 'Api@flights',
        '/api/flights/one'       => 'Api@flightsOne',

        /*
        |--------------------------------------------------------------------------
        | Search controller
        |--------------------------------------------------------------------------
        */

        '/ajax/add-trip' => 'Ajax@addTrip',

        /*
        |--------------------------------------------------------------------------
        | Debug controller
        |--------------------------------------------------------------------------
        */

        '/__debug-it' => 'Debug@index',

    ];

    const ROUTS_CONTROLLERS_PATH = 'TripBuilder\Controllers';

    const EXCLUDE_HEADER_FOOTER = [
        'Api',
        'Ajax',
        'Debug',
    ];

    private static string $currentPage;

    /**
     * @param $page
     * @return void
     */
    public static function setCurrentPage($page): void
    {
        self::$currentPage = $page;
    }

    /**
     * @return string
     */
    public static function getCurrentPage(): string
    {
        return self::$currentPage;
    }

}
