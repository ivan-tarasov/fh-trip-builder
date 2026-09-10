<?php

declare(strict_types=1);

namespace TripBuilder;

use TripBuilder\Http\Request;

/**
 * Roughly where a request came from, when the edge in front of us says so.
 *
 * Cloudflare's "Add visitor location headers" managed transform puts the
 * visitor's city, region, timezone and coordinates on every request. It is a
 * toggle rather than a service to sign up for, and the coordinates arrive with
 * the request -- so there is nothing to cache, no key to hold, and no call on
 * the render path.
 *
 * Only the two coordinates are read. `cf-ipcity` arrives as well and is
 * tempting -- it said `Montréal` on the request this was built against -- but
 * matching a city by its name means matching against a spelling somebody else
 * chose, in whatever language and accenting they chose it, and this app has
 * `ST_Distance_Sphere` and 231 city centroids. A number is not ambiguous.
 *
 * Absent everywhere the edge is not: local development, a direct hit on the
 * origin, `php -S`. That is the ordinary case rather than an error, so it
 * answers null and the caller leaves the field alone.
 *
 * **This must not be rendered into a cacheable page.** Nothing caches HTML
 * today -- there are no Cloudflare Cache Rules on this zone -- but a rule
 * added later that covered `/` would serve the first visitor's city to
 * everybody behind it, and nothing on the page would say so. If HTML is ever
 * cached, the homepage has to be excluded or made to vary on these headers.
 */
final readonly class VisitorLocation
{
    /**
     * Latitude and longitude, or null where the edge said nothing usable.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public static function coordinates(Request $request): ?array
    {
        $latitude = self::degrees($request->header('cf-iplatitude'), 90.0);
        $longitude = self::degrees($request->header('cf-iplongitude'), 180.0);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    /**
     * A header as a coordinate, or null when it is not one.
     *
     * `is_numeric` and not a cast: `(float) 'unknown'` is 0.0, which is a real
     * place in the Gulf of Guinea rather than an absence. Bounded because a
     * header is input like any other -- it arrives on a request, and a proxy
     * misconfigured to pass one through from the client would be the way it
     * arrived wrong.
     */
    private static function degrees(?string $value, float $limit): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $degrees = (float) $value;

        return abs($degrees) <= $limit ? $degrees : null;
    }
}
