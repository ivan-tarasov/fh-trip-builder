<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * A run of coordinates in Google's encoded polyline format.
 *
 * What a Mapbox static map wants a path drawn from. The alternative it accepts
 * is a `geojson()` overlay, which needs no encoding and is several times
 * longer: the route map's arc is 17 points, which is about 1,100 characters of
 * GeoJSON before URL-escaping and about 180 encoded. These URLs are printed
 * into every route page, so the shorter one wins.
 *
 * The format stores each point as its difference from the one before, at five
 * decimal places, in six-bit groups offset into printable ASCII. That is why
 * the output looks like line noise and why the encoder is worth a test rather
 * than an eyeball -- an off-by-one in the bit shifting produces a string that
 * is still perfectly valid and draws a line across the wrong continent.
 */
final class Polyline
{
    /** Decimal places the format carries: about a metre. */
    private const int PRECISION = 100000;

    /**
     * Every group but the last carries this bit, to say another follows.
     */
    private const int CONTINUES = 0x20;

    /** Groups are offset into printable ASCII, starting at "?". */
    private const int ASCII_OFFSET = 63;

    /**
     * @param list<array{lat: float|string, lon: float|string}> $points
     */
    public static function encode(array $points): string
    {
        $encoded = '';
        $lastLat = 0;
        $lastLon = 0;

        foreach ($points as $point) {
            // Cast, because these arrive from PDO as strings on the pages
            // that read them out of the database.
            $lat = (int) round((float) $point['lat'] * self::PRECISION);
            $lon = (int) round((float) $point['lon'] * self::PRECISION);

            // Latitude first, which is the opposite order to the rest of this
            // codebase -- a map URL takes lon,lat and this format takes
            // lat,lon. Reversing them is the one mistake here that still
            // produces a valid string.
            $encoded .= self::group($lat - $lastLat) . self::group($lon - $lastLon);

            $lastLat = $lat;
            $lastLon = $lon;
        }

        return $encoded;
    }

    /**
     * One signed difference, as printable characters.
     */
    private static function group(int $difference): string
    {
        // The sign is moved into the lowest bit -- shift left, then invert if
        // it was negative -- so that the value can be read five bits at a time
        // from the bottom without the sign getting in the way.
        $value = $difference < 0 ? ~($difference << 1) : $difference << 1;

        $out = '';

        while ($value >= self::CONTINUES) {
            $out .= chr((self::CONTINUES | ($value & 0x1f)) + self::ASCII_OFFSET);
            $value >>= 5;
        }

        return $out . chr($value + self::ASCII_OFFSET);
    }
}
