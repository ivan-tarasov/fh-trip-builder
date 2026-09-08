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
        // Every city we sell to, in one place. Sixty of the 231 city pages had
        // no inbound link before this existed -- see CityRepository::all().
        '/cities' => 'City@index',
        // And every country. Same page, different rows: both are a few hundred
        // names somebody arrives already knowing.
        '/countries' => 'Country@index',

        // Not a page. It is here rather than as a file on disk because its
        // contents are the 231 city pages, which are rows in a table.
        '/sitemap.xml' => 'Sitemap@index',
        '/robots.txt' => 'Sitemap@robots',

        /*
        |--------------------------------------------------------------------------
        | Personal user pages
        |--------------------------------------------------------------------------
        */

        '/my/bookings' => 'My@bookings',
        // Cancelled bookings keep their own address rather than a tab the URL
        // cannot describe: a booking somebody is arguing with an airline about
        // is one they want to be able to link to.
        '/my/bookings/past' => 'My@past',
        '/my/bookings/cancelled' => 'My@cancelled',
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
        '/ajax/day-prices' => 'Ajax@dayPrices',
        '/ajax/subscribe' => 'Ajax@subscribe',

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
        '#^/search/[A-Z0-9]{3}\d{6}(?:x[2-9])?[A-Z0-9]{3}(?:\d{6})?(?:x[2-9])?[YWCF]\d{1,3}$#' => 'Search@index',
        // "montreal-ymq". The name is for the reader and the code is what the
        // lookup uses, so the pattern is deliberately loose about the name half
        // -- a stale or mistyped one still finds the city and is redirected to
        // the spelling this app would have written.
        '#^/city/[A-Za-z0-9-]+$#' => 'City@show',
        // "canada-ca", spelled the same way and loose for the same reason. The
        // code on the end is two characters rather than three, because that is
        // what an ISO country code is; the controllers, not the router, are
        // what tell the two apart.
        '#^/country/[A-Za-z0-9-]+$#' => 'Country@show',
        // "heathrow-lhr". Three characters again, like a city -- an IATA code
        // is an IATA code whether it names an airport or the city around it.
        '#^/airport/[A-Za-z0-9-]+$#' => 'Airport@show',
        // "air-canada-ac". Two characters, like a country: an airline's IATA
        // code is two, and the controller is what knows which two.
        '#^/airline/[A-Za-z0-9-]+$#' => 'Airline@show',
        // "montreal-ymq/toronto-yto" -- a place address on each side. The only
        // page here that is a pair rather than a record, and the only one with
        // two segments: each half is read by the same rule a city's is, which
        // one joined segment could not be, because a name may hold hyphens and
        // nothing would say where the first one ended.
        '#^/route/[A-Za-z0-9-]+/[A-Za-z0-9-]+$#' => 'Route@show',
    ];

    public const array EXCLUDE_HEADER_FOOTER = [
        'Api',
        'Ajax',
    ];

    /**
     * Paths whose pages are transient, personal, or both.
     *
     * A search result is a snapshot of prices that will be wrong tomorrow, and
     * there are more possible search URLs than there are flights. A checkout is
     * a step in a transaction. /my is one browser's own bookings.
     *
     * Two things read this and they must agree: the robots meta tag that keeps
     * these out of an index, and the sitemap that would otherwise invite a
     * crawler in. It lives here because this class is what already knows what a
     * path is.
     */
    public const array PRIVATE_PREFIXES = ['/search', '/checkout', '/my'];

    /**
     * Whether a path is a page worth a stranger arriving at.
     */
    public static function isPublic(string $path): bool
    {
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        $route = self::resolve($path);

        if ($route === null) {
            return false;
        }

        // An endpoint answers with JSON, not with a page.
        return !in_array(explode('@', $route)[0], self::EXCLUDE_HEADER_FOOTER, true);
    }

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
        '#^/sitemap\.xml$#',
        '#^/robots\.txt$#',
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

    // Defaulted: this is read on every page render, and a typed static with no
    // default is a fatal for any render that does not come through index.php.
    private static string $currentPage = '/';

    public static function setCurrentPage(string $page): void
    {
        self::$currentPage = $page;
    }

    public static function getCurrentPage(): string
    {
        return self::$currentPage;
    }

}
