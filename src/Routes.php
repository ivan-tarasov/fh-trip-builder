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
        // Not a page: see EXCLUDE_HEADER_FOOTER, which also keeps it out of the
        // sitemap and the index.
        '/health' => 'Health@index',
        // Every city we sell to, in one place. Sixty of the 231 city pages had
        // no inbound link before this existed -- see CityRepository::all().
        '/cities' => 'City@index',
        // And every country. Same page, different rows: both are a few hundred
        // names somebody arrives already knowing.
        '/countries' => 'Country@index',
        // The five help topics the footer has always linked to. A hub rather
        // than five loose pages, so the family has somewhere to be listed and
        // each article has its siblings to point at.
        '/help' => 'Help@index',
        // Airside: travel writing, as against help, which is what a reader
        // needs in order to finish a booking here. The name is the industry's
        // word for everything past the security line, so it states that
        // boundary rather than describing a format.
        '/airside' => 'Airside@index',

        // Spelled out rather than matched by a pattern: there are three, they
        // never grow from data, and a route listed here is a route the sitemap
        // carries and LayoutData::indexable() lets a crawler keep.
        '/privacy' => 'Legal@show',
        '/terms' => 'Legal@show',
        '/cookies' => 'Legal@show',

        // Not a page. It is here rather than as a file on disk because its
        // contents are the 231 city pages, which are rows in a table.
        '/sitemap.xml' => 'Sitemap@index',
        '/robots.txt' => 'Sitemap@robots',

        /*
        |--------------------------------------------------------------------------
        | Personal user pages
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | The admin panel
        |--------------------------------------------------------------------------
        |
        | One operator, one password hash in `.env`, one session flag. Listed
        | under PRIVATE_PREFIXES below, which keeps every one of these out of
        | the sitemap and out of an index (A3.2, #100).
        |
        */

        '/admin' => 'Admin@index',
        // The content list moved off `/admin` when the dashboard took it
        // (A3.6, #231). One address per section, so the rail can name them.
        '/admin/content' => 'Admin@content',
        '/admin/bookings' => 'Admin@bookings',
        '/admin/bookings/export' => 'Admin@exportBookings',
        '/admin/subscribers' => 'Admin@subscribers',
        '/admin/subscribers/export' => 'Admin@exportSubscribers',
        // A drill-down from Overview's "Most searched", not a rail
        // destination -- plural, since `/admin/search` singular below is
        // already the command palette's own JSON endpoint (G16, #369).
        '/admin/searches' => 'Admin@searches',
        // The live schedule (G19, #377). History is its own address since it
        // is reached by command (`?command=`), not by id.
        '/admin/schedule' => 'Admin@schedule',
        '/admin/schedule/history' => 'Admin@scheduleHistory',
        // Settings is a rail parent with two more children below it, each
        // its own address the same way -- Search rules kept `/admin/settings`
        // itself, the same idiom Orchid's own expandable parent link uses
        // (its href is its first child) (G2.6, #299).
        '/admin/settings' => 'Admin@settings',
        '/admin/settings/site-identity' => 'Admin@settingsSiteIdentity',
        '/admin/settings/map' => 'Admin@settingsMap',
        '/admin/settings/diagnostics' => 'Admin@settingsDiagnostics',
        // One export for all three groups, since the history it reads is
        // already shared across them (G3.5, #308).
        '/admin/settings/export' => 'Admin@exportSettingsHistory',
        // The command palette's own endpoint (G4.1, #312) -- JSON, listed in
        // EXCLUDE_HEADER_FOOTER_ROUTES below the same way `/admin/preview` is.
        '/admin/search' => 'Admin@search',
        // Reached from the topbar user menu, not the rail (G9, #350).
        '/admin/profile' => 'Admin@profile',
        '/admin/login' => 'Admin@login',
        '/admin/logout' => 'Admin@logout',
        // Markdown in, HTML out, for the editor's preview pane. Listed in
        // EXCLUDE_HEADER_FOOTER_ROUTES below: it answers a fragment, and a
        // header and footer wrapped around one would be spliced into the page.
        '/admin/preview' => 'Admin@preview',

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
        '/ajax/article-vote' => 'Ajax@articleVote',
        '/ajax/post-vote' => 'Ajax@postVote',
        '/ajax/cancel-booking' => 'Ajax@cancelBooking',
        '/ajax/day-prices' => 'Ajax@dayPrices',
        '/ajax/subscribe' => 'Ajax@subscribe',
        '/ajax/watch-route' => 'Ajax@watchRoute',

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
        // "montreal-to-toronto" -- the only page here that is a pair rather
        // than a record, and the only address with no code in it at all. Loose
        // like the other four, and for the same reason: what turns a slug away
        // is whether it names two cities, which is a question for
        // RouteAddress::read() and not for a pattern.
        '#^/route/[A-Za-z0-9-]+$#' => 'Route@show',
        // "baggage". No code on the end, like the route above it -- and unlike
        // it, no record behind the slug either. The slug is the whole identity,
        // so a pattern is all the router can do; which five words are real is
        // a row in `articles`, and HelpController's question.
        '#^/help/[A-Za-z-]+$#' => 'Help@show',
        // Digits allowed, unlike help: an article is a subject and a post is a
        // piece of writing, which can be "three-ways-to-pick-a-seat". Kept in
        // step with AirsideController::slug() and with the check
        // `airside:import` makes on a file name.
        '#^/airside/[A-Za-z0-9-]+$#' => 'Airside@show',
        // A tag's own page. Lower case only: a tag slug is derived from its
        // name by `airside:import`, which lower-cases it, so there is no
        // capitalised spelling to redirect from -- unlike a post, whose slug a
        // reader may well have typed.
        '#^/airside/tag/[a-z0-9-]+$#' => 'Airside@tag',

        // The editors. Without a slug they create; with one they edit, and the
        // same address takes the POST that saves. A slug is the same shape the
        // help pages use, because it is the same slug (A3.3, #101).
        '#^/admin/article(?:/[a-z0-9-]+)?$#' => 'Admin@article',
        '#^/admin/category(?:/[a-z0-9-]+)?$#' => 'Admin@category',
        // Same idiom, by id rather than by slug -- a scheduled job has no
        // name of its own to be one.
        '#^/admin/schedule/job(?:/\d+)?$#' => 'Admin@scheduleJob',
        // By id and not by reference. A reference is the code a traveller
        // quotes and it is unique, but it is empty on a row written before
        // checkout finished -- and those are exactly the bookings an operator
        // most wants to look at (A3.8, #233).
        '#^/admin/bookings/\d+$#' => 'Admin@booking',
    ];

    public const array EXCLUDE_HEADER_FOOTER = [
        'Api',
        'Ajax',
        // Answers JSON to a monitor. Listing it here does three things at once:
        // no layout, no sitemap entry, and no robots invitation -- because
        // isPublic() reads this list too.
        'Health',
    ];

    /**
     * Paths whose pages are transient, personal, or both.
     *
     * A search result is a snapshot of prices that will be wrong tomorrow, and
     * there are more possible search URLs than there are flights. A checkout is
     * a step in a transaction. /my is one browser's own bookings. /admin is not
     * a page a stranger has any business arriving at, and listing it here is
     * what keeps it out of the sitemap -- not a security measure, which is what
     * the password is for, but there is no reason to publish the address.
     *
     * Two things read this and they must agree: the robots meta tag that keeps
     * these out of an index, and the sitemap that would otherwise invite a
     * crawler in. It lives here because this class is what already knows what a
     * path is.
     */
    public const array PRIVATE_PREFIXES = ['/search', '/checkout', '/my', '/admin'];

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
        '#^/admin/preview$#',
        '#^/admin/search$#',
        '#^/admin/bookings/export$#',
        '#^/admin/subscribers/export$#',
        '#^/admin/settings/export$#',
        // A GET here still renders the whole page through `admin/booking.html.twig`,
        // which already carries its own `{% extends %}` -- `wrapped()`'s own
        // `<!DOCTYPE` check was already a no-op for that. What this is actually for
        // is the other half of the same route: `AdminController::respondBooking()`
        // answering a POST with JSON for `fetch()` instead of a redirect, which
        // has no doctype to be caught by that check and was landing inside the
        // public layout as escaped text (G8.3, #338).
        '#^/admin/bookings/\d+$#',
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
