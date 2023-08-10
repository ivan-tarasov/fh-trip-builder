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
        '/my'          => 'My@index',
        '/my/bookings' => 'My@bookings',

        /*
        |--------------------------------------------------------------------------
        | API controller with endpoints
        |--------------------------------------------------------------------------
        */
        '/api'          => 'Api@index',
        '/api/server'   => 'Api@server',
        '/api/airports' => 'Api@airports',
        '/api/airlines' => 'Api@airlines',
    ];

    const ROUTS_CONTROLLERS_PATH = 'TripBuilder\Controllers';

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
