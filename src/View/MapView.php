<?php

declare(strict_types=1);

namespace TripBuilder\View;

use TripBuilder\Config;
use TripBuilder\Polyline;

/**
 * The map on a page, in the two forms a page needs it.
 *
 * config() is the live map: a blob of JSON that public/js/map.js turns into a
 * Mapbox GL map you can pan and zoom. image() is the same map as a flat
 * picture, which the templates put inside `<noscript>` -- a browser running
 * scripts never fetches it, so it costs no request, and it is what a crawler
 * and a scriptless client get instead of an empty canvas. Both are fed the same
 * markers and paths by the same template, which is the point of them living
 * together: two renderings of one map, not two maps.
 *
 * Five pages draw one and each used to build its own Yandex URL in its own
 * template -- the same host, the same size, four spellings of a marker. That is
 * what made changing provider a four-template edit, and why the provider now
 * stops here instead of reaching into the views.
 *
 * Mapbox, because the endpoint the site was using -- Yandex's `1.x`, with no
 * API key -- is not in Yandex's current documentation, which documents `v1`
 * with a mandatory key. Measured: `1.x` unkeyed answers 200 with a PNG, `v1`
 * unkeyed answers 400. So it worked, was undocumented, and could stop without
 * notice, at which point every map on 580-odd pages showed nothing and said
 * nothing.
 *
 * No token, nothing at all -- both return an empty string and the templates
 * draw no map. That is what makes a missing or revoked token a page without a
 * map rather than a page of broken images, and it is what let all of this be
 * built before a token existed.
 */
final class MapView
{
    private const string PICTURE_ENDPOINT = 'https://api.mapbox.com/styles/v1';

    /**
     * The same map as a flat picture, or '' when there is no token.
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
    public static function image(array $markers, array $paths = [], ?int $zoom = null): string
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

        $zoom = self::zoomFor($markers, $paths, $zoom);

        $position = $zoom === null
            ? 'auto'
            : sprintf('%s,%s,%d', self::round($markers[0]['lon']), self::round($markers[0]['lat']), $zoom);

        $url = sprintf(
            '%s/%s/static/%s/%s/%dx%d%s',
            self::PICTURE_ENDPOINT,
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
     * The live map, as the JSON public/js/map.js reads, or '' with no token.
     *
     * Handed over in a data attribute rather than written into a script tag,
     * because a place name can hold an apostrophe and a script tag is the one
     * context Twig's escaping cannot make safe on its own. The attribute is
     * escaped as an attribute and parsed as JSON, which is a path that has no
     * quoting to get wrong.
     *
     * A marker may carry a `label`, which the live map writes beside its pin.
     * Only the live map can: the picture endpoint takes a single character
     * there, which names nothing, so the picture goes unlabelled and the page
     * that wants names says them in text as well.
     *
     * @param list<array{lat: float|string, lon: float|string, colour?: string, label?: string}> $markers
     * @param list<list<array{lat: float|string, lon: float|string}>> $paths
     */
    public static function config(array $markers, array $paths = [], ?int $zoom = null): string
    {
        $token = self::token();

        if ($token === '' || ($markers === [] && $paths === [])) {
            return '';
        }

        return (string) json_encode([
            'token' => $token,
            // Resolved here rather than in the browser, so the live map and the
            // flat picture open on the same view.
            // GL JS wants the mapbox:// form of the same style the picture
            // endpoint takes bare.
            'style' => 'mapbox://styles/' . self::setting('style', 'mapbox/streets-v12'),
            'zoom' => self::zoomFor($markers, $paths, $zoom),
            'padding' => (int) self::setting('padding', 72),
            'markers' => array_map(
                static fn(array $marker): array => [
                    // GeoJSON order, which is the opposite of the order the
                    // encoded polyline takes and of how these read in English.
                    // Every coordinate that crosses into JavaScript from here
                    // is [lon, lat].
                    'at' => [(float) $marker['lon'], (float) $marker['lat']],
                    'colour' => '#' . ltrim($marker['colour'] ?? '2A5CAA', '#'),
                    // Null where a page has nothing to call a pin. The flat
                    // picture ignores it either way: that endpoint takes one
                    // character on a pin, so it carries none.
                    'label' => $marker['label'] ?? null,
                ],
                $markers,
            ),
            'paths' => array_map(
                static fn(array $path): array => array_map(
                    static fn(array $point): array => [(float) $point['lon'], (float) $point['lat']],
                    $path,
                ),
                $paths,
            ),
            'path' => [
                'colour' => '#' . ltrim((string) self::setting('path_colour', '0F766E'), '#'),
                'width' => (int) self::setting('path_width', 5),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * How far out the map opens, or null to let it frame itself.
     *
     * A caller's own zoom wins. Otherwise a map with one place and no path gets
     * the configured single-pin zoom, because there is nothing to frame: a box
     * drawn round one point has no size, and both the picture endpoint and the
     * live map need telling what "around here" means. Anything with two points
     * or a path frames itself, which is better than any fixed number could be.
     *
     * @param list<array{lat: float|string, lon: float|string, colour?: string, label?: string}> $markers
     * @param list<list<array{lat: float|string, lon: float|string}>> $paths
     */
    private static function zoomFor(array $markers, array $paths, ?int $zoom): ?int
    {
        if ($zoom !== null) {
            return $zoom;
        }

        return count($markers) === 1 && $paths === []
            ? (int) self::setting('zoom_single', 9)
            : null;
    }

    /**
     * One marker: "pin-s+0EB600(lon,lat)".
     *
     * No label, because this endpoint cannot carry one: a marker here takes a
     * single character, which names neither of two places. The live map labels
     * its pins properly -- see config().
     *
     * @param array{lat: float|string, lon: float|string, colour?: string, label?: string} $marker
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
