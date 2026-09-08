<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The line a flight actually takes between two places.
 *
 * A route drawn straight between its two ends is wrong on any map the web
 * draws, and wrong in a way people notice: New York to London leaves the
 * eastern seaboard heading north-east over Newfoundland, and a straight line on
 * a Mercator projection sends it across the middle of the Atlantic instead. The
 * shortest path over a sphere is a great circle, and on Mercator that is a
 * curve -- so the curve is the honest drawing and the straight line is the
 * stylisation.
 *
 * Points along that circle, for a map to join up. Sixteen segments is enough
 * that the joins do not show at the width a static map is drawn: the largest
 * gap between the true circle and the chords, on the longest route in this data
 * -- 15,174km -- is under a pixel at 650px wide.
 */
final class GreatCircle
{
    /**
     * Straight-line interpolation is used below this angular distance.
     *
     * Under about a degree the two are the same line to well inside a pixel,
     * and the interpolation below divides by sin(d) -- which at d = 0, a route
     * whose ends round to the same point, is a division by zero. Montreal to
     * Toronto is 4.5 degrees, so nothing real reaches this; two airports in one
     * city would.
     */
    private const float MIN_ARC_DEGREES = 0.5;

    /**
     * The arc as one or more runs of points, each staying inside the map.
     *
     * Two runs where the route crosses the antimeridian and one everywhere
     * else. Tokyo to Los Angeles is the case: its honest longitudes run past
     * +180, which the map service rejects outright -- the request comes back
     * 400 and the page loses its map, not just its line. Wrapped back into
     * range instead, without splitting, the line is drawn the whole way round
     * the world through Africa. So it is cut at the edge, leaves at +180 and
     * returns at -180, and the two runs are drawn as two lines.
     *
     * The latitude of the cut is interpolated between the two points either
     * side of it. Straight-line interpolation over a step of at most a few
     * degrees, which is smaller than the error already accepted by drawing the
     * arc in chords at all.
     *
     * @param int $segments how many straight pieces to draw the arc in
     * @return list<list<array{lat: float, lon: float}>>
     */
    public static function segments(
        float $fromLat,
        float $fromLon,
        float $toLat,
        float $toLon,
        int $segments = 16,
    ): array {
        return self::split(self::path($fromLat, $fromLon, $toLat, $toLon, $segments));
    }

    /**
     * Points along the great circle from one place to another, ends included.
     *
     * Longitudes are carried past +-180 rather than wrapped, so that every step
     * is a small one and the run can be cut at the edge by split(). Nothing
     * outside this class should take these as coordinates.
     *
     * @param int $segments how many straight pieces to draw the arc in
     * @return list<array{lat: float, lon: float}>
     */
    private static function path(
        float $fromLat,
        float $fromLon,
        float $toLat,
        float $toLon,
        int $segments = 16,
    ): array {
        $segments = max(1, $segments);

        $lat1 = deg2rad($fromLat);
        $lon1 = deg2rad($fromLon);
        $lat2 = deg2rad($toLat);
        $lon2 = deg2rad($toLon);

        $d = self::angle($lat1, $lon1, $lat2, $lon2);

        if ($d < deg2rad(self::MIN_ARC_DEGREES)) {
            return self::unwrap([
                ['lat' => $fromLat, 'lon' => $fromLon],
                ['lat' => $toLat, 'lon' => $toLon],
            ]);
        }

        $points = [];

        for ($i = 0; $i <= $segments; $i++) {
            $f = $i / $segments;

            // Spherical interpolation: the two ends as vectors on the unit
            // sphere, weighted so the result stays on the sphere rather than
            // cutting through it, which is what averaging the coordinates
            // would do.
            $a = sin((1 - $f) * $d) / sin($d);
            $b = sin($f * $d) / sin($d);

            $x = $a * cos($lat1) * cos($lon1) + $b * cos($lat2) * cos($lon2);
            $y = $a * cos($lat1) * sin($lon1) + $b * cos($lat2) * sin($lon2);
            $z = $a * sin($lat1) + $b * sin($lat2);

            $points[] = [
                'lat' => self::round(rad2deg(atan2($z, sqrt($x * $x + $y * $y)))),
                'lon' => self::round(rad2deg(atan2($y, $x))),
            ];
        }

        return self::unwrap($points);
    }

    /**
     * The angle between two points on the sphere, in radians.
     *
     * The haversine form rather than the plain spherical law of cosines, which
     * loses its precision on short distances -- and short is most of these.
     */
    private static function angle(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * asin(min(1.0, sqrt($h)));
    }

    /**
     * Longitudes carried past +-180 rather than wrapped back.
     *
     * The honest coordinates jump from +179 to -179 across the antimeridian,
     * and a jump of 358 degrees is indistinguishable from going the other way
     * round the world. Continuing to +181 instead keeps every step small, which
     * is what lets split() below tell a crossing from a long westward leg.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @return list<array{lat: float, lon: float}>
     */
    private static function unwrap(array $points): array
    {
        foreach ($points as $i => $point) {
            if ($i === 0) {
                continue;
            }

            $previous = $points[$i - 1]['lon'];
            $shift = round(($previous - $point['lon']) / 360);

            $points[$i]['lon'] = $point['lon'] + $shift * 360;
        }

        return $points;
    }

    /**
     * One run of unwrapped points, cut into runs that each stay in range.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @return list<list<array{lat: float, lon: float}>>
     */
    private static function split(array $points): array
    {
        $runs = [];
        $run = [];
        $previous = null;

        foreach ($points as $point) {
            if ($previous !== null) {
                $edge = self::edgeBetween($previous['lon'], $point['lon']);

                if ($edge !== null) {
                    $span = $point['lon'] - $previous['lon'];
                    $at = ($edge - $previous['lon']) / $span;
                    $lat = $previous['lat'] + $at * ($point['lat'] - $previous['lat']);

                    // Out at the edge it reached, in at the opposite one.
                    $out = $span > 0 ? 180.0 : -180.0;

                    $run[] = ['lat' => $lat, 'lon' => $out];
                    $runs[] = $run;
                    $run = [['lat' => $lat, 'lon' => -$out]];
                }
            }

            $run[] = ['lat' => $point['lat'], 'lon' => self::wrap($point['lon'])];
            $previous = $point;
        }

        $runs[] = $run;

        return $runs;
    }

    /**
     * Where between two unwrapped longitudes the map's edge falls, or null when
     * it does not fall between them.
     *
     * The edge is every odd multiple of 180 -- +-180, +-540 -- because a run
     * unwrapped far enough can pass more than one. A step is at most a fraction
     * of the arc, so at most one edge lies inside it.
     */
    private static function edgeBetween(float $from, float $to): ?float
    {
        $low = min($from, $to);
        $high = max($from, $to);

        // The first odd multiple of 180 above the lower end.
        $k = (int) floor(($low - 180) / 360) + 1;
        $edge = 180 + 360 * $k;

        return $edge > $low && $edge < $high ? (float) $edge : null;
    }

    /** A longitude brought back into the -180..180 the map understands. */
    private static function wrap(float $lon): float
    {
        return self::round(fmod(fmod($lon + 180, 360) + 360, 360) - 180);
    }

    /**
     * To the precision a map can draw.
     *
     * These coordinates end up in a URL, and a float printed whole is 17
     * significant figures of it -- seventeen points of that is a query string
     * for nothing. Four decimals is about eleven metres, on an image where one
     * pixel of the widest route is several kilometres.
     */
    private static function round(float $degrees): float
    {
        return round($degrees, 4);
    }
}
