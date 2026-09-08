<?php

declare(strict_types=1);

namespace TripBuilder\View;

use TripBuilder\Config;
use TripBuilder\Polyline;

/**
 * The one place that knows what a map picture's address is.
 *
 * Five pages draw a map and each used to build its own URL in its own template
 * -- the same host, the same size and four different spellings of a marker.
 * That is why moving off Yandex was a four-template edit rather than a one-line
 * one, and it is the actual reason this class exists: the provider is a detail
 * and it had leaked into the views.
 *
 * Mapbox Static Images, because the endpoint the site was using -- Yandex's
 * `1.x`, with no API key -- is not in Yandex's current documentation, which
 * documents `v1` with a mandatory key. Measured: `1.x` unkeyed answers 200 with
 * a PNG, `v1` unkeyed answers 400. So it works, is undocumented, and can stop
 * without notice, at which point every map on 580-odd pages shows nothing and
 * says nothing.
 *
 * No token, no URL. An empty string is returned and the templates draw no map,
 * which is what makes a missing or revoked token a page without a picture
 * rather than a page of broken images. It is also what lets all of this be
 * built and tested before a token exists.
 */
final class StaticMap
{
    private const string ENDPOINT = 'https://api.mapbox.com/styles/v1';

    /**
     * A map of the given markers and paths, or '' when there is no token.
     *
     * Framing is automatic unless a zoom is given: Mapbox's `auto` fits the
     * overlays, which is what four of the five maps want -- a city with three
     * airports and a country with thirty-two frame themselves. The airport page
     * passes a zoom, because one pin frames to nothing and what that page wants
     * to show is which part of its city the airport sits in.
     *
     * @param list<array{lat: float|string, lon: float|string, colour?: string}> $markers
     * @param list<list<array{lat: float|string, lon: float|string}>> $paths drawn under the markers
     */
    public static function url(array $markers, array $paths = [], ?int $zoom = null): string
    {
        $token = self::token();

        if ($token === '') {
            return '';
        }

        if ($markers === [] && $paths === []) {
            return '';
        }

        // Paths first: overlays are drawn in the order they are given, and a
        // line over a pin hides the thing the line connects.
        $overlays = [
            ...array_map(static fn(array $path): string => self::path($path), $paths),
            ...array_map(static fn(array $marker): string => self::pin($marker), $markers),
        ];

        $position = $zoom === null
            ? 'auto'
            : sprintf('%s,%s,%d', self::round($markers[0]['lon']), self::round($markers[0]['lat']), $zoom);

        $url = sprintf(
            '%s/%s/static/%s/%s/%dx%d%s',
            self::ENDPOINT,
            self::setting('style', 'mapbox/streets-v12'),
            implode(',', array_filter($overlays)),
            $position,
            (int) self::setting('width', 650),
            (int) self::setting('height', 300),
            self::setting('retina', true) ? '@2x' : '',
        );

        $query = ['access_token' => $token];

        // Only meaningful when Mapbox is choosing the frame; with an explicit
        // centre and zoom there is no frame to pad.
        if ($zoom === null) {
            $query['padding'] = (string) (int) self::setting('padding', 40);
        }

        return $url . '?' . http_build_query($query);
    }

    /**
     * One marker: "pin-s+0EB600(lon,lat)".
     *
     * No label. The size in config is the small pin, and small pins carry
     * none -- see the note there.
     *
     * @param array{lat: float|string, lon: float|string, colour?: string} $marker
     */
    private static function pin(array $marker): string
    {
        $colour = ltrim($marker['colour'] ?? '', '#');

        return sprintf(
            '%s%s(%s,%s)',
            self::setting('pin', 'pin-s'),
            $colour === '' ? '' : '+' . $colour,
            self::round($marker['lon']),
            self::round($marker['lat']),
        );
    }

    /**
     * One path: "path-5+0F766E(<encoded>)".
     *
     * The polyline is encoded and then URL-encoded, which Mapbox requires and
     * which is not optional in practice: the format emits backslashes and
     * backticks, and a bare one ends the overlay early.
     *
     * @param list<array{lat: float|string, lon: float|string}> $path
     */
    private static function path(array $path): string
    {
        if (count($path) < 2) {
            return '';
        }

        return sprintf(
            'path-%d+%s(%s)',
            (int) self::setting('path_width', 5),
            ltrim((string) self::setting('path_colour', '0F766E'), '#'),
            rawurlencode(Polyline::encode($path)),
        );
    }

    /**
     * The token, from the environment and nowhere else.
     *
     * getenv() first and $_ENV second, the same order Connection::fromEnv()
     * reads its credentials in and for the same reason: in CI these arrive as
     * real environment variables, which phpdotenv's immutable loader does not
     * copy into $_ENV.
     */
    private static function token(): string
    {
        $token = getenv('MAPBOX_TOKEN');

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return (string) ($_ENV['MAPBOX_TOKEN'] ?? '');
    }

    private static function setting(string $key, mixed $default): mixed
    {
        return Config::get('maps.static.' . $key, $default);
    }

    /**
     * Coordinates at the precision a map can draw, so a URL printed into every
     * page does not carry seventeen significant figures of a float.
     *
     * Takes a string as readily as a float, because that is what the callers
     * actually have: PDO hands back a DECIMAL column as a string, so every
     * latitude and longitude on these pages arrives as "51.47060000". Declared
     * `float` only, this threw on every one of the five pages while the tests
     * -- written with float literals -- passed.
     */
    private static function round(int|float|string $degrees): string
    {
        return (string) round((float) $degrees, 4);
    }
}
