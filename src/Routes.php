<?php

declare(strict_types=1);

namespace TripBuilder;

class Routes
{
    public const array ENABLED_ROUTES = [

        /*
        |--------------------------------------------------------------------------
        | Index controller with root pages
        |--------------------------------------------------------------------------
        */

        '/' => 'Home@index',
        '/airlines' => 'Airlines@index',
        '/airports' => 'Airports@index',
        // '/about' => 'About@index',

        /*
        |--------------------------------------------------------------------------
        | Personal user pages
        |--------------------------------------------------------------------------
        */

        '/my/bookings' => 'My@bookings',
        '/my/saved' => 'My@saved',

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

        '/api/airports' => 'Api@airports',
        '/api/airports/autofill' => 'Api@airportsAutofill',
        '/api/airlines' => 'Api@airlines',
        '/api/flights' => 'Api@flights',
        '/api/flights/one' => 'Api@flightsOne',

        /*
        |--------------------------------------------------------------------------
        | Search controller
        |--------------------------------------------------------------------------
        */

        '/ajax/add-trip' => 'Ajax@addTrip',
        '/ajax/delete-booking' => 'Ajax@deleteBooking',

    ];

    public const string ROUTES_CONTROLLERS_PATH = 'TripBuilder\Controllers';

    public const array EXCLUDE_HEADER_FOOTER = [
        'Api',
        'Ajax',
    ];

    private static string $currentPage;

    public static function setCurrentPage(string $page): void
    {
        self::$currentPage = $page;
    }

    public static function getCurrentPage(): string
    {
        return self::$currentPage;
    }

}
