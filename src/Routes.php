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
        '/about' => 'About@index',

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
        | Checkout controller
        |--------------------------------------------------------------------------
        */

        '/checkout' => 'Checkout@index',
        '/checkout/confirmation' => 'Checkout@confirmation',

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
        '/ajax/cancel-booking' => 'Ajax@cancelBooking',

    ];

    public const string ROUTES_CONTROLLERS_PATH = 'TripBuilder\Controllers';

    /**
     * Routes that name a record in the path.
     *
     * The table above is an exact-match map, which is all this app needed while
     * every page was a fixed address. A booking is not: it is one of many, and
     * /my/bookings/100001 is the address a person expects to be able to keep.
     *
     * Kept deliberately small -- two patterns, both anchored, both matching
     * digits only -- rather than growing a general router for one resource.
     */
    public const array DYNAMIC_ROUTES = [
        '#^/my/bookings/(\d+)$#' => 'My@booking',
        '#^/my/bookings/(\d+)/calendar$#' => 'My@calendar',
        // A whole search in one segment -- see SearchUrl. The plain /search
        // route below still answers, because that is where the query-string
        // form lands before being redirected here.
        '#^/search/[A-Z0-9]{3}\d{6}[A-Z0-9]{3}(?:\d{6})?[YWCF]\d{1,3}$#' => 'Search@index',
    ];

    public const array EXCLUDE_HEADER_FOOTER = [
        'Api',
        'Ajax',
    ];

    /**
     * Routes that emit their own payload from a controller that otherwise
     * renders pages.
     *
     * The list above is per controller, which works while "emits a document"
     * and "emits something else" split cleanly by controller. A file download
     * does not: it is one action on a page controller, and wrapping its bytes
     * in a header and footer corrupts the file.
     */
    public const array EXCLUDE_HEADER_FOOTER_ROUTES = [
        '#^/my/bookings/\d+/calendar$#',
    ];

    /**
     * The 'Controller@action' for a path, or null when nothing serves it.
     */
    public static function resolve(string $url): ?string
    {
        if (isset(self::ENABLED_ROUTES[$url])) {
            return self::ENABLED_ROUTES[$url];
        }

        foreach (self::DYNAMIC_ROUTES as $pattern => $route) {
            if (preg_match($pattern, $url) === 1) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Whether this path writes its own bytes and must not be wrapped in a
     * header and footer.
     */
    public static function emitsOwnPayload(string $url): bool
    {
        foreach (self::EXCLUDE_HEADER_FOOTER_ROUTES as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

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
